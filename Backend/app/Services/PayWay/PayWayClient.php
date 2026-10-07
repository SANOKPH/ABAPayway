<?php

namespace App\Services\PayWay;

use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Builds and (optionally) sends ABA PayWay API requests.
 *
 * The purchase request is delivered to PayWay through the documented
 * HTML form POST. All fields — including the request `hash` — are generated
 * on the server. Only the final form payload is handed to the browser;
 * the API key never leaves the server.
 *
 * @see https://developer.payway.com.kh/purchase-14530820e0
 */
class PayWayClient
{
    public function __construct(private readonly PayWaySignature $signature) {}

    public function purchaseEndpoint(): string
    {
        if ($apiUrl = config('payway.api_url')) {
            return (string) $apiUrl;
        }

        return rtrim((string) config('payway.base_url'), '/')
            .'/api/payment-gateway/v1/payments/purchase';
    }

    public function checkEndpoint(): string
    {
        if ($checkUrl = config('payway.check_url')) {
            return (string) $checkUrl;
        }

        return rtrim((string) config('payway.base_url'), '/')
            .'/api/payment-gateway/v1/payments/check-transaction-2';
    }

    /**
     * Ask PayWay for the current state of a transaction.
     *
     * This is the outbound counterpart of the server-to-server callback. The
     * callback is the authoritative result and is still processed when it
     * arrives, but it is only deliverable to a publicly reachable host — so
     * this call is what lets a PENDING transaction be confirmed when the
     * callback cannot land.
     *
     * The returned array is the decoded PayWay response:
     *   status.code            result of the *query* ("00" = query succeeded)
     *   data.payment_status_code  result of the *transaction* (0 = paid)
     *
     * @throws \RuntimeException when the gateway cannot be reached or answers
     *                           with an unrecognised body
     */
    public function checkTransaction(string $tranId): array
    {
        $reqTime = gmdate('YmdHis');
        $merchantId = (string) config('payway.merchant_id');

        $response = Http::asForm()
            ->timeout((int) config('payway.timeout', 15))
            ->post($this->checkEndpoint(), [
                'req_time' => $reqTime,
                'merchant_id' => $merchantId,
                'tran_id' => $tranId,
                'hash' => $this->signature->queryHash($reqTime, $merchantId, $tranId),
            ]);

        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ! isset($body['status']['code'])) {
            throw new \RuntimeException(
                'PayWay check-transaction-2 failed with HTTP '.$response->status().'.'
            );
        }

        return $body;
    }

    /**
     * Build the full Purchase API payload including the server-generated
     * `hash`. The returned array is used to populate a hidden HTML form that
     * is auto-submitted to $this->purchaseEndpoint().
     *
     * @param  array{name: string, account_number: string, amount: string|int}  $validated
     */    public function buildPurchaseForm(array $validated, Transaction $transaction): array
    {
        $amount = (string) (int) $validated['amount'];
        $merchantId = (string) config('payway.merchant_id');
        $reqTime = gmdate('YmdHis');
        $tranId = $transaction->tran_id;

        $fields = [
            'req_time' => $reqTime,
            'merchant_id' => $merchantId,
            'tran_id' => $tranId,
            'amount' => $amount,
            // Base64-encoded JSON, as required by PayWay.
            'items' => base64_encode(json_encode([
                ['name' => 'Payment', 'quantity' => 1, 'price' => $amount],
            ])),
            'shipping' => 0,
            'firstname' => $validated['name'],
            'lastname' => '',
            'email' => '',
            'phone' => '',
            'type' => 'purchase',
            'payment_option' => '',
            // `return_url` must be Base64-encoded; it is our server-to-server
            // callback endpoint (the authoritative payment result).
            'return_url' => base64_encode((string) config('payway.callback_url')),
            'cancel_url' => (string) config('payway.cancel_url'),
            'continue_success_url' => rtrim((string) config('payway.frontend_url'), '/')
                .'/payment/success?tran_id='.$tranId,
            'return_deeplink' => '',
            'currency' => (string) config('payway.currency', Transaction::CURRENCY_KHR),
            // Documented field for attaching extra information to the
            // transaction (appears in PayWay transaction detail/list/export).
            // Used to associate the customer's account number safely.
            'custom_fields' => base64_encode(json_encode([
                'account_number' => $validated['account_number'],
            ])),
            'return_params' => '',
            'payout' => '',
            'lifetime' => 30,
            'additional_params' => '',
            'google_pay_token' => '',
            'skip_success_page' => 1,
            // The following two fields are NOT part of the hash.
            //
            // `payment_gate` must be 0 to force the Checkout service. Without
            // it, any merchant profile that also has the QR Payment API
            // enabled receives a JSON body (qrString/qrImage/checkout_qr_url)
            // instead of the hosted checkout page, and the browser is left
            // staring at raw JSON.
            'payment_gate' => 0,
            // `hosted_view` = full-page redirect. Use `popup` for a bottom
            // sheet on mobile / modal on desktop.
            'view_type' => 'hosted_view',
        ];

        $fields['hash'] = $this->signature->purchaseHash($fields);

        Log::info('PAYWAY_REQUEST', [
            'tran_id' => $tranId,
            'amount' => $amount,
            'currency' => $fields['currency'],
            'endpoint' => $this->purchaseEndpoint(),
        ]);

        return $fields;
    }
}
