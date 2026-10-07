<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsPayWayCallbacks;
use Tests\TestCase;

class PaymentCallbackTest extends TestCase
{
    use RefreshDatabase;
    use SignsPayWayCallbacks;

    protected function createPending(string $tranId = 'PWTEST0001'): Transaction
    {
        return Transaction::create([
            'tran_id' => $tranId,
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => '2000.00',
            'currency' => 'KHR',
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    public function test_valid_success_callback_updates_transaction(): void
    {
        $transaction = $this->createPending();
        $payload = $this->callbackPayload($transaction->tran_id);
        $signature = $this->signCallback($payload);

        $response = $this->postJson('/api/payments/callback', $payload, [
            'X-PAYWAY-HMAC-SHA512' => $signature,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('processed', true)
            ->assertJsonPath('transaction_id', $transaction->tran_id);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_SUCCESS,
            'payway_response_code' => '0',
            'payway_apv' => '544415',
        ]);
    }

    public function test_valid_failed_callback_marks_transaction_failed(): void
    {
        $transaction = $this->createPending();
        $payload = $this->callbackPayload($transaction->tran_id, '1');
        $signature = $this->signCallback($payload);

        $this->postJson('/api/payments/callback', $payload, [
            'X-PAYWAY-HMAC-SHA512' => $signature,
        ])->assertStatus(200)->assertJsonPath('processed', true);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_FAILED,
            'payway_response_code' => '1',
        ]);
    }

    public function test_callback_with_invalid_signature_is_rejected(): void
    {
        $transaction = $this->createPending();
        $payload = $this->callbackPayload($transaction->tran_id);

        $this->postJson('/api/payments/callback', $payload, [
            'X-PAYWAY-HMAC-SHA512' => 'aW52YWxpZC1zaWduYXR1cmU=',
        ])->assertStatus(401);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    public function test_callback_without_signature_header_is_rejected(): void
    {
        $transaction = $this->createPending();
        $payload = $this->callbackPayload($transaction->tran_id);

        $this->postJson('/api/payments/callback', $payload)->assertStatus(401);
    }

    public function test_callback_with_missing_fields_is_rejected(): void
    {
        $this->postJson('/api/payments/callback', ['status' => '0'])->assertStatus(422);

        $this->postJson('/api/payments/callback', [])->assertStatus(422);
    }

    public function test_callback_for_unknown_transaction_is_acknowledged_but_not_processed(): void
    {
        $payload = $this->callbackPayload('PWNOTFOUND1234');
        $signature = $this->signCallback($payload);

        $this->postJson('/api/payments/callback', $payload, [
            'X-PAYWAY-HMAC-SHA512' => $signature,
        ])->assertStatus(200)
            ->assertJsonPath('found', false)
            ->assertJsonPath('processed', false);

        $this->assertSame(0, Transaction::count());
    }

    public function test_duplicate_callback_is_idempotent(): void
    {
        $transaction = $this->createPending();
        $payload = $this->callbackPayload($transaction->tran_id);
        $signature = $this->signCallback($payload);

        $this->postJson('/api/payments/callback', $payload, [
            'X-PAYWAY-HMAC-SHA512' => $signature,
        ])->assertStatus(200)->assertJsonPath('processed', true);

        $updatedAt = $transaction->fresh()->updated_at;

        $this->postJson('/api/payments/callback', $payload, [
            'X-PAYWAY-HMAC-SHA512' => $signature,
        ])->assertStatus(200)->assertJsonPath('processed', false);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_SUCCESS,
        ]);

        // No state change on the duplicate — repeated callbacks must produce
        // no duplicate side effects.
        $this->assertSame($updatedAt->format('Y-m-d H:i:s'), $transaction->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, Transaction::count());
    }

    public function test_callback_does_not_mark_success_based_on_browser_data(): void
    {
        // Regression guard: only a verified callback can flip the status.
        // A request signed by anyone but PayWay must be rejected.
        $transaction = $this->createPending();
        $payload = $this->callbackPayload($transaction->tran_id, '0');
        $forgedSignature = base64_encode(hash_hmac('sha512', json_encode($payload), 'attacker-key', true));

        $this->postJson('/api/payments/callback', $payload, [
            'X-PAYWAY-HMAC-SHA512' => $forgedSignature,
        ])->assertStatus(401);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_PENDING,
        ]);
    }
}
