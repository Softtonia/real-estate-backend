<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\Models\DynamicPost;
use App\PageBuilder\Foundation\WidgetContext;
use App\PageBuilder\Services\RelatedPostQueryService;
use Throwable;

class RelatedPostsWidget
{
    public function __construct(
        protected RelatedPostQueryService $relatedPostQueryService
    ) {
    }

    public function key(): string
    {
        return 'related_posts';
    }

    public function sidebarItem(): array
    {
        return [
            'label' => 'Related Posts',
            'key' => 'related_posts',
            'source' => 'basic_widget',
            'type' => 'related_posts',
            'component_key' => 'related_posts',
            'field_value' => [],
            'value' => [],
            'has_value' => false,
            'settings' => $this->defaultSettings(),
            'settings_schema' => $this->settingsSchema(),
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'title' => 'Related Posts',
            'exclude_current' => true,
            'posts_per_page' => 6,
            'orderby' => 'created_at',
            'order' => 'DESC',

            /*
             * Post Type Mapping
             *
             * same_post_type:
             * current post_type_id = related post post_type_id
             *
             * related_post_types:
             * use post type relationship mapping from PostType module
             */
            'post_type_mapping' => [
                'enabled' => true,
                'source' => 'current_post_type',
                'target' => 'same_post_type',
            ],

            /*
             * Taxonomy Mapping
             *
             * source_taxonomy = current post taxonomy
             * target_taxonomy = related post taxonomy
             */
            'taxonomy_mapping' => [
                'enabled' => true,
                'relation' => 'AND',
                'items' => [
                    [
                        'source_taxonomy' => 'purpose',
                        'target_taxonomy' => 'purpose',
                        'terms_source' => 'current_post',
                        'operator' => 'IN',
                    ],
                    [
                        'source_taxonomy' => 'property',
                        'target_taxonomy' => 'property',
                        'terms_source' => 'current_post',
                        'operator' => 'IN',
                    ],
                    [
                        'source_taxonomy' => 'property-type',
                        'target_taxonomy' => 'property-type',
                        'terms_source' => 'current_post',
                        'operator' => 'IN',
                    ],
                    [
                        'source_taxonomy' => 'property-status',
                        'target_taxonomy' => 'property-status',
                        'terms_source' => 'current_post',
                        'operator' => 'IN',
                    ],
                ],
            ],

            /*
             * Location Mapping
             *
             * source_field = current post location field
             * target_field = related post location field
             */
            'location_mapping' => [
                'enabled' => true,
                'relation' => 'AND',
                'items' => [
                    [
                        'source_field' => 'country_id',
                        'target_field' => 'country_id',
                    ],
                    [
                        'source_field' => 'state_id',
                        'target_field' => 'state_id',
                    ],
                    [
                        'source_field' => 'city_id',
                        'target_field' => 'city_id',
                    ],
                    [
                        'source_field' => 'area_locality',
                        'target_field' => 'area_locality',
                    ],
                ],
            ],

            /*
             * Extra Query Mapping
             *
             * Example:
             * current bedroom field -> related bedroom field
             * related price field between 1000-2000
             */
            'query_mapping' => [
                'relation' => 'AND',
                'items' => [],
            ],
        ];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'tab' => 'Layout',
                'section' => 'Content',
                'fields' => [
                    [
                        'name' => 'title',
                        'label' => 'Title',
                        'type' => 'text',
                        'default' => 'Related Posts',
                    ],
                    [
                        'name' => 'posts_per_page',
                        'label' => 'Posts Per Page',
                        'type' => 'number',
                        'default' => 6,
                    ],
                    [
                        'name' => 'exclude_current',
                        'label' => 'Exclude Current Post',
                        'type' => 'switch',
                        'default' => true,
                    ],
                ],
            ],

            [
                'tab' => 'Layout',
                'section' => 'Post Type Mapping',
                'fields' => [
                    [
                        'name' => 'post_type_mapping.enabled',
                        'label' => 'Enable Post Type Mapping',
                        'type' => 'switch',
                        'default' => true,
                    ],
                    [
                        'name' => 'post_type_mapping.source',
                        'label' => 'Current Post Source',
                        'type' => 'select',
                        'default' => 'current_post_type',
                        'options' => [
                            'current_post_type' => 'Current Post Type',
                        ],
                    ],
                    [
                        'name' => 'post_type_mapping.target',
                        'label' => 'Map With Related Posts',
                        'type' => 'select',
                        'default' => 'same_post_type',
                        'options' => [
                            'same_post_type' => 'Same Post Type',
                            'related_post_types' => 'Mapped Related Post Types',
                            'same_or_related_post_types' => 'Same + Mapped Related Post Types',
                        ],
                    ],
                ],
            ],

            [
                'tab' => 'Layout',
                'section' => 'Taxonomy Mapping',
                'fields' => [
                    [
                        'name' => 'taxonomy_mapping.enabled',
                        'label' => 'Enable Taxonomy Mapping',
                        'type' => 'switch',
                        'default' => true,
                    ],
                    [
                        'name' => 'taxonomy_mapping.relation',
                        'label' => 'Taxonomy Relation',
                        'type' => 'select',
                        'default' => 'AND',
                        'options' => [
                            'AND' => 'AND',
                            'OR' => 'OR',
                        ],
                    ],
                    [
                        'name' => 'taxonomy_mapping.items',
                        'label' => 'Map Taxonomies',
                        'type' => 'repeater',
                        'button_label' => 'Add Taxonomy Mapping',
                        'fields' => [
                            [
                                'name' => 'source_taxonomy',
                                'label' => 'Current Post Taxonomy',
                                'type' => 'text',
                                'placeholder' => 'purpose, property, property-type',
                            ],
                            [
                                'name' => 'target_taxonomy',
                                'label' => 'Related Post Taxonomy',
                                'type' => 'text',
                                'placeholder' => 'purpose, property, property-type',
                            ],
                            [
                                'name' => 'terms_source',
                                'label' => 'Terms Source',
                                'type' => 'select',
                                'default' => 'current_post',
                                'options' => [
                                    'current_post' => 'Use Current Post Selected Terms',
                                    'manual' => 'Manual Terms',
                                ],
                            ],
                            [
                                'name' => 'manual_terms',
                                'label' => 'Manual Terms',
                                'type' => 'text',
                                'placeholder' => 'term slug/id comma separated',
                            ],
                            [
                                'name' => 'operator',
                                'label' => 'Operator',
                                'type' => 'select',
                                'default' => 'IN',
                                'options' => [
                                    'IN' => 'IN',
                                    'NOT IN' => 'NOT IN',
                                    'AND' => 'AND',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            [
                'tab' => 'Layout',
                'section' => 'Location Mapping',
                'fields' => [
                    [
                        'name' => 'location_mapping.enabled',
                        'label' => 'Enable Location Mapping',
                        'type' => 'switch',
                        'default' => true,
                    ],
                    [
                        'name' => 'location_mapping.relation',
                        'label' => 'Location Relation',
                        'type' => 'select',
                        'default' => 'AND',
                        'options' => [
                            'AND' => 'AND',
                            'OR' => 'OR',
                        ],
                    ],
                    [
                        'name' => 'location_mapping.items',
                        'label' => 'Map Locations',
                        'type' => 'repeater',
                        'button_label' => 'Add Location Mapping',
                        'fields' => [
                            [
                                'name' => 'source_field',
                                'label' => 'Current Post Location Field',
                                'type' => 'select',
                                'default' => 'city_id',
                                'options' => [
                                    'country_id' => 'Country',
                                    'state_id' => 'State',
                                    'city_id' => 'City',
                                    'area_locality' => 'Area Locality',
                                ],
                            ],
                            [
                                'name' => 'target_field',
                                'label' => 'Related Post Location Field',
                                'type' => 'select',
                                'default' => 'city_id',
                                'options' => [
                                    'country_id' => 'Country',
                                    'state_id' => 'State',
                                    'city_id' => 'City',
                                    'area_locality' => 'Area Locality',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            [
                'tab' => 'Layout',
                'section' => 'Query Mapping',
                'fields' => [
                    [
                        'name' => 'query_mapping.relation',
                        'label' => 'Query Relation',
                        'type' => 'select',
                        'default' => 'AND',
                        'options' => [
                            'AND' => 'AND',
                            'OR' => 'OR',
                        ],
                    ],
                    [
                        'name' => 'query_mapping.items',
                        'label' => 'Add Query Mapping',
                        'type' => 'repeater',
                        'button_label' => 'Add Query Mapping',
                        'fields' => [
                            [
                                'name' => 'source_type',
                                'label' => 'Source Type',
                                'type' => 'select',
                                'default' => 'manual',
                                'options' => [
                                    'current_post_field' => 'Current Post Field',
                                    'manual' => 'Manual Value',
                                ],
                            ],
                            [
                                'name' => 'source_key',
                                'label' => 'Current Post Field Key',
                                'type' => 'text',
                                'placeholder' => 'bedroom',
                            ],
                            [
                                'name' => 'target_key',
                                'label' => 'Related Post Field Key',
                                'type' => 'text',
                                'placeholder' => 'bedroom, property_price',
                            ],
                            [
                                'name' => 'compare',
                                'label' => 'Compare',
                                'type' => 'select',
                                'default' => '=',
                                'options' => [
                                    '=' => '=',
                                    '!=' => '!=',
                                    '>' => '>',
                                    '>=' => '>=',
                                    '<' => '<',
                                    '<=' => '<=',
                                    'LIKE' => 'LIKE',
                                    'IN' => 'IN',
                                    'NOT IN' => 'NOT IN',
                                    'BETWEEN' => 'BETWEEN',
                                    'NOT BETWEEN' => 'NOT BETWEEN',
                                ],
                            ],
                            [
                                'name' => 'manual_value',
                                'label' => 'Manual Value',
                                'type' => 'text',
                                'placeholder' => '1BHK or 1000-2000',
                            ],
                            [
                                'name' => 'value_type',
                                'label' => 'Value Type',
                                'type' => 'select',
                                'default' => 'CHAR',
                                'options' => [
                                    'CHAR' => 'Text',
                                    'NUMERIC' => 'Number',
                                    'DATE' => 'Date',
                                    'DATETIME' => 'Date Time',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            [
                'tab' => 'Layout',
                'section' => 'Order',
                'fields' => [
                    [
                        'name' => 'orderby',
                        'label' => 'Order By',
                        'type' => 'select',
                        'default' => 'created_at',
                        'options' => [
                            'created_at' => 'Created Date',
                            'updated_at' => 'Updated Date',
                            'title' => 'Title',
                            'rand' => 'Random',
                        ],
                    ],
                    [
                        'name' => 'order',
                        'label' => 'Order',
                        'type' => 'select',
                        'default' => 'DESC',
                        'options' => [
                            'DESC' => 'DESC',
                            'ASC' => 'ASC',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function render(
        array $settings,
        ?WidgetContext $context = null,
        ?DynamicPost $currentPost = null
    ): string {
        try {
            $contextData = $this->contextToArray($context);

            if (! $currentPost) {
                $currentPost = $this->resolveCurrentPost($contextData);
            }

            return $this->renderHtml(
                settings: $settings,
                currentPost: $currentPost,
                context: $contextData
            );
        } catch (Throwable $e) {
            return '<div class="pb-widget-error">' . e($e->getMessage()) . '</div>';
        }
    }

    public function data(
        array $settings,
        ?DynamicPost $currentPost = null,
        array $context = []
    ): array {
        $settings = array_replace_recursive(
            $this->defaultSettings(),
            $settings
        );

        $posts = $this->relatedPostQueryService
            ->getRelatedPosts(
                settings: $settings,
                currentPost: $currentPost,
                context: $context
            )
            ->map(fn ($post) => $this->formatPost($post))
            ->values()
            ->all();

        return [
            'title' => $settings['title'] ?? 'Related Posts',
            'posts' => $posts,
            'settings' => $settings,
        ];
    }

    public function renderHtml(
        array $settings,
        ?DynamicPost $currentPost = null,
        array $context = []
    ): string {
        return view(
            'page-builder.widgets.related-posts',
            $this->data($settings, $currentPost, $context)
        )->render();
    }

    private function resolveCurrentPost(array $context): ?DynamicPost
    {
        foreach (['current_post', 'dynamic_post', 'post'] as $key) {
            $value = data_get($context, $key);

            if ($value instanceof DynamicPost) {
                return $value;
            }

            if (is_array($value) && ! empty($value['id'])) {
                return DynamicPost::find((int) $value['id']);
            }

            if (is_object($value) && ! empty($value->id)) {
                return DynamicPost::find((int) $value->id);
            }
        }

        $postId = data_get($context, 'entity_id')
            ?? data_get($context, 'current_post_id')
            ?? data_get($context, 'dynamic_post_id')
            ?? data_get($context, 'post_id')
            ?? data_get($context, 'id');

        return $postId ? DynamicPost::find((int) $postId) : null;
    }

    private function contextToArray(?WidgetContext $context): array
    {
        if (! $context) {
            return [];
        }

        if (method_exists($context, 'toArray')) {
            try {
                $data = $context->toArray();

                if (is_array($data)) {
                    return $data;
                }
            } catch (Throwable) {
            }
        }

        if (method_exists($context, 'data')) {
            try {
                $data = $context->data();

                if (is_array($data)) {
                    return $data;
                }
            } catch (Throwable) {
            }
        }

        $keys = [
            'entity_id',
            'post_id',
            'dynamic_post_id',
            'current_post_id',
            'post_type_id',
            'selected_taxonomy_term_ids',
            'preview_values',
            'taxonomies',
            'terms',
            'current_post',
            'dynamic_post',
            'post',
            'request',
        ];

        $data = [];

        foreach ($keys as $key) {
            if (method_exists($context, 'get')) {
                try {
                    $value = $context->get($key);

                    if ($value !== null) {
                        $data[$key] = $value;
                    }
                } catch (Throwable) {
                }
            }

            try {
                if (isset($context->{$key})) {
                    $data[$key] = $context->{$key};
                }
            } catch (Throwable) {
            }
        }

        return $data;
    }

    private function formatPost(object $post): array
    {
        return [
            'id' => $post->id ?? null,
            'post_type_id' => $post->post_type_id ?? null,

            'title' => $post->title
                ?? $post->post_title
                ?? $post->name
                ?? $post->property_title
                ?? $post->project_name
                ?? $post->developer_name
                ?? null,

            'slug' => $post->slug
                ?? $post->post_slug
                ?? null,

            'content' => $post->content
                ?? $post->description
                ?? $post->post_content
                ?? null,

            'excerpt' => $post->excerpt
                ?? $post->short_description
                ?? null,

            'featured_image' => $post->featured_image
                ?? $post->thumbnail
                ?? $post->image
                ?? $post->banner_image
                ?? null,

            'status' => $post->status ?? null,
            'created_at' => $post->created_at ?? null,
            'updated_at' => $post->updated_at ?? null,
        ];
    }
}