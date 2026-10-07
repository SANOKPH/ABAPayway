<?php
namespace App\Http\Controllers;
use App\Http\Requests\CreatePaymentRequest;
use App\Models\Transaction;
use App\Services\PayWay\PayWayCallbackService;
use App\Services\PayWay\PayWayClient;
use App\Services\PayWay\PayWayReconciler;
use App\Services\TranIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        private readonly TranIdService $tranIdService,
        private readonly PayWayClient $payWayClient,
        private readonly PayWayCallbackService $callbackService,
        private readonly PayWayReconciler $reconciler,
    ) {}

    public function store(CreatePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $transaction = Transaction::create([
                'tran_id' => $this->tranIdService->generate(),
                'name' => $validated['name'],
                'account_number' => $validated['account_number'],
                'amount' => $validated['amount'],
                'currency' => config('payway.currency', 'KHR'),
                'status' => Transaction::STATUS_PENDING,
            ]);
        } catch (Throwable $e) {
            Log::error('PAYMENT_CREATE_DATABASE_ERROR', [
                'error' => $e->getMessage(),
            ]);
            return $this->serverError();
        }

        Log::info('PAYMENT_CREATED', [
            'tran_id' => $transaction->tran_id,
            'status' => $transaction->status,
        ]);

        $checkout = $this->payWayClient->buildPurchaseForm($validated, $transaction);

        return response()->json([
            'transaction_id' => $transaction->tran_id,
            'checkout' => [
                'action' => $this->payWayClient->purchaseEndpoint(),
                'fields' => $checkout,
            ],
        ], Response::HTTP_CREATED);
    }

    public function return(Request $request): RedirectResponse
    {
        $tranId = trim((string) $request->query('tran_id', ''));

        if ($tranId === '') {
            return $this->redirectToFrontend('/payment/failed');
        }

        $transaction = Transaction::where('tran_id', $tranId)->first();

        if ($transaction === null) {
            Log::warning('RETURN_UNKNOWN_TRANSACTION', ['tran_id' => $tranId]);

            return $this->redirectToFrontend('/payment/failed');
        }

        $page = match ($transaction->status) {
            Transaction::STATUS_SUCCESS => '/payment/success',
            Transaction::STATUS_FAILED => '/payment/failed',
            Transaction::STATUS_CANCELLED => '/payment/cancelled',
            default => '/payment/pending',
        };
        return $this->redirectToFrontend($page, ['tran_id' => $tranId]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $tranId = trim((string) $request->query('tran_id', ''));

        if ($tranId !== '') {
            DB::transaction(function () use ($tranId) {
                $transaction = Transaction::where('tran_id', $tranId)
                    ->lockForUpdate()
                    ->first();
                if ($transaction !== null && $transaction->isPending()) {
                    $transaction->update(['status' => Transaction::STATUS_CANCELLED]);
                    Log::info('PAYMENT_CANCELLED', ['tran_id' => $tranId]);
                }
            });
        }

        return $this->redirectToFrontend('/payment/cancelled', $tranId !== '' ? ['tran_id' => $tranId] : []);
    }

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->json()?->all() ?? [];

        if (! is_array($payload) || $payload === []) {
            return response()->json(['error' => 'Invalid callback payload.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tranId = isset($payload['tran_id']) ? (string) $payload['tran_id'] : '';

        if ($tranId === '' || ! isset($payload['status'])) {
            return response()->json(['error' => 'Missing required callback fields.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $receivedSignature = $request->header('X-PAYWAY-HMAC-SHA512');

        if (! $this->callbackService->verify($payload, $receivedSignature)) {
            Log::warning('PAYWAY_CALLBACK_INVALID_SIGNATURE', ['tran_id' => $tranId]);

            return response()->json(['error' => 'Invalid callback signature.'], Response::HTTP_UNAUTHORIZED);
        }

        Log::info('PAYWAY_CALLBACK_VERIFIED', ['tran_id' => $tranId]);

        $result = $this->callbackService->process($tranId, $payload);

        return response()->json([
            'status' => 'success',
            'transaction_id' => $tranId,
            'processed' => $result['processed'] ?? false,
            'found' => $result['found'] ?? false,
        ]);
    }

    public function show(string $tranId): JsonResponse
    {
        $transaction = Transaction::where('tran_id', $tranId)->first();

        if ($transaction === null) {
            return response()->json(['error' => 'Transaction not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($transaction->isPending() && $this->reconciler->reconcile($transaction)) {
            $transaction->refresh();
        }

        return response()->json([
            'tran_id' => $transaction->tran_id,
            'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'currency' => $transaction->currency,
            'status' => $transaction->status,
        ]);
    }

    private function redirectToFrontend(string $path, array $params = []): RedirectResponse
    {
        $url = rtrim((string) config('payway.frontend_url'), '/').$path;

        if ($params !== []) {
            $url .= '?'.http_build_query($params);
        }

        return redirect()->away($url);
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'error' => 'Payment could not be completed. Please try again later.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
