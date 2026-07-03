<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\PostType;
use App\Models\Template;
use Illuminate\Support\Collection;

class TemplateResolveService
{
    public function __construct(
        protected TemplateRenderService $templateRenderService,
        protected DynamicPostDataService $dynamicPostDataService
    ) {
    }

    public function resolve(array $payload): ?array
    {
        $payload = $this->normalizePayload($payload);
        $payload = $this->mergeDynamicPostData($payload);
        $payload = $this->normalizePayload($payload);

        $templateType = $payload['template_type'] ?? 'single_post';

        $templates = Template::with([
            'conditions' => function ($query) {
                $query->orderBy('id', 'asc');
            },
            'layout',
            'postType',
        ])
            ->where('status', 'active')
            ->where('template_type', $templateType)
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        foreach ($templates as $template) {
            if (! $this->templateMatches($template, $payload)) {
                continue;
            }

            $renderPayload = $this->prepareRenderPayload($payload);

            $rendered = $this->templateRenderService->preview(
                $template,
                $renderPayload
            );

            return [
                'matched' => true,
                'match' => [
                    'template_id' => $template->id,
                    'template_name' => $template->template_name,
                    'template_type' => $template->template_type,
                    'post_type_id' => $template->post_type_id,
                    'post_type_slug' => $template->post_type_slug,
                    'priority' => $template->priority,
                ],
                'post' => $payload['post'] ?? null,
                'fields' => $renderPayload['fields'] ?? [],
                'rendered' => $rendered,
            ];
        }

        return null;
    }

    private function mergeDynamicPostData(array $payload): array
    {
        $loaded = $this->dynamicPostDataService->loadForResolvePayload($payload);

        if (empty($loaded)) {
            return $payload;
        }

        $loadedFields = $loaded['content_data']
            ?? $loaded['fields']
            ?? [];

        $requestFields = $payload['content_data']
            ?? $payload['fields']
            ?? [];

        if (! is_array($loadedFields)) {
            $loadedFields = [];
        }

        if (! is_array($requestFields)) {
            $requestFields = [];
        }

        /*
         * Keep resolved DB data, but allow request preview data to override.
         */
        $payload = array_replace_recursive($payload, $loaded);

        $payload['content_data'] = array_replace_recursive(
            $loadedFields,
            $requestFields
        );

        $payload['fields'] = $payload['content_data'];

        if (empty($payload['taxonomy_term_ids']) && ! empty($loaded['taxonomy_term_ids'])) {
            $payload['taxonomy_term_ids'] = $loaded['taxonomy_term_ids'];
        }

        if (empty($payload['taxonomy_terms']) && ! empty($loaded['taxonomy_terms'])) {
            $payload['taxonomy_terms'] = $loaded['taxonomy_terms'];
        }

        if (empty($payload['taxonomies']) && ! empty($loaded['taxonomies'])) {
            $payload['taxonomies'] = $loaded['taxonomies'];
        }

        if (empty($payload['terms']) && ! empty($loaded['terms'])) {
            $payload['terms'] = $loaded['terms'];
        }

        return $payload;
    }

    private function templateMatches(Template $template, array $payload): bool
    {
        if (! $this->templateBaseMatches($template, $payload)) {
            return false;
        }

        $conditions = $template->conditions ?? collect();

        if (! $conditions instanceof Collection) {
            $conditions = collect($conditions);
        }

        $conditions = $conditions->sortBy('id')->values();

        $excludeConditions = $conditions
            ->where('show_type', 'exclude')
            ->values();

        if ($excludeConditions->isNotEmpty()) {
            if ($this->evaluateConditionExpression($excludeConditions, $payload)) {
                return false;
            }
        }

        $includeConditions = $conditions
            ->where('show_type', 'include')
            ->values();

        if ($includeConditions->isNotEmpty()) {
            return $this->evaluateConditionExpression($includeConditions, $payload);
        }

        return true;
    }

    private function templateBaseMatches(Template $template, array $payload): bool
    {
        if (($payload['template_type'] ?? 'single_post') !== 'single_post') {
            return true;
        }

        if (! empty($template->post_type_id) && ! empty($payload['_post_type_id'])) {
            return (int) $template->post_type_id === (int) $payload['_post_type_id'];
        }

        if (! empty($template->post_type_slug) && ! empty($payload['_post_type_slug'])) {
            return (string) $template->post_type_slug === (string) $payload['_post_type_slug'];
        }

        return false;
    }

    private function evaluateConditionExpression(Collection $conditions, array $payload): bool
    {
        $groups = [];
        $currentGroup = [];

        foreach ($conditions as $index => $condition) {
            $relation = strtolower((string) ($condition->relation ?? 'and'));

            if ($index > 0 && $relation === 'or') {
                $groups[] = $currentGroup;
                $currentGroup = [];
            }

            $currentGroup[] = $condition;
        }

        if (! empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        foreach ($groups as $group) {
            $groupMatched = true;

            foreach ($group as $condition) {
                if (! $this->conditionMatches($condition, $payload)) {
                    $groupMatched = false;
                    break;
                }
            }

            if ($groupMatched) {
                return true;
            }
        }

        return false;
    }

    private function conditionMatches($condition, array $payload): bool
    {
        $sourceType = $condition->source_type
            ?? $condition->show_if
            ?? null;

        if ($sourceType === 'post_type') {
            return $this->postTypeConditionMatches($condition, $payload);
        }

        if ($sourceType === 'taxonomy') {
            return $this->taxonomyConditionMatches($condition, $payload);
        }

        return false;
    }

    private function postTypeConditionMatches($condition, array $payload): bool
    {
        if (! empty($condition->post_type_id) && ! empty($payload['_post_type_id'])) {
            return (int) $condition->post_type_id === (int) $payload['_post_type_id'];
        }

        if (! empty($condition->post_type_slug) && ! empty($payload['_post_type_slug'])) {
            return (string) $condition->post_type_slug === (string) $payload['_post_type_slug'];
        }

        return false;
    }

    private function taxonomyConditionMatches($condition, array $payload): bool
    {
        $conditionTermIds = $condition->taxonomy_term_ids ?? [];

        if (is_string($conditionTermIds)) {
            $decoded = json_decode($conditionTermIds, true);
            $conditionTermIds = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($conditionTermIds) || empty($conditionTermIds)) {
            return false;
        }

        $requestTermIds = $this->getRequestTermIdsForCondition($condition, $payload);

        if (empty($requestTermIds)) {
            return false;
        }

        $conditionTermIds = array_map('intval', $conditionTermIds);
        $requestTermIds = array_map('intval', $requestTermIds);

        return count(array_intersect($conditionTermIds, $requestTermIds)) > 0;
    }

    private function getRequestTermIdsForCondition($condition, array $payload): array
    {
        $termIds = [];

        if (! empty($payload['taxonomy_term_ids']) && is_array($payload['taxonomy_term_ids'])) {
            $termIds = array_merge($termIds, $payload['taxonomy_term_ids']);
        }

        if (! empty($payload['taxonomy_terms']) && is_array($payload['taxonomy_terms'])) {
            $taxonomyId = (string) ($condition->taxonomy_id ?? '');
            $taxonomySlug = (string) ($condition->taxonomy_slug ?? '');

            if ($taxonomyId !== '' && isset($payload['taxonomy_terms'][$taxonomyId])) {
                $termIds = array_merge(
                    $termIds,
                    $this->normalizeTermIds($payload['taxonomy_terms'][$taxonomyId])
                );
            }

            if ($taxonomySlug !== '' && isset($payload['taxonomy_terms'][$taxonomySlug])) {
                $termIds = array_merge(
                    $termIds,
                    $this->normalizeTermIds($payload['taxonomy_terms'][$taxonomySlug])
                );
            }
        }

        return array_values(array_unique(array_filter($termIds, function ($id) {
            return $id !== null && $id !== '';
        })));
    }

    private function normalizeTermIds(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            return array_filter(array_map('trim', explode(',', $value)));
        }

        return [];
    }

    private function normalizePayload(array $payload): array
    {
        $postType = null;

        if (! empty($payload['post_type_id'])) {
            $postType = PostType::query()
                ->where('id', $payload['post_type_id'])
                ->first();
        }

        if (! $postType && ! empty($payload['post_type'])) {
            $postType = PostType::query()
                ->where('slug', $payload['post_type'])
                ->first();
        }

        $payload['_post_type_id'] = $postType?->id
            ?? ($payload['post_type_id'] ?? null);

        $payload['_post_type_slug'] = $postType?->slug
            ?? ($payload['post_type'] ?? null);

        $payload['_post_type_name'] = $postType?->name ?? null;

        return $payload;
    }

    private function prepareRenderPayload(array $payload): array
    {
        $fields = $payload['fields']
            ?? $payload['content_data']
            ?? [];

        if (! is_array($fields)) {
            $fields = [];
        }

        if (! isset($fields['system']) || ! is_array($fields['system'])) {
            $fields['system'] = [];
        }

        foreach ([
            'id',
            'title',
            'slug',
            'status',
            'created_at',
            'updated_at',
        ] as $key) {
            if (isset($payload[$key]) && ! isset($fields['system'][$key])) {
                $fields['system'][$key] = $payload[$key];
            }
        }

        return array_merge($payload, [
            'content_data' => $fields,
            'fields' => $fields,
            'taxonomies' => $payload['taxonomies'] ?? ($fields['taxonomies'] ?? []),
            'terms' => $payload['terms'] ?? [],
        ]);
    }
}