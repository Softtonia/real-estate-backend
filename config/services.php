<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'razorpay' => [
        'mode' => env('RAZORPAY_MODE', 'test'),
        'currency' => env('RAZORPAY_CURRENCY', 'INR'),

        'test_key_id' => env('RAZORPAY_TEST_KEY_ID'),
        'test_key_secret' => env('RAZORPAY_TEST_KEY_SECRET'),
        'test_webhook_secret' => env('RAZORPAY_TEST_WEBHOOK_SECRET'),

        'live_key_id' => env('RAZORPAY_LIVE_KEY_ID'),
        'live_key_secret' => env('RAZORPAY_LIVE_KEY_SECRET'),
        'live_webhook_secret' => env('RAZORPAY_LIVE_WEBHOOK_SECRET'),

        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],
];
