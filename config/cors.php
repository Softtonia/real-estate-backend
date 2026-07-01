<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'Authorization',
        'Origin',
        'Referer',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',

        // Application access security headers
        'X-Application-Password',
        'X-App-Type',
        'X-App-Origin',
        'X-Debug-API-Client',

        // Signature headers
        'X-Timestamp',
        'X-Nonce',
        'X-Signature',
    ],

    'exposed_headers' => [
        'Content-Type',
    ],

    'max_age' => 86400,

    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', true),

];