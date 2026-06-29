<?php

return [
    'token_prefix' => env('API_TOKEN_PREFIX', env('APP_ENV') === 'production' ? 'sk_live_' : 'sk_test_'),

    'signature_ttl_seconds' => env('API_SIGNATURE_TTL', 300),

    'default_rate_limit_per_minute' => env('API_DEFAULT_RATE_LIMIT', 300),
    'failed_auth_window_minutes' => env('API_FAILED_AUTH_WINDOW_MINUTES', 10),

    'failed_auth_threshold' => env('API_FAILED_AUTH_THRESHOLD', 20),

    'failed_auth_block_minutes' => env('API_FAILED_AUTH_BLOCK_MINUTES', 60),
    'failed_auth_retention_days' => env('API_FAILED_AUTH_RETENTION_DAYS', 30),

    'request_log_retention_days' => env('API_REQUEST_LOG_RETENTION_DAYS', 30),

    'audit_log_retention_days' => env('API_AUDIT_LOG_RETENTION_DAYS', 180),

    'expired_ip_block_retention_days' => env('API_EXPIRED_IP_BLOCK_RETENTION_DAYS', 7),

    'cleanup_chunk_size' => env('API_CLEANUP_CHUNK_SIZE', 1000),

    'allowed_dev_origins' => array_filter(array_map('trim', explode(',', env(
        'API_ALLOWED_DEV_ORIGINS',
        'http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173'
    )))),
];
