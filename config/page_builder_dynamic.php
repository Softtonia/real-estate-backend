<?php

return [
    'tables' => [
        'posts' => 'dynamic_posts',
        'post_types' => 'post_types',
        'custom_fields' => 'custom_fields',

        'custom_field_values' => [
            'custom_field_values',
            'post_custom_field_values',
            'custom_field_data',
            'post_meta',
        ],

        'post_term_relations' => [
            'post_taxonomy_terms',
            'post_terms',
            'post_term_relationships',
            'taxonomy_post',
        ],

        'taxonomy_terms' => [
            'taxonomy_terms',
            'terms',
        ],

        'taxonomies' => [
            'taxonomies',
        ],

        'custom_field_term_assignments' => [
            'custom_field_taxonomy_terms',
            'custom_field_terms',
            'custom_field_term_assignments',
        ],
    ],

    'columns' => [
        'post_id' => [
            'entity_id',
            'dynamic_post_id',
            'post_id',
            'content_id',
            'object_id',
            'model_id',
        ],

        'entity_type' => [
            'entity_type',
            'model_type',
            'object_type',
        ],

        'term_id' => [
            'term_id',
            'taxonomy_term_id',
        ],

        'taxonomy_id' => [
            'taxonomy_id',
        ],

        'custom_field_id' => [
            'custom_field_id',
            'field_id',
        ],

        'field_key' => [
            'field_key',
            'field_name_slug',
            'field_name',
            'meta_key',
            'key',
        ],

        'field_value' => [
            'value',
            'value_text',
            'value_string',
            'value_number',
            'value_decimal',
            'value_json',
            'field_value',
            'meta_value',
            'content',
            'data',
        ],
    ],
];