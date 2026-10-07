<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payment_creates_pending_transaction_and_returns_checkout_form(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => 2000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath(
                'checkout.action',
                'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/purchase'
            )
            ->assertJsonStructure([
                'transaction_id',
                'checkout' => [
                    'action',
                    'fields' => [
                        'req_time', 'merchant_id', 'tran_id', 'amount', 'hash',
                        'return_url', 'cancel_url', 'continue_success_url',
                        'currency', 'custom_fields', 'lifetime', 'skip_success_page',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('transactions', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'currency' => 'KHR',
            'status' => Transaction::STATUS_PENDING,
        ]);

        $transaction = Transaction::where('account_number', '123456789')->first();
        $this->assertSame('2000.00', number_format((float) $transaction->amount, 2, '.', ''));

        $tranId = $response->json('transaction_id');
        $this->assertStringStartsWith('PW', $tranId);

        // hash is generated server-side and never leaks the API key
        $this->assertNotEmpty($response->json('checkout.fields.hash'));
        $response->assertDontSee('test-api-key-not-a-secret');
    }

    public function test_transaction_is_saved_as_pending_before_payway_form_is_returned(): void
    {
        $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => 1000,
        ])->assertStatus(201);

        $this->assertSame(
            1,
            Transaction::where('status', Transaction::STATUS_PENDING)->count()
        );
    }

    public function test_invalid_name_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => '',
            'account_number' => '123456789',
            'amount' => 1000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_overlong_name_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => str_repeat('a', 101),
            'account_number' => '123456789',
            'amount' => 1000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_invalid_account_number_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '12ab',
            'amount' => 1000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('account_number');
    }

    public function test_missing_amount_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_non_numeric_amount_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => 'abc',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_decimal_khr_amount_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => 1000.5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_amount_below_minimum_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => 0,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_amount_above_maximum_is_rejected(): void
    {
        $response = $this->postJson('/api/payments', [
            'name' => 'Sanok Payang',
            'account_number' => '123456789',
            'amount' => 50000001,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }
}
