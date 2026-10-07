<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Only the React frontend is permitted to call the API. The origin is
    | read from the environment so it can be locked down per environment.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['POST', 'GET', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'X-PAYWAY-HMAC-SHA512'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
