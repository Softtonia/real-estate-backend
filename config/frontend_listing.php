<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend taxonomies
    |--------------------------------------------------------------------------
    |
    | Only these taxonomies will be displayed and accepted.
    |
    */

    'taxonomy_slugs' => [
        'property-type',
        'purpose',
        'property-status',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fixed listing post type
    |--------------------------------------------------------------------------
    |
    | Dynamic posts may still require post_type_id in the database.
    | We use one fixed listing post type ID, but we do not check its attached
    | taxonomies.
    |
    */

    'post_type_id' => env('FRONTEND_LISTING_POST_TYPE_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Listing status
    |--------------------------------------------------------------------------
    |
    | User requested that frontend listings should not be stored as draft.
    |
    */

    'status' => 'published',

    'live_status' => 'submit',

];