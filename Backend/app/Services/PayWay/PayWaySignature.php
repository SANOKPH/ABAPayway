<?php

namespace App\Services\PayWay;

/**
 * Implements the exact signature algorithm documented by ABA PayWay.
 *
 * @see https://developer.payway.com.kh/purchase-14530820e0
 *
 * Purchase hash (Base64 of HMAC-SHA512 of concatenated values, in this
 * exact order):
 *
 *     req_time . merchant_id . tran_id . amount . items . shipping .
 *     firstname . lastname . email . phone . type . payment_option .
 *     return_url . cancel_url . continue_success_url . return_deeplink .
 *     currency . custom_fields . return_params . payout . lifetime .
 *     additional_params . google_pay_token . skip_success_page
 *
 * Callback verification (Base64 of HMAC-SHA512 over all JSON values sorted
 * by key ascending) compared with the X-PAYWAY-HMAC-SHA512 header.
 * @see https://developer.payway.com.kh/ecommerce-checkout-3158159f0
 */
class PayWaySignature
{
    public function __construct(private readonly string $apiKey) {}

    /**
     * Build the `hash` field for the Purchase API.
     *
     * Every key in $payload must be present, even when empty/unused,
     * because the concatenation order is fixed by PayWay.
     */
    public function purchaseHash(array $payload): string
    {
        $b4hash = $payload['req_time']
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

        return base64_encode(hash_hmac('sha512', $b4hash, $this->apiKey, true));
    }

    /**
     * Build the `hash` field for the transaction query APIs
     * (check-transaction / transaction-detail): HMAC-SHA512 over
     * `req_time . merchant_id . tran_id`.
     */
    public function queryHash(string $reqTime, string $merchantId, string $tranId): string
    {
        return base64_encode(hash_hmac('sha512', $reqTime.$merchantId.$tranId, $this->apiKey, true));
    }

    /**
     * Verify the authenticity of a PayWay server-to-server callback.
     *
     * Per PayWay docs: sort the JSON body by key (ascending), concatenate all
     * values (arrays are JSON-encoded first), then compute
     * Base64(HMAC-SHA512(concat, api_key)) and compare — using a constant
     * time comparison — with the signature received in the
     * X-PAYWAY-HMAC-SHA512 request header.
     */
    
    public function verifyCallback(array $payload, ?string $receivedSignature): bool
    {
        if ($receivedSignature === null || $receivedSignature === '') {
            return false;
        }

        ksort($payload, SORT_STRING);

        $concatenated = '';
        foreach ($payload as $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = '';
            }

            $concatenated .= (string) $value;
        }

        $expected = base64_encode(hash_hmac('sha512', $concatenated, $this->apiKey, true));

        return hash_equals($expected, $receivedSignature);
    }
}
