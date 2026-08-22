<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| API-only backend consumed by a separate frontend (SPA / mobile) using
| Sanctum bearer tokens — not cookies — so supports_credentials stays false.
|
| Set CORS_ALLOWED_ORIGINS to a comma-separated list of exact origins, e.g.:
|   CORS_ALLOWED_ORIGINS=https://erp.example.co.id,https://app.example.co.id
| Empty (the default) means no cross-origin browser access is allowed.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
