<?php

namespace App\Services\PayWay;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Confirms PENDING transactions by querying PayWay directly.
 *
 * The server-to-server callback remains the authoritative result and is still
 * processed in full when it arrives. This service is the fallback for when it
 * cannot: a callback host on a private/LAN address is unreachable from
 * PayWay's servers, so the transaction would otherwise stay PENDING forever
 * even though the customer already paid.
 *
 * Two different codes are involved and must not be confused:
 *
 *   status.code            the result of the *query*      ("00" = query OK,
 *                          "5" = PayWay does not know this tran_id)
 *   data.payment_status_code  the result of the *transaction* (0 = paid,
 *                          2 = still pending)
 *
 * Only a transaction PayWay reports as paid is ever marked SUCCESS. Anything
 * else — pending, unknown, unreachable — is left PENDING, because wrongly
 * failing a paid transaction is worse than leaving it unresolved.
 */
class PayWayReconciler
{
    public function __construct(private readonly PayWayClient $client) {}

    /**
     * Reconcile one transaction.
     *
     * @return bool true when this call moved the transaction to a final status
     */
    public function reconcile(Transaction $transaction): bool
    {
        if (! $transaction->isPending()) {
            return false;
        }

        if (! $this->mayCallGateway($transaction->tran_id)) {
            return false;
        }

        try {
            $body = $this->client->checkTransaction($transaction->tran_id);
        } catch (RuntimeException $e) {
            Log::warning('PAYWAY_RECONCILE_REQUEST_FAILED', [
                'tran_id' => $transaction->tran_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('PAYWAY_RECONCILE_ERROR', [
                'tran_id' => $transaction->tran_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $code = (string) ($body['status']['code'] ?? '');
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        Log::info('PAYWAY_RECONCILE_RESULT', [
            'tran_id' => $transaction->tran_id,
            'gateway_code' => $code,
            'gateway_message' => $body['status']['message'] ?? null,
            'payment_status_code' => $data['payment_status_code'] ?? null,
            'payment_status' => $data['payment_status'] ?? null,
            'payment_amount' => $data['payment_amount'] ?? null,
        ]);

        // The query itself failed (e.g. "5: Transaction not found"). PayWay may
        // simply not know this tran_id yet, so the local status is left alone.
        if ($code !== '00') {
            return false;
        }

        if (! $this->isPaid($data)) {
            return false;
        }

        return $this->markSuccess($transaction, $data);
    }

    /**
     * Reconcile every transaction that is still PENDING.
     *
     * Used to recover transactions whose result page was never opened again
     * (for example when the customer closed the browser after paying).
     *
     * @return array{checked: int, settled: int}
     */
    public function reconcilePending(int $limit = 200): array
    {
        $checked = 0;
        $settled = 0;

        Transaction::where('status', Transaction::STATUS_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Transaction $transaction) use (&$checked, &$settled) {
                $checked++;

                // Each transaction gets its own throttle slot, so a sweep is not
                // silently skipped by the per-transaction rate limit.
                Cache::forget($this->throttleKey($transaction->tran_id));

                if ($this->reconcile($transaction)) {
                    $settled++;
                }
            });

        return ['checked' => $checked, 'settled' => $settled];
    }

    /**
     * Rate limit: at most one gateway call per transaction per interval,
     * however often the browser polls the status endpoint.
     */
    private function mayCallGateway(string $tranId): bool
    {
        $interval = (int) config('payway.reconcile_interval', 8);

        if ($interval <= 0) {
            return true;
        }

        return Cache::add($this->throttleKey($tranId), 1, $interval);
    }

    private function throttleKey(string $tranId): string
    {
        return 'payway:reconcile:'.$tranId;
    }

    /**
     * PayWay reports a settled payment as payment_status_code 0 with a
     * non-zero payment_amount. An unpaid transaction comes back as code 2 with
     * payment_amount 0, so both conditions are required before crediting it.
     *
     * @param  array<string, mixed>  $data
     */
    private function isPaid(array $data): bool
    {
        $statusCode = $data['payment_status_code'] ?? null;
        $paid = isset($data['payment_amount']) ? (float) $data['payment_amount'] : 0.0;

        return (int) $statusCode === 0 && $paid > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function markSuccess(Transaction $transaction, array $data): bool
    {
        return DB::transaction(function () use ($transaction, $data) {
            /** @var Transaction|null $fresh */
            $fresh = Transaction::where('tran_id', $transaction->tran_id)
                ->lockForUpdate()
                ->first();

            // A callback may have settled it while the query was in flight.
            if ($fresh === null || ! $fresh->isPending()) {
                return false;
            }

            $apv = $data['apv'] ?? null;

            $fresh->update([
                'status' => Transaction::STATUS_SUCCESS,
                'payway_response_code' => (string) ($data['payment_status_code'] ?? '0'),
                'payway_apv' => $apv === null || $apv === '' ? null : (string) $apv,
            ]);

            Log::info('PAYMENT_RECONCILED_SUCCESS', [
                'tran_id' => $fresh->tran_id,
                'amount' => $fresh->amount,
                'currency' => $fresh->currency,
                'payway_apv' => $fresh->payway_apv,
            ]);

            return true;
        });
    }
}
