<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\PageBuilder\Services\RelatedPostQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RelatedPostsWidgetCandidateController extends Controller
{
    public function __construct(
        protected RelatedPostQueryService $relatedPostQueryService
    ) {}

    public function candidates(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'current_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'dynamic_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'entity_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],

            'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],

            'selected_taxonomy_term_ids' => ['nullable'],
            'selected_post_ids' => ['nullable'],

            'settings' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $currentPostId = $payload['current_post_id']
                ?? $payload['dynamic_post_id']
                ?? $payload['post_id']
                ?? $payload['entity_id']
                ?? null;

            $currentPost = $currentPostId
                ? DynamicPost::find((int) $currentPostId)
                : null;

            $settings = $payload['settings'] ?? [];

            $context = [
                'entity_id' => $payload['entity_id'] ?? $currentPostId,
                'current_post_id' => $currentPostId,
                'post_id' => $currentPostId,
                'dynamic_post_id' => $currentPostId,
                'post_type_id' => $payload['post_type_id'] ?? $currentPost?->post_type_id,
                'selected_taxonomy_term_ids' => $this->normalizeIds($payload['selected_taxonomy_term_ids'] ?? []),
            ];

            $candidates = $this->relatedPostQueryService->getCandidatePosts(
                $settings,
                $currentPost,
                $context
            );

            if ($candidates->isEmpty()) {
                $candidates = $this->fallbackCandidatePosts(
                    $settings,
                    $currentPost,
                    $context
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Matched related posts fetched successfully.',
                'data' => [
                    'current_post_id' => $currentPostId ? (int) $currentPostId : null,
                    'post_type_id' => $context['post_type_id'] ? (int) $context['post_type_id'] : null,
                    'matched_count' => $candidates->count(),
                    'selected_post_ids' => $this->normalizeIds($payload['selected_post_ids'] ?? data_get($settings, 'selected_post_ids', [])),
                    'options' => $candidates,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch matched related posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    private function fallbackCandidatePosts(
        array $settings,
        ?DynamicPost $currentPost,
        array $context
    ): Collection {
        $postTypeId = (int) ($context['post_type_id'] ?? $currentPost?->post_type_id ?? 0);

        $limit = (int) data_get($settings, 'posts_per_page', 20);
        $limit = max(1, min($limit, 100));

        $matchPostType = (bool) data_get($settings, 'match_post_type', true);
        $excludeCurrent = (bool) data_get($settings, 'exclude_current', true);

        $orderby = data_get($settings, 'orderby', 'created_at');
        $allowedOrderBy = ['id', 'title', 'created_at', 'updated_at'];

        if (! in_array($orderby, $allowedOrderBy, true)) {
            $orderby = 'created_at';
        }

        $order = strtoupper((string) data_get($settings, 'order', 'DESC')) === 'ASC'
            ? 'ASC'
            : 'DESC';

        $query = DynamicPost::query()
            ->select([
                'id',
                'post_type_id',
                'title',
                'slug',
                'status',
                'live_status',
                'created_at',
                'updated_at',
            ]);

        if ($matchPostType && $postTypeId > 0) {
            $query->where('post_type_id', $postTypeId);
        }

        if ($excludeCurrent && $currentPost?->id) {
            $query->where('id', '!=', $currentPost->id);
        }

        $this->applyRelatedPostQueryMapping($query, $settings);

        $posts = $query
            ->orderBy($orderby, $order)
            ->limit($limit)
            ->get();

        return $posts->map(fn(DynamicPost $post) => [
            'id' => (int) $post->id,
            'value' => (int) $post->id,
            'label' => trim(($post->title ?: 'Untitled') . ' #' . $post->id),
            'title' => $post->title,
            'slug' => $post->slug,
            'post_type_id' => (int) $post->post_type_id,
            'status' => $post->status,
            'live_status' => $post->live_status,
        ]);
    }

    private function applyRelatedPostQueryMapping(Builder $query, array $settings): void
    {
        $items = data_get($settings, 'query_mapping.items', []);

        if (! is_array($items) || empty($items)) {
            return;
        }

        $items = collect($items)
            ->filter(
                fn($item) =>
                is_array($item)
                    && filled(data_get($item, 'target_key'))
                    && data_get($item, 'manual_value') !== null
                    && data_get($item, 'manual_value') !== ''
            )
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $relation = strtoupper((string) data_get($settings, 'query_mapping.relation', 'AND'));

        $query->where(function (Builder $nested) use ($items, $relation) {
            foreach ($items as $index => $item) {
                $method = $index === 0
                    ? 'where'
                    : ($relation === 'OR' ? 'orWhere' : 'where');

                $nested->{$method}(function (Builder $subQuery) use ($item) {
                    $this->applySingleRelatedPostQueryFilter($subQuery, $item);
                });
            }
        });
    }

    private function applySingleRelatedPostQueryFilter(Builder $query, array $item): void
    {
        $targetKey = (string) data_get($item, 'target_key');
        $compare = strtoupper((string) data_get($item, 'compare', '='));
        $value = data_get($item, 'manual_value');
        $valueType = strtoupper((string) data_get($item, 'value_type', 'CHAR'));

        $allowedCompares = [
            '=',
            '!=',
            '>',
            '>=',
            '<',
            '<=',
            'LIKE',
            'IN',
            'NOT IN',
            'BETWEEN',
            'NOT BETWEEN',
        ];

        if (! in_array($compare, $allowedCompares, true)) {
            $compare = '=';
        }

        if (Schema::hasColumn('dynamic_posts', $targetKey)) {
            $this->applyCompare($query, 'dynamic_posts.' . $targetKey, $compare, $value, $valueType);
            return;
        }

        $this->applyCustomFieldCompare($query, $targetKey, $compare, $value, $valueType);
    }

    private function applyCustomFieldCompare(
        Builder $query,
        string $fieldKey,
        string $compare,
        mixed $value,
        string $valueType
    ): void {
        $table = $this->resolveCustomFieldValueTable();

        if (! $table) {
            $query->whereRaw('1 = 0');
            return;
        }

        $postIdColumn = $this->firstExistingColumn($table, [
            'dynamic_post_id',
            'post_id',
            'entity_id',
        ]);

        $fieldIdColumn = $this->firstExistingColumn($table, [
            'custom_field_id',
            'field_id',
        ]);

        $fieldKeyColumn = $this->firstExistingColumn($table, [
            'field_key',
            'key',
            'slug',
            'field_slug',
        ]);

        $valueColumn = $this->firstExistingColumn($table, [
            'value',
            'field_value',
            'meta_value',
            'content',
        ]);

        if (! $postIdColumn || ! $valueColumn) {
            $query->whereRaw('1 = 0');
            return;
        }

        $customFieldId = $this->resolveCustomFieldId($fieldKey);

        $query->whereExists(function ($exists) use (
            $table,
            $postIdColumn,
            $fieldIdColumn,
            $fieldKeyColumn,
            $valueColumn,
            $customFieldId,
            $fieldKey,
            $compare,
            $value,
            $valueType
        ) {
            $exists
                ->selectRaw('1')
                ->from($table)
                ->whereColumn($table . '.' . $postIdColumn, 'dynamic_posts.id');

            if ($customFieldId && $fieldIdColumn) {
                $exists->where($table . '.' . $fieldIdColumn, $customFieldId);
            } elseif ($fieldKeyColumn) {
                $exists->where($table . '.' . $fieldKeyColumn, $fieldKey);
            } else {
                $exists->whereRaw('1 = 0');
            }

            $this->applyCompare(
                $exists,
                $table . '.' . $valueColumn,
                $compare,
                $value,
                $valueType
            );
        });
    }

    private function applyCompare(
        mixed $query,
        string $column,
        string $compare,
        mixed $value,
        string $valueType
    ): void {
        $values = $this->normalizeQueryValues($value);

        if ($valueType === 'NUMERIC') {
            $numericColumn = 'CAST(' . $column . ' AS DECIMAL(18,4))';

            if (in_array($compare, ['IN', 'NOT IN'], true)) {
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $query->whereRaw(
                    $numericColumn . ' ' . $compare . ' (' . $placeholders . ')',
                    $values
                );
                return;
            }

            if (in_array($compare, ['BETWEEN', 'NOT BETWEEN'], true)) {
                $query->whereRaw(
                    $numericColumn . ' ' . $compare . ' ? AND ?',
                    [$values[0] ?? 0, $values[1] ?? 0]
                );
                return;
            }

            $query->whereRaw($numericColumn . ' ' . $compare . ' ?', [(float) $value]);
            return;
        }

        if ($compare === 'LIKE') {
            $query->where($column, 'LIKE', '%' . $value . '%');
            return;
        }

        if ($compare === 'IN') {
            $query->whereIn($column, $values);
            return;
        }

        if ($compare === 'NOT IN') {
            $query->whereNotIn($column, $values);
            return;
        }

        if ($compare === 'BETWEEN') {
            $query->whereBetween($column, [$values[0] ?? '', $values[1] ?? '']);
            return;
        }

        if ($compare === 'NOT BETWEEN') {
            $query->whereNotBetween($column, [$values[0] ?? '', $values[1] ?? '']);
            return;
        }

        $query->where($column, $compare, $value);
    }

    private function normalizeQueryValues(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn($item) => $item !== null && $item !== ''));
        }

        if (! is_string($value)) {
            return [$value];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter($decoded, fn($item) => $item !== null && $item !== ''));
        }

        if (str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }

        if (str_contains($value, '-')) {
            return array_map('trim', explode('-', $value));
        }

        return [$value];
    }

    private function resolveCustomFieldId(string $fieldKey): ?int
    {
        if (! Schema::hasTable('custom_fields')) {
            return null;
        }

        $columns = collect(['field_key', 'key', 'slug', 'name'])
            ->filter(fn($column) => Schema::hasColumn('custom_fields', $column))
            ->values();

        if ($columns->isEmpty()) {
            return null;
        }

        $query = DB::table('custom_fields');

        $query->where(function ($q) use ($columns, $fieldKey) {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $q->{$method}($column, $fieldKey);
            }
        });

        $id = $query->value('id');

        return $id ? (int) $id : null;
    }

    private function resolveCustomFieldValueTable(): ?string
    {
        $tables = [
            'dynamic_post_custom_field_values',
            'dynamic_post_field_values',
            'custom_field_values',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
    private function getPayload(Request $request): array
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            $payload = $request->all();
        }

        if (empty($payload) && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return is_array($payload) ? $payload : [];
    }

    private function normalizeIds(mixed $ids): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = str_contains($ids, ',') ? explode(',', $ids) : [$ids];
            }
        }

        if (!is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }
    
}
