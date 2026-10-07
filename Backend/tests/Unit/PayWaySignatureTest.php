<?php

namespace Tests\Unit;

use App\Services\PayWay\PayWaySignature;
use PHPUnit\Framework\TestCase;

class PayWaySignatureTest extends TestCase
{
    private const API_KEY = 'test-api-key-not-a-secret';

    private function signature(): PayWaySignature
    {
        return new PayWaySignature(self::API_KEY);
    }

    /**
     * Regression vector computed independently of the class under test,
     * from the concatenation order documented by ABA PayWay:
     *
     *   req_time . merchant_id . tran_id . amount . items . shipping .
     *   firstname . lastname . email . phone . type . payment_option .
     *   return_url . cancel_url . continue_success_url . return_deeplink .
     *   currency . custom_fields . return_params . payout . lifetime .
     *   additional_params . google_pay_token . skip_success_page
     *
     * hash = base64(hmac_sha512(b4hash, api_key)).
     */
    public function test_purchase_hash_matches_known_vector(): void
    {
        $payload = $this->purchasePayload();

        $this->assertSame(
            'r7XVytogAXo8vvr0NSPkR7iPcJsueq8hGf8B3Duqr8YXXTQoR5m95YdcpEkSPyocGV14sZR+AN5sKsIUbvL68Q==',
            $this->signature()->purchaseHash($payload)
        );
    }

    public function test_different_input_produces_different_signature(): void
    {
        $payload = $this->purchasePayload();

        $changed = $this->purchasePayload();
        $changed['amount'] = '2001';

        $this->assertNotSame(
            $this->signature()->purchaseHash($payload),
            $this->signature()->purchaseHash($changed)
        );
    }

    public function test_purchase_hash_is_base64_hmac_sha512(): void
    {
        $payload = $this->purchasePayload();

        $hash = $this->signature()->purchaseHash($payload);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+==?$/', $hash);
        $this->assertSame(
            base64_encode(hash_hmac('sha512', $this->buildB4hash($payload), self::API_KEY, true)),
            $hash
        );
    }

    public function test_query_hash_is_hmac_over_req_time_merchant_and_tran_id(): void
    {
        $expected = base64_encode(hash_hmac(
            'sha512',
            '20260921120000ec000002PW2026092100001',
            self::API_KEY,
            true
        ));

        $this->assertSame(
            $expected,
            $this->signature()->queryHash('20260921120000', 'ec000002', 'PW2026092100001')
        );
    }

    public function test_valid_callback_signature_is_verified(): void
    {
        $payload = [
            'tran_id' => 'PW2026092100001',
            'apv' => '544415',
            'status' => '0',
            'original_amount' => 2000,
            'original_currency' => 'KHR',
            'payment_amount' => 2000,
            'payment_currency' => 'KHR',
            'total_amount' => 2000,
            'discount_amount' => 0,
            'transaction_date' => '2026-09-21 12:00:00',
            'first_name' => '',
            'payment_type' => 'ABA Pay',
        ];

        $this->assertTrue($this->signature()->verifyCallback($payload, $this->signCallback($payload)));
    }

    public function test_invalid_callback_signature_is_rejected(): void
    {
        $payload = ['tran_id' => 'PW001', 'status' => '0'];

        $this->assertFalse($this->signature()->verifyCallback($payload, 'bm90LXRoZS1yaWdodC1zaWduYXR1cmU='));
    }

    public function test_missing_callback_signature_is_rejected(): void
    {
        $payload = ['tran_id' => 'PW001', 'status' => '0'];

        $this->assertFalse($this->signature()->verifyCallback($payload, null));
        $this->assertFalse($this->signature()->verifyCallback($payload, ''));
    }

    public function test_tampered_callback_data_is_rejected(): void
    {
        $payload = ['tran_id' => 'PW001', 'status' => '0'];
        $signature = $this->signCallback($payload);

        $tampered = $payload;
        $tampered['amount'] = 999999;

        $this->assertFalse($this->signature()->verifyCallback($tampered, $signature));
    }

    private function purchasePayload(): array
    {
        return [
            'req_time' => '20260921120000',
            'merchant_id' => 'ec000002',
            'tran_id' => 'PW2026092100001',
            'amount' => '2000',
            'items' => base64_encode(json_encode([['name' => 'Payment', 'quantity' => 1, 'price' => '2000']])),
            'shipping' => 0,
            'firstname' => '',
            'lastname' => '',
            'email' => '',
            'phone' => '',
            'type' => 'purchase',
            'payment_option' => '',
            'return_url' => base64_encode('https://checkout.example.com/api/payments/callback'),
            'cancel_url' => 'https://checkout.example.com/api/payments/cancel',
            'continue_success_url' => 'https://app.example.com/payment/success?tran_id=PW2026092100001',
            'return_deeplink' => '',
            'currency' => 'KHR',
            'custom_fields' => base64_encode(json_encode(['account_number' => '123456789'])),
            'return_params' => '',
            'payout' => '',
            'lifetime' => 30,
            'additional_params' => '',
            'google_pay_token' => '',
            'skip_success_page' => 1,
        ];
    }

    private function buildB4hash(array $payload): string
    {
        return $payload['req_time']
            .$payload['merchant_id']
            .$payload['tran_id']
            .$payload['amount']
            .$payload['items']
            .$payload['shipping']
            .$payload['firstname']
            .$payload['lastname']
            .$payload['email']
            .$payload['phone']
            .$payload['type']
            .$payload['payment_option']
            .$payload['return_url']
            .$payload['cancel_url']
            .$payload['continue_success_url']
            .$payload['return_deeplink']
            .$payload['currency']
            .$payload['custom_fields']
            .$payload['return_params']
            .$payload['payout']
            .$payload['lifetime']
            .$payload['additional_params']
            .$payload['google_pay_token']
            .$payload['skip_success_page'];
    }

    private function signCallback(array $payload): string
    {
        ksort($payload, SORT_STRING);

        $concatenated = '';
        foreach ($payload as $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $concatenated .= (string) $value;
        }

        return base64_encode(hash_hmac('sha512', $concatenated, self::API_KEY, true));
    }
}
