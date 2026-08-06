<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sold Property Public Visibility
    |--------------------------------------------------------------------------
    |
    | Sold properties remain publicly visible with a Sold badge for this
    | number of days. They are hidden automatically after the deadline.
    |
    */
    'sold_public_days' => (int) env(
        'PROPERTY_SOLD_PUBLIC_DAYS',
        7
    ),

    /*
    |--------------------------------------------------------------------------
    | Scheduler Batch Size
    |--------------------------------------------------------------------------
    */
    'expiry_batch_size' => (int) env(
        'PROPERTY_AVAILABILITY_EXPIRY_BATCH_SIZE',
        200
    ),
];