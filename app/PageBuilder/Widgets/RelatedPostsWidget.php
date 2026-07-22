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

            // default true
            'exclude_current' => true,

            // current dynamic post/listing matching
            'match_post_type' => true,
            'match_taxonomy_terms' => true,
            'match_locations' => true,

            'posts_per_page' => 6,
            'orderby' => 'created_at',
            'order' => 'DESC',

            // builder query section
            'query' => [
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
                    [
                        'name' => 'match_post_type',
                        'label' => 'Match Current Post Type',
                        'type' => 'switch',
                        'default' => true,
                    ],
                    [
                        'name' => 'match_taxonomy_terms',
                        'label' => 'Match Current Taxonomy & Terms',
                        'type' => 'switch',
                        'default' => true,
                    ],
                    [
                        'name' => 'match_locations',
                        'label' => 'Match Current Location',
                        'type' => 'switch',
                        'default' => true,
                    ],
                ],
            ],
            [
                'tab' => 'Layout',
                'section' => 'Query',
                'fields' => [
                    [
                        'name' => 'query.relation',
                        'label' => 'Relation',
                        'type' => 'select',
                        'default' => 'AND',
                        'options' => [
                            'AND' => 'AND',
                            'OR' => 'OR',
                        ],
                    ],
                    [
                        'name' => 'query.items',
                        'label' => 'Add Query',
                        'type' => 'repeater',
                        'button_label' => 'Add Query',
                        'fields' => [
                            [
                                'name' => 'type',
                                'label' => 'Query Type',
                                'type' => 'select',
                                'default' => 'custom_field',
                                'options' => [
                                    'custom_field' => 'Custom Field',
                                    'taxonomy' => 'Taxonomy',
                                    'location' => 'Location',
                                ],
                            ],
                            [
                                'name' => 'key',
                                'label' => 'Custom Field Key',
                                'type' => 'text',
                                'placeholder' => 'bedroom, property_price',
                            ],
                            [
                                'name' => 'taxonomy',
                                'label' => 'Taxonomy',
                                'type' => 'text',
                                'placeholder' => 'city, property-type, property-status',
                            ],
                            [
                                'name' => 'terms',
                                'label' => 'Terms',
                                'type' => 'text',
                                'placeholder' => 'term slug/id comma separated',
                            ],
                            [
                                'name' => 'source',
                                'label' => 'Source',
                                'type' => 'select',
                                'default' => 'manual',
                                'options' => [
                                    'manual' => 'Manual',
                                    'current_post' => 'Current Post',
                                ],
                            ],
                            [
                                'name' => 'column',
                                'label' => 'Location Column',
                                'type' => 'text',
                                'placeholder' => 'city_id, area_id, location_id',
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
                                    'EXISTS' => 'EXISTS',
                                    'NOT EXISTS' => 'NOT EXISTS',
                                ],
                            ],
                            [
                                'name' => 'value',
                                'label' => 'Value',
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