<?php

// Published by `php artisan vendor:publish --tag=kapitalbank-config`.
// Secrets and merchant data come from .env only.
return [
    'base_url' => env('KAPITALBANK_BASE_URL', 'https://checkout.kapitalbank.az/api'),
    // preprod: https://precheckout.kapitalbank.az/api
    'client_id' => env('KAPITALBANK_CLIENT_ID'),
    'client_secret' => env('KAPITALBANK_CLIENT_SECRET'),
    'merchant_id' => env('KAPITALBANK_MERCHANT_ID'),
    'terminal_id' => env('KAPITALBANK_TERMINAL_ID'),
    'webhook_secret' => env('KAPITALBANK_WEBHOOK_SECRET'),
    'timeout' => (float) env('KAPITALBANK_TIMEOUT', 30),
    'connect_timeout' => (float) env('KAPITALBANK_CONNECT_TIMEOUT', 10),
    // PSR-16 token cache: a Laravel cache store name, null = default store, false = no cache.
    'token_cache_store' => env('KAPITALBANK_TOKEN_CACHE_STORE'),

    // Refund request body. Default = the shape the live API accepts: {"id": "<uuid>", "amount": 2.5}
    'refund' => [
        'id_field' => env('KAPITALBANK_REFUND_ID_FIELD', 'id'),               // id | originalId | paymentId
        'amount_format' => env('KAPITALBANK_REFUND_AMOUNT_FORMAT', 'number'), // number | string | object | object_string
        'send_description' => (bool) env('KAPITALBANK_REFUND_SEND_DESCRIPTION', false),
        'send_pos_detail' => (bool) env('KAPITALBANK_REFUND_SEND_POS_DETAIL', false),
        'extra_json' => env('KAPITALBANK_REFUND_EXTRA_JSON', ''),            // JSON object merged last
    ],
];
