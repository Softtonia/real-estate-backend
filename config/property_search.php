<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Property related post types
    |--------------------------------------------------------------------------
    */
    'post_type_slugs' => [
        'property-listing',
        'project-listing',
        'developer-listing',
    ],

    'taxonomy_slugs' => [
        'purpose' => ['property_purpose', 'property-purpose'],
        'property_type' => ['property_type', 'property-type'],
        'bedrooms' => ['bedrooms', 'bedroom', 'bhk'],
    ],

    'price_field_slugs' => [
        'price',
        'property_price',
        'expected_price',
        'sale_price',
        'rent',
        'monthly_rent',
    ],

    'default_per_page' => 20,
    'max_per_page' => 50,

    'budget_options' => [
        'min' => [
            500000,
            1000000,
            2000000,
            3000000,
            4000000,
            5000000,
            7500000,
            10000000,
            15000000,
            20000000,
        ],
        'max' => [
            1000000,
            2000000,
            3000000,
            5000000,
            7500000,
            10000000,
            15000000,
            20000000,
            30000000,
            50000000,
        ],
    ],
];