<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\PostType;
use App\PageBuilder\Foundation\WidgetContext;

class DynamicPostDataService
{
    public function __construct(
        protected DynamicPostFieldValueService $dynamicPostFieldValueService
    ) {
    }

    public function loadForResolvePayload(array $payload): array
    {
        $postId = $payload['dynamic_post_id']
            ?? $payload['post_id']
            ?? $payload['id']
            ?? null;

        if (! $postId) {
            return [];
        }

        $postType = $this->resolvePostType($payload);

        $resolved = $this->dynamicPostFieldValueService->resolveForPost(
            (int) $postId,
            $postType?->id ?? (! empty($payload['post_type_id']) ? (int) $payload['post_type_id'] : null),
            $postType?->slug ?? ($payload['post_type'] ?? null)
        );

        if (empty($resolved['post'])) {
            return [];
        }

        $system = $resolved['fields']['system'] ?? [];
        $custom = $resolved['fields']['custom'] ?? [];

        return [
            'id' => $system['id'] ?? $postId,
            'title' => $system['title'] ?? null,
            'slug' => $system['slug'] ?? null,
            'status' => $system['status'] ?? null,

            'post' => $resolved['post'],
            'post_type_id' => $resolved['post_type']['id'] ?? $postType?->id ?? null,
            'post_type' => $resolved['post_type']['slug'] ?? $postType?->slug ?? null,
            'post_type_name' => $resolved['post_type']['name'] ?? $postType?->name ?? null,

            'content_data' => $resolved['content_data'] ?? [
                'system' => $system,
                'custom' => $custom,
            ],

            'fields' => $resolved['fields'] ?? [
                'system' => $system,
                'custom' => $custom,
            ],

            'taxonomy_term_ids' => $resolved['taxonomy_term_ids'] ?? [],
            'taxonomy_terms' => $resolved['taxonomy_terms'] ?? [],
            'taxonomies' => $resolved['taxonomies'] ?? [],
            'terms' => $resolved['terms'] ?? [],
            'assigned_terms' => $resolved['assigned_terms'] ?? [],
        ];
    }

    public function contextFromPayload(array $payload, string $mode = 'frontend'): WidgetContext
    {
        $loaded = $this->loadForResolvePayload($payload);

        $fields = $loaded['fields']
            ?? $loaded['content_data']
            ?? [];

        if (! is_array($fields)) {
            $fields = [];
        }

        /*
         * Preview/manual values override database values.
         */
        $manualFields = $payload['content_data']
            ?? $payload['fields']
            ?? [];

        if (is_array($manualFields)) {
            $fields = array_replace_recursive($fields, $manualFields);
        }

        $post = isset($loaded['post']) && is_array($loaded['post'])
            ? (object) $loaded['post']
            : null;

        $postType = null;

        if (! empty($loaded['post_type_id']) || ! empty($loaded['post_type'])) {
            $postType = (object) [
                'id' => $loaded['post_type_id'] ?? null,
                'slug' => $loaded['post_type'] ?? null,
                'name' => $loaded['post_type_name'] ?? null,
            ];
        }

        return new WidgetContext(
            post: $post,
            postType: $postType,
            postTypeId: ! empty($loaded['post_type_id']) ? (int) $loaded['post_type_id'] : null,
            fields: $fields,
            taxonomies: $loaded['taxonomies'] ?? [],
            terms: $loaded['terms'] ?? [],
            requestData: $payload,
            mode: $mode
        );
    }

    public function previewContext(array $payload): WidgetContext
    {
        return $this->contextFromPayload($payload, 'preview');
    }

    public function frontendContext(array $payload): WidgetContext
    {
        return $this->contextFromPayload($payload, 'frontend');
    }

    public function resolve(array $payload): array
    {
        return $this->loadForResolvePayload($payload);
    }

    private function resolvePostType(array $payload): ?PostType
    {
        if (! empty($payload['post_type_id'])) {
            return PostType::query()
                ->where('id', $payload['post_type_id'])
                ->first();
        }

        if (! empty($payload['post_type'])) {
            return PostType::query()
                ->where('slug', $payload['post_type'])
                ->first();
        }

        return null;
    }
}