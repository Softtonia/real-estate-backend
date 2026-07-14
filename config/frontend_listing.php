<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend listing taxonomies
    |--------------------------------------------------------------------------
    |
    | Only these taxonomies will be displayed and accepted through the
    | frontend listing form.
    |
    */

    'taxonomy_slugs' => [
        'property-type',
        'purpose',
        'property-status',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default listing statuses
    |--------------------------------------------------------------------------
    */

    'default_status' => 'draft',

    'default_live_status' => 'submit',

];