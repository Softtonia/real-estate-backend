<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\Models\DynamicPost;
use App\PageBuilder\Services\RelatedPostQueryService;

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
            'match_post_type' => true,
            'match_taxonomy_terms' => true,
            'match_locations' => true,

            'posts_per_page' => 6,
            'orderby' => 'created_at',
            'order' => 'DESC',

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
                        'label' => 'Match Post Type',
                        'type' => 'switch',
                        'default' => true,
                    ],
                    [
                        'name' => 'match_taxonomy_terms',
                        'label' => 'Match Taxonomy & Terms',
                        'type' => 'switch',
                        'default' => true,
                    ],
                    [
                        'name' => 'match_locations',
                        'label' => 'Match Locations',
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
                                'placeholder' => 'city, property-type',
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
                                'placeholder' => 'city_id, area_id',
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

    public function data(array $settings, ?DynamicPost $currentPost = null): array
    {
        $settings = array_replace_recursive(
            $this->defaultSettings(),
            $settings
        );

        $posts = $this->relatedPostQueryService
            ->getRelatedPosts($settings, $currentPost)
            ->map(fn ($post) => $this->formatPost($post))
            ->values()
            ->all();

        return [
            'title' => $settings['title'] ?? 'Related Posts',
            'posts' => $posts,
            'settings' => $settings,
        ];
    }

    public function renderHtml(array $settings, ?DynamicPost $currentPost = null): string
    {
        return view(
            'page-builder.widgets.related-posts',
            $this->data($settings, $currentPost)
        )->render();
    }

    private function formatPost(object $post): array
    {
        return [
            'id' => $post->id ?? null,
            'title' => $post->title
                ?? $post->post_title
                ?? $post->name
                ?? $post->property_title
                ?? null,
            'slug' => $post->slug ?? $post->post_slug ?? null,
            'content' => $post->content ?? $post->description ?? $post->post_content ?? null,
            'excerpt' => $post->excerpt ?? $post->short_description ?? null,
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