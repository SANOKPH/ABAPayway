<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PayWay Credentials
    |--------------------------------------------------------------------------
    |
    | These values are provided by ABA Bank after you register for a PayWay
    | sandbox (or production) merchant account. They must only ever live in
    | the server environment (.env) and must never be exposed to the browser.
    |
    */

    'merchant_id' => env('PAYWAY_MERCHANT_ID'),

    /*
    | PayWay ships the HMAC secret under two different names depending on
    | the document you read: the merchant-portal signup email calls it
    | "public_key" (a 40-character hex string), while the API reference
    | calls it the "API Key". They are the same value and both are used as
    | the HMAC-SHA512 key for the `hash` field.
    |
    | We therefore fall back to PAYWAY_PUBLIC_KEY so a sandbox merchant can
    | integrate without waiting for a separately named credential.
    */

    'api_key' => env('PAYWAY_API_KEY') ?: env('PAYWAY_PUBLIC_KEY'),

    'public_key' => env('PAYWAY_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | PayWay Base URL
    |--------------------------------------------------------------------------
    |
    | Sandbox : https://checkout-sandbox.payway.com.kh
    | Production : https://checkout.payway.com.kh
    |
    */

    'base_url' => env('PAYWAY_BASE_URL', 'https://checkout-sandbox.payway.com.kh'),

    'api_url' => env('PAYWAY_API_URL'),

    /*
    |--------------------------------------------------------------------------
    | Check Transaction API
    |--------------------------------------------------------------------------
    |
    | The server-to-server callback is the authoritative payment result, but
    | it is only ever delivered when the callback host is reachable from the
    | public internet. A host on a private/LAN address can never receive it.
    |
    | The check-transaction API lets the backend ask PayWay for the result
    | over plain outbound HTTPS instead, so PENDING transactions can be
    | reconciled even when the callback cannot be delivered.
    |
    */

    'check_url' => env('PAYWAY_CHECK_URL'),

    'timeout' => (int) env('PAYWAY_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation Throttle
    |--------------------------------------------------------------------------
    |
    | The result pages poll the status endpoint every few seconds. This is the
    | minimum number of seconds between two gateway calls for the same
    | transaction, so a single open page cannot hammer PayWay.
    |
    */

    'reconcile_interval' => (int) env('PAYWAY_RECONCILE_INTERVAL', 8),

    /*
    |--------------------------------------------------------------------------
    | URLs
    |--------------------------------------------------------------------------
    |
    | callback_url: server-to-server endp       oint that PayWay POSTs the payment
    |               result to (the authoritative result). Sent Base64-encoded
    |               in the purchase request as `return_url`.
    |
    | cancel_url:   browser-facing endpoint the customer is redirected to when
    |               they cancel the payment on the PayWay checkout page.
    |
    | frontend_url: base URL of the React application (used for result pages).
    |
    */

    'callback_url' => env('PAYWAY_CALLBACK_URL'),

    'cancel_url' => env('PAYWAY_CANCEL_URL'),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'currency' => env('PAYWAY_CURRENCY', 'KHR'),
];
