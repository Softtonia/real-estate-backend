<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel default CORS disabled for API
    |--------------------------------------------------------------------------
    |
    | DynamicApiCors will handle CORS from api_clients.allowed_origins.
    | Keep paths empty so Laravel's default HandleCors does not block dynamic DB origins.
    |
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];