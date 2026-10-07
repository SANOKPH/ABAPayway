<?php
namespace App\Services\PayWay;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies and safely processes PayWay server-to-server callbacks.
 *
 * The callback is the AUTHORITATIVE payment result. A transaction is never
 * marked successful from browser-side information (return URL) — only from a
 * verified callback.
 *
 * Idempotency: the transaction is locked for update inside a DB transaction.
 * If it has already reached a final status the callback is acknowledged but
 * not processed again (no duplicate side effects).
 */
class PayWayCallbackService
{
    public function __construct(private readonly PayWaySignature $signature) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload, ?string $receivedSignature): bool
    {
        return $this->signature->verifyCallback($payload, $receivedSignature);
    }

    /**
     * Process a verified callback for the given transaction id.
     *
     * @param  array<string, mixed>  $payload
     */
    public function process(string $tranId, array $payload): array
    {
        return DB::transaction(function () use ($tranId, $payload) {
            /** @var Transaction|null $transaction */
            $transaction = Transaction::where('tran_id', $tranId)->lockForUpdate()->first();

            if ($transaction === null) {
                Log::warning('PAYWAY_CALLBACK_UNKNOWN_TRANSACTION', [
                    'tran_id' => $tranId,
                ]);

                return ['found' => false, 'processed' => false];
            }
            Log::info('PAYWAY_CALLBACK_RECEIVED', [
                'tran_id' => $tranId,
                'status' => $transaction->status,
            ]);

            if ($transaction->isFinal()) {
                Log::info('PAYWAY_CALLBACK_DUPLICATE', [
                    'tran_id' => $tranId,
                    'status' => $transaction->status,
                    'processed' => false,
                ]);

                return ['found' => true, 'processed' => false, 'status' => $transaction->status];
            }

            $newStatus = $this->resolveStatus($payload);
            $responseCode = $this->extractResponseCode($payload);

            $transaction->update([
                'status' => $newStatus,
                'payway_response_code' => $responseCode,
                'payway_apv' => $this->extractApv($payload),
            ]);

            Log::info(
                $newStatus === Transaction::STATUS_SUCCESS ? 'PAYMENT_SUCCESS' : 'PAYMENT_FAILED',
                [
                    'tran_id' => $tranId,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'payway_response_code' => $responseCode,
                ]
            );

            return ['found' => true, 'processed' => true, 'status' => $newStatus];
        });

    }

    /**
     * Map the PayWay callback `status` field to an internal status.
     *
     * `0` is the documented success status (Value: 0). Any other status code
     * is treated as a failed/declined payment. The raw code is stored in
     * `payway_response_code` for traceability.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveStatus(array $payload): string
    {
        $code = $this->extractResponseCode($payload);

        return $code === '0' ? Transaction::STATUS_SUCCESS : Transaction::STATUS_FAILED;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractResponseCode(array $payload): string
    {
        return strval($payload['status'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractApv(array $payload): ?string
    {
        $apv = $payload['apv'] ?? null;

        return $apv === null || $apv === '' ? null : (string) $apv;
    }
}
