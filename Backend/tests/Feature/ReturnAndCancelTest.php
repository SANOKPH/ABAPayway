<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnAndCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function createTransaction(string $status): Transaction
    {
        return Transaction::create([
            'tran_id' => 'PWRT000'.strtoupper(substr(md5((string) random_int(0, 999999)), 0, 6)),
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => '2000.00',
            'currency' => 'KHR',
            'status' => $status,
        ]);
    }

    public function test_return_redirects_successful_transaction_to_success_page(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_SUCCESS);

        $response = $this->getJson('/api/payments/return?tran_id='.$transaction->tran_id)
            ->assertStatus(302);

        $this->assertStringContainsString(
            '/payment/success?tran_id='.$transaction->tran_id,
            $response->headers->get('Location')
        );
    }

    public function test_return_redirects_failed_transaction_to_failed_page(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_FAILED);

        $response = $this->getJson('/api/payments/return?tran_id='.$transaction->tran_id)
            ->assertStatus(302);

        $this->assertStringContainsString('/payment/failed', $response->headers->get('Location'));
    }

    public function test_return_redirects_pending_transaction_to_pending_page(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_PENDING);

        $response = $this->getJson('/api/payments/return?tran_id='.$transaction->tran_id)
            ->assertStatus(302);

        $this->assertStringContainsString('/payment/pending', $response->headers->get('Location'));
    }

    public function test_return_does_not_change_transaction_status(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_PENDING);

        $this->getJson('/api/payments/return?tran_id='.$transaction->tran_id);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_PENDING,
        ]);
    }

    public function test_return_for_unknown_transaction_redirects_to_failed_page(): void
    {
        $response = $this->getJson('/api/payments/return?tran_id=PWNOPE9999')
            ->assertStatus(302);

        $this->assertStringContainsString('/payment/failed', $response->headers->get('Location'));
    }

    public function test_cancel_marks_pending_transaction_cancelled_and_redirects(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_PENDING);

        $response = $this->getJson('/api/payments/cancel?tran_id='.$transaction->tran_id)
            ->assertStatus(302);

        $this->assertStringContainsString('/payment/cancelled', $response->headers->get('Location'));

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_CANCELLED,
        ]);
    }

    public function test_cancel_does_not_overwrite_a_successful_transaction(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_SUCCESS);

        $this->getJson('/api/payments/cancel?tran_id='.$transaction->tran_id);

        $this->assertDatabaseHas('transactions', [
            'tran_id' => $transaction->tran_id,
            'status' => Transaction::STATUS_SUCCESS,
        ]);
    }

    public function test_show_returns_minimal_safe_status(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_SUCCESS);

        $this->getJson('/api/payments/'.$transaction->tran_id)
            ->assertStatus(200)
            ->assertExactJson([
                'tran_id' => $transaction->tran_id,
                'amount' => '2000.00',
                'currency' => 'KHR',
                'status' => Transaction::STATUS_SUCCESS,
            ]);
    }

    public function test_show_hides_sensitive_fields(): void
    {
        $transaction = $this->createTransaction(Transaction::STATUS_SUCCESS);

        $this->getJson('/api/payments/'.$transaction->tran_id)
            ->assertJsonMissing(['account_number'])
            ->assertJsonMissing(['name']);
    }

    public function test_show_for_unknown_transaction_returns_404(): void
    {
        $this->getJson('/api/payments/PWUNKNOWN999')
            ->assertStatus(404);
    }
}
