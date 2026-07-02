<?php

return [
    'enabled' => env('PAGE_BUILDER_CACHE_ENABLED', true),

    /*
     * Main cache store.
     */
    'store' => env(
        'PAGE_BUILDER_CACHE_STORE',
        env('CACHE_STORE', env('CACHE_DRIVER', 'redis'))
    ),

    /*
     * Safe fallback store.
     * If Redis fails, PageBuilder will silently use this.
     */
    'fallback_store' => env('PAGE_BUILDER_CACHE_FALLBACK_STORE', 'file'),

    /*
     * Cache lifetime in seconds.
     */
    'ttl' => env('PAGE_BUILDER_CACHE_TTL', 3600),

    'prefix' => env('PAGE_BUILDER_CACHE_PREFIX', 'page_builder'),

    /*
     * Keep false so Redis errors are not exposed or logged repeatedly.
     */
    'log_errors' => env('PAGE_BUILDER_CACHE_LOG_ERRORS', false),
];