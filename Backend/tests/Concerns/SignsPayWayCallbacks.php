<?php

namespace Tests\Concerns;

trait SignsPayWayCallbacks
{
    /**
     * Build a realistic PayWay callback payload for $tranId.
     */
    private function callbackPayload(string $tranId, string $status = '0'): array
    {
        return [
            'tran_id' => $tranId,
            'apv' => '544415',
            'status' => $status,
            'return_params' => '',
            'original_amount' => 2000,
            'original_currency' => 'KHR',
            'payment_amount' => 2000,
            'payment_currency' => 'KHR',
            'total_amount' => 2000,
            'discount_amount' => 0,
            'transaction_date' => '2026-09-21 12:00:00',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'bank_ref' => '100FT40074059022',
            'payment_type' => 'ABA Pay',
            'payer_account' => '003471222',
            'bank_name' => '',
            'card_source' => '',
        ];
    }

    /**
     * Compute the documented callback signature:
     * sort fields by key ascending, concatenate all values, then
     * base64(HMAC-SHA512(concat, api_key)).
     */
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

        return base64_encode(hash_hmac('sha512', $concatenated, (string) config('payway.api_key'), true));
    }
}
