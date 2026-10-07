<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\PayWay\PayWayClient;
use App\Services\PayWay\PayWayReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The server-to-server callback is only deliverable to a publicly reachable
 * host. These tests cover the fallback that asks PayWay directly instead.
 *
 * @see https://developer.payway.com.kh/purchase-14530820e0
 */
class ReconcileTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts with an empty reconciliation throttle.
        Cache::clear();
    }

    protected function createTransaction(string $status = Transaction::STATUS_PENDING): Transaction
    {
        return Transaction::create([
            'tran_id' => 'PWRC'.strtoupper(substr(md5((string) random_int(0, 99999999)), 0, 8)),
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => '2000.00',
            'currency' => 'KHR',
            'status' => $status,
        ]);
    }

    /**
     * A settled payment: status 0 with a non-zero payment_amount.
     */
    protected function fakePaidGateway(int $amount = 2000): void
    {
        Http::fake([
            '*check-transaction-2*' => Http::response([
                'data' => [
                    'payment_status_code' => 0,
                    'payment_status' => 'APPROVED',
                    'total_amount' => $amount,
                    'payment_amount' => $amount,
                    'payment_currency' => 'KHR',
                    'apv' => '544415',
                    'transaction_date' => '2026-09-28 09:31:11',
                ],
                'status' => ['code' => '00', 'message' => 'Success!'],
            ]),
        ]);
    }

    /**
     * The shape PayWay actually returns for a created-but-unpaid transaction,
     * captured from the live sandbox.
     */
    protected function fakeUnpaidGateway(): void
    {
        Http::fake([
            '*check-transaction-2*' => Http::response([
                'data' => [
                    'payment_status_code' => 2,
                    'total_amount' => 2000,
                    'payment_amount' => 0,
                    'payment_currency' => '',
                    'apv' => '',
                    'payment_status' => 'PENDING',
                ],
                'status' => ['code' => '00', 'message' => 'Success!'],
            ]),
        ]);
    }

    public function test_paid_transaction_is_settled(): void
    {
        $this->fakePaidGateway();
        $transaction = $this->createTransaction();

        $settled = app(PayWayReconciler::class)->reconcile($transaction);

        $this->assertTrue($settled);
        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_SUCCESS,
            'payway_apv' => '544415',
        ]);
    }

    public function test_unpaid_transaction_stays_pending(): void
    {
        $this->fakeUnpaidGateway();
        $transaction = $this->createTransaction();

        $settled = app(PayWayReconciler::class)->reconcile($transaction);

        $this->assertFalse($settled);
        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    /**
     * PayWay answers "6 / tran_id not found" for a tran_id it does not know.
     * That is a gateway lookup failure, not a declined payment, so the
     * transaction must be left alone rather than failed.
     */
    public function test_unknown_transaction_is_never_marked_failed(): void
    {
        Http::fake([
            '*check-transaction-2*' => Http::response([
                'status' => ['code' => '6', 'message' => 'tran_id not found'],
            ]),
        ]);

        $transaction = $this->createTransaction();

        $settled = app(PayWayReconciler::class)->reconcile($transaction);

        $this->assertFalse($settled);
        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    /**
     * A status code of 0 with nothing actually paid is not a settlement.
     */
    public function test_zero_payment_amount_is_not_treated_as_paid(): void
    {
        Http::fake([
            '*check-transaction-2*' => Http::response([
                'data' => ['payment_status_code' => 0, 'payment_amount' => 0],
                'status' => ['code' => '00', 'message' => 'Success!'],
            ]),
        ]);

        $transaction = $this->createTransaction();

        $this->assertFalse(app(PayWayReconciler::class)->reconcile($transaction));
        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    public function test_gateway_error_does_not_change_status(): void
    {
        Http::fake([
            '*check-transaction-2*' => Http::response('gateway down', 500),
        ]);

        $transaction = $this->createTransaction();

        $this->assertFalse(app(PayWayReconciler::class)->reconcile($transaction));
        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    public function test_settled_transactions_are_never_queried(): void
    {
        Http::fake();
        $transaction = $this->createTransaction(Transaction::STATUS_SUCCESS);

        $this->assertFalse(app(PayWayReconciler::class)->reconcile($transaction));
        Http::assertNothingSent();
    }

    public function test_repeated_polls_are_throttled_to_one_gateway_call(): void
    {
        $this->fakeUnpaidGateway();
        $reconciler = app(PayWayReconciler::class);
        $transaction = $this->createTransaction();

        $reconciler->reconcile($transaction);
        $reconciler->reconcile($transaction);
        $reconciler->reconcile($transaction);

        Http::assertSentCount(1);
    }

    /**
     * The callback stays authoritative: a transaction already settled by a
     * verified callback must not be touched by a later reconciliation.
     */
    public function test_callback_result_is_not_overwritten(): void
    {
        $this->fakePaidGateway();
        $transaction = $this->createTransaction(Transaction::STATUS_CANCELLED);

        $this->assertFalse(app(PayWayReconciler::class)->reconcile($transaction));
        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_CANCELLED,
        ]);
    }

    public function test_check_transaction_sends_the_documented_signed_payload(): void
    {
        Http::fake(['*check-transaction-2*' => Http::response([
            'data' => ['payment_status_code' => 2, 'payment_amount' => 0],
            'status' => ['code' => '00'],
        ])]);

        $transaction = $this->createTransaction();

        app(PayWayClient::class)->checkTransaction($transaction->tran_id);

        Http::assertSent(function ($request) use ($transaction) {
            parse_str($request->body(), $body);

            $this->assertSame(config('payway.merchant_id'), $body['merchant_id']);
            $this->assertSame($transaction->tran_id, $body['tran_id']);
            $this->assertMatchesRegularExpression('/^\d{14}$/', $body['req_time']);

            // hash = base64(hmac_sha512(req_time . merchant_id . tran_id))
            $this->assertSame(
                base64_encode(hash_hmac(
                    'sha512',
                    $body['req_time'].$body['merchant_id'].$body['tran_id'],
                    config('payway.api_key'),
                    true
                )),
                $body['hash']
            );

            return true;
        });
    }

    public function test_status_endpoint_settles_a_paid_pending_transaction(): void
    {
        $this->fakePaidGateway();
        $transaction = $this->createTransaction();

        $this->getJson('/api/payments/'.$transaction->tran_id)
            ->assertStatus(200)
            ->assertJson(['status' => Transaction::STATUS_SUCCESS]);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_SUCCESS,
        ]);
    }

    public function test_reconcile_pending_sweeps_every_pending_transaction(): void
    {
        $this->fakePaidGateway();

        $paid = $this->createTransaction();
        $unpaid = $this->createTransaction();
        $this->createTransaction(Transaction::STATUS_SUCCESS);

        $result = app(PayWayReconciler::class)->reconcilePending();

        $this->assertSame(2, $result['checked']);
        $this->assertSame(2, $result['settled']);
        $this->assertDatabaseHas('transactions', [
            'tran_id' => $paid->tran_id,
            'status' => Transaction::STATUS_SUCCESS,
        ]);
    }
}
