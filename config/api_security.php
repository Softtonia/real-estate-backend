<?php

return [

    'default_rate_limit_per_minute' => (int) env('API_DEFAULT_RATE_LIMIT', 300),

    'signature_ttl' => (int) env('API_SIGNATURE_TTL', 300),

    'token_prefix' => env('API_TOKEN_PREFIX', 'sk_live_'),

    'failed_auth_window_minutes' => (int) env('API_FAILED_AUTH_WINDOW_MINUTES', 10),

    'failed_auth_threshold' => (int) env('API_FAILED_AUTH_THRESHOLD', 20),

    'failed_auth_block_minutes' => (int) env('API_FAILED_AUTH_BLOCK_MINUTES', 60),

    'failed_auth_retention_days' => (int) env('API_FAILED_AUTH_RETENTION_DAYS', 30),

    'request_log_retention_days' => (int) env('API_REQUEST_LOG_RETENTION_DAYS', 30),

    'audit_log_retention_days' => (int) env('API_AUDIT_LOG_RETENTION_DAYS', 180),

    'expired_ip_block_retention_days' => (int) env('API_EXPIRED_IP_BLOCK_RETENTION_DAYS', 7),

    'cleanup_chunk_size' => (int) env('API_CLEANUP_CHUNK_SIZE', 1000),
        'fixed_tokens' => [
        'FIXED_ADMIN_PANEL_TOKEN' => env('FIXED_ADMIN_PANEL_TOKEN'),

        'FIXED_LOCAL_REACT_ADMIN_TOKEN' => env(
            'FIXED_LOCAL_REACT_ADMIN_TOKEN'
        ),

        'FIXED_LOCAL_API_TOKEN' => env('FIXED_LOCAL_API_TOKEN'),

        'FIXED_BUSINESS_LIVE_TOKEN' => env(
            'FIXED_BUSINESS_LIVE_TOKEN'
        ),

        'FIXED_BUSINESS_LOCAL_TOKEN' => env(
            'FIXED_BUSINESS_LOCAL_TOKEN'
        ),

        'FIXED_LOCAL_NEXTJS_TOKEN' => env(
            'FIXED_LOCAL_NEXTJS_TOKEN'
        ),

        'FIXED_HOLIPLACES_WEBSITE_TOKEN' => env(
            'FIXED_HOLIPLACES_WEBSITE_TOKEN'
        ),

        'FIXED_MOBILE_APPLICATION_TOKEN' => env(
            'FIXED_MOBILE_APPLICATION_TOKEN'
        ),
    ],

];