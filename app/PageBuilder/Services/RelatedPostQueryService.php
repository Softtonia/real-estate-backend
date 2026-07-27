<?php

namespace App\PageBuilder\Services;

use App\Models\DynamicPost;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class RelatedPostQueryService
{
    private string $postsTable = 'dynamic_posts';
    private string $taxonomyTermsTable = 'taxonomy_terms';
    private string $taxonomiesTable = 'taxonomies';
    private string $customFieldsTable = 'custom_fields';
    private string $customFieldValuesTable = 'custom_field_values';
    private string $pivotTable = 'post_taxonomy_terms';

    private array $locationColumns = [
        'country_id',
        'state_id',
        'city_id',
        'area_locality',
    ];

    public function getRelatedPosts(
        array $settings,
        ?DynamicPost $currentPost = null,
        array $context = []
    ): Collection {
        if (! Schema::hasTable($this->postsTable)) {
            throw new RuntimeException('dynamic_posts table not found.');
        }

        $settings = $this->normalizeSettings($settings);
        $selectedPostIds = $this->normalizeIds($settings['selected_post_ids'] ?? []);

        if (! empty($selectedPostIds)) {
            return $this->getSelectedPosts($selectedPostIds, $settings);
        }

        return $this->getMatchedPosts($settings, $currentPost, $context)
            ->limit((int) ($settings['posts_per_page'] ?? 6))
            ->get();
    }

    public function getCandidatePosts(
        array $settings,
        ?DynamicPost $currentPost = null,
        array $context = []
    ): Collection {
        if (! Schema::hasTable($this->postsTable)) {
            throw new RuntimeException('dynamic_posts table not found.');
        }

        $settings = $this->normalizeSettings($settings);

        return $this->getMatchedPosts($settings, $currentPost, $context)
            ->limit(100)
            ->get()
            ->map(fn ($post) => $this->formatCandidatePost($post))
            ->values();
    }

    public function normalizeSettings(array $settings): array
    {
        $queryMapping = $settings['query_mapping'] ?? [];
        $oldQuery = $settings['query'] ?? [];

        return [
            'title' => $settings['title'] ?? 'Related Posts',

            'exclude_current' => $this->boolSetting(
                $settings,
                'exclude_current',
                null,
                true
            ),

            'match_post_type' => $this->boolSetting(
                $settings,
                'match_post_type',
                'post_type_mapping.enabled',
                true
            ),

            'match_taxonomy_terms' => $this->boolSetting(
                $settings,
                'match_taxonomy_terms',
                'taxonomy_mapping.enabled',
                true
            ),

            'match_locations' => $this->boolSetting(
                $settings,
                'match_locations',
                'location_mapping.enabled',
                true
            ),

            'posts_per_page' => max(1, min((int) ($settings['posts_per_page'] ?? 6), 100)),

            'selected_post_ids' => $this->normalizeIds($settings['selected_post_ids'] ?? []),

            'post_type_mapping' => [
                'enabled' => $this->boolSetting($settings, 'match_post_type', 'post_type_mapping.enabled', true),
                'target' => data_get($settings, 'post_type_mapping.target', 'same_post_type'),
            ],

            'taxonomy_mapping' => [
                'enabled' => $this->boolSetting($settings, 'match_taxonomy_terms', 'taxonomy_mapping.enabled', true),
                'relation' => strtoupper((string) data_get($settings, 'taxonomy_mapping.relation', 'AND')),
                'items' => data_get($settings, 'taxonomy_mapping.items', []),
            ],

            'location_mapping' => [
                'enabled' => $this->boolSetting($settings, 'match_locations', 'location_mapping.enabled', true),
                'relation' => strtoupper((string) data_get($settings, 'location_mapping.relation', 'AND')),
                'items' => data_get($settings, 'location_mapping.items', []),
            ],

            'query' => $this->normalizeBuilderQuery(! empty($queryMapping) ? $queryMapping : $oldQuery),

            'orderby' => $settings['orderby'] ?? 'created_at',
            'order' => strtoupper((string) ($settings['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',

            'status' => $settings['status'] ?? null,
            'live_status' => $settings['live_status'] ?? null,
        ];
    }

    private function getMatchedPosts(
        array $settings,
        ?DynamicPost $currentPost = null,
        array $context = []
    ): Builder {
        $currentPostId = $currentPost?->id
            ?? data_get($context, 'entity_id')
            ?? data_get($context, 'current_post_id')
            ?? data_get($context, 'dynamic_post_id')
            ?? data_get($context, 'post_id');

        if (! $currentPost && $currentPostId) {
            $currentPost = DynamicPost::query()->find((int) $currentPostId);
        }

        $currentPostTypeId = $currentPost?->post_type_id
            ?? data_get($context, 'post_type_id');

        $selectedTaxonomyTermIds = $this->normalizeIds(
            data_get($context, 'selected_taxonomy_term_ids', [])
        );

        if (
            ($settings['match_taxonomy_terms'] ?? true)
            && empty($selectedTaxonomyTermIds)
            && $currentPostId
        ) {
            $selectedTaxonomyTermIds = $this->currentPostTermIds((int) $currentPostId);
        }

        $query = DB::table($this->postsTable . ' as dp')
            ->select('dp.*')
            ->distinct();

        $this->applyStatus($query, $settings);
        $this->applyLiveStatus($query, $settings);

        if (($settings['exclude_current'] ?? true) && $currentPostId) {
            $query->where('dp.id', '!=', (int) $currentPostId);
        }

        if (($settings['match_post_type'] ?? true) && $currentPostTypeId) {
            $query->where('dp.post_type_id', (int) $currentPostTypeId);
        }

        if (($settings['match_taxonomy_terms'] ?? true) && ! empty($selectedTaxonomyTermIds)) {
            $this->applyTaxonomyTermFilter($query, $selectedTaxonomyTermIds);
        }

        if (($settings['match_locations'] ?? true) && $currentPost) {
            $this->applyCurrentPostLocationMatch($query, $currentPost);
        }

        $this->applyBuilderQuery(
            query: $query,
            builderQuery: $settings['query'] ?? [],
            currentPost: $currentPost
        );

        $this->applyOrder($query, $settings);

        return $query;
    }

    private function getSelectedPosts(array $selectedPostIds, array $settings): Collection
    {
        $query = DB::table($this->postsTable . ' as dp')
            ->select('dp.*')
            ->whereIn('dp.id', $selectedPostIds);

        $this->applyStatus($query, $settings);
        $this->applyLiveStatus($query, $settings);

        $posts = $query->get();

        $limit = max(1, min((int) ($settings['posts_per_page'] ?? 6), 100));

        return $posts
            ->sortBy(fn ($post) => array_search((int) $post->id, $selectedPostIds, true))
            ->take($limit)
            ->values();
    }

    private function boolSetting(
        array $settings,
        string $directKey,
        ?string $fallbackPath = null,
        bool $default = true
    ): bool {
        if (array_key_exists($directKey, $settings)) {
            return $this->toBoolean($settings[$directKey], $default);
        }

        if ($fallbackPath !== null) {
            $value = data_get($settings, $fallbackPath);

            if ($value !== null) {
                return $this->toBoolean($value, $default);
            }
        }

        return $default;
    }

    private function toBoolean(mixed $value, bool $default = true): bool
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $bool ?? $default;
    }

    private function normalizeBuilderQuery(array $query): array
    {
        $relation = strtoupper((string) ($query['relation'] ?? 'AND'));

        $items = $query['items']
            ?? $query['rules']
            ?? [];

        $rules = collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $sourceType = $item['source_type'] ?? 'manual';

                $targetKey = $this->firstFilled($item, [
                    'target_key',
                    'key',
                    'field',
                    'source_key',
                ]);

                if (! $targetKey) {
                    return null;
                }

                $compare = strtoupper((string) ($item['compare'] ?? '='));

                return [
                    'type' => $item['type'] ?? 'custom_field',
                    'field' => $this->cleanFieldKey($targetKey),
                    'key' => $this->cleanFieldKey($targetKey),
                    'compare' => $compare,
                    'value' => $sourceType === 'current_post_field'
                        ? null
                        : ($item['manual_value'] ?? $item['value'] ?? null),
                    'source_type' => $sourceType,
                    'source_key' => isset($item['source_key'])
                        ? $this->cleanFieldKey((string) $item['source_key'])
                        : $this->cleanFieldKey($targetKey),
                    'value_type' => strtoupper((string) ($item['value_type'] ?? 'CHAR')),
                ];
            })
            ->filter()
            ->values()
            ->toArray();

        return [
            'relation' => in_array($relation, ['AND', 'OR'], true) ? $relation : 'AND',
            'rules' => $rules,
        ];
    }

    private function firstFilled(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function cleanFieldKey(string $key): string
    {
        return trim(str_replace(['custom.', 'custom_field.'], '', $key));
    }

    private function applyStatus(Builder $query, array $settings): void
    {
        if (! Schema::hasColumn($this->postsTable, 'status')) {
            return;
        }

        $status = $settings['status'] ?? null;

        if ($status && ! in_array($status, ['all', '*'], true)) {
            $query->where('dp.status', $status);
            return;
        }

        $query->where(function ($q) {
            $q->whereNull('dp.status')
                ->orWhere('dp.status', '')
                ->orWhereIn('dp.status', [
                    'published',
                    'active',
                    'draft',
                    'pending',
                    'submit',
                    '1',
                    1,
                    true,
                ]);
        });
    }

    private function applyLiveStatus(Builder $query, array $settings): void
    {
        if (! Schema::hasColumn($this->postsTable, 'live_status')) {
            return;
        }

        $liveStatus = $settings['live_status'] ?? null;

        if ($liveStatus && ! in_array($liveStatus, ['all', '*'], true)) {
            $query->where('dp.live_status', $liveStatus);
            return;
        }

        $query->where(function ($q) {
            $q->whereNull('dp.live_status')
                ->orWhere('dp.live_status', '')
                ->orWhereIn('dp.live_status', [
                    'approve',
                    'approved',
                    'submit',
                    'pending',
                    'draft',
                ]);
        });
    }

    private function applyTaxonomyTermFilter(Builder $query, array $termIds): void
    {
        if (
            ! Schema::hasTable($this->pivotTable)
            || ! Schema::hasColumn($this->pivotTable, 'dynamic_post_id')
            || ! Schema::hasColumn($this->pivotTable, 'taxonomy_term_id')
        ) {
            return;
        }

        $groups = $this->groupTermIdsByTaxonomy($termIds);

        if ($groups->isEmpty()) {
            return;
        }

        foreach ($groups as $groupTermIds) {
            $query->whereExists($this->termExistsQuery($groupTermIds));
        }
    }

    private function currentPostTermIds(int $postId): array
    {
        if (
            ! Schema::hasTable($this->pivotTable)
            || ! Schema::hasColumn($this->pivotTable, 'dynamic_post_id')
            || ! Schema::hasColumn($this->pivotTable, 'taxonomy_term_id')
        ) {
            return [];
        }

        return DB::table($this->pivotTable)
            ->where('dynamic_post_id', $postId)
            ->pluck('taxonomy_term_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function groupTermIdsByTaxonomy(array $termIds): Collection
    {
        $termIds = $this->normalizeIds($termIds);

        if (empty($termIds)) {
            return collect();
        }

        if (
            ! Schema::hasTable($this->taxonomyTermsTable)
            || ! Schema::hasColumn($this->taxonomyTermsTable, 'taxonomy_id')
        ) {
            return collect([
                $termIds,
            ]);
        }

        return DB::table($this->taxonomyTermsTable)
            ->whereIn('id', $termIds)
            ->select('id', 'taxonomy_id')
            ->get()
            ->groupBy('taxonomy_id')
            ->map(function ($rows) {
                return $rows->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->toArray();
            })
            ->filter(fn ($ids) => ! empty($ids))
            ->values();
    }

    private function termExistsQuery(array $termIds): \Closure
    {
        $termIds = $this->normalizeIds($termIds);

        return function ($sub) use ($termIds) {
            $sub->select(DB::raw(1))
                ->from($this->pivotTable . ' as ptt')
                ->whereColumn('ptt.dynamic_post_id', 'dp.id')
                ->whereIn('ptt.taxonomy_term_id', $termIds);
        };
    }

    private function applyCurrentPostLocationMatch(Builder $query, DynamicPost $currentPost): void
    {
        foreach ($this->locationColumns as $column) {
            if (! Schema::hasColumn($this->postsTable, $column)) {
                continue;
            }

            $value = $currentPost->{$column} ?? null;

            if ($value !== null && $value !== '') {
                $query->where('dp.' . $column, $value);
            }
        }
    }

    private function applyBuilderQuery(
        Builder $query,
        array $builderQuery,
        ?DynamicPost $currentPost
    ): void {
        $relation = strtoupper((string) ($builderQuery['relation'] ?? 'AND'));
        $rules = $builderQuery['rules'] ?? $builderQuery['items'] ?? [];

        if (! is_array($rules) || empty($rules)) {
            return;
        }

        $query->where(function ($mainQuery) use ($rules, $relation, $currentPost) {
            foreach ($rules as $index => $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $callback = function ($innerQuery) use ($rule, $currentPost) {
                    $type = strtolower((string) ($rule['type'] ?? 'custom_field'));

                    if ($type === 'location') {
                        $this->applyLocationRule($innerQuery, $rule, $currentPost);
                        return;
                    }

                    if ($type === 'taxonomy') {
                        $this->applyManualTaxonomyRule($innerQuery, $rule);
                        return;
                    }

                    $this->applyCustomFieldOrPostColumnRule($innerQuery, $rule, $currentPost);
                };

                if ($index > 0 && $relation === 'OR') {
                    $mainQuery->orWhere($callback);
                } else {
                    $mainQuery->where($callback);
                }
            }
        });
    }

    private function applyLocationRule($query, array $rule, ?DynamicPost $currentPost): void
    {
        $column = $rule['column'] ?? $rule['field'] ?? $rule['key'] ?? null;

        if (! $column || ! Schema::hasColumn($this->postsTable, (string) $column)) {
            return;
        }

        $value = $rule['value'] ?? null;

        if (($rule['source_type'] ?? null) === 'current_post_field' && $currentPost) {
            $value = $currentPost->{$column} ?? null;
        }

        if ($value === null || $value === '') {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('dp.' . $column, $value);
    }

    private function applyManualTaxonomyRule($query, array $rule): void
    {
        $termIds = $this->normalizeIds($rule['value'] ?? $rule['terms'] ?? []);

        if (empty($termIds)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereExists($this->termExistsQuery($termIds));
    }

    private function applyCustomFieldOrPostColumnRule($query, array $rule, ?DynamicPost $currentPost): void
    {
        $fieldKey = $this->firstFilled($rule, ['key', 'field']);

        if (! $fieldKey) {
            return;
        }

        $fieldKey = $this->cleanFieldKey($fieldKey);

        $compare = strtoupper((string) ($rule['compare'] ?? '='));
        $valueType = strtoupper((string) ($rule['value_type'] ?? 'CHAR'));

        $value = $rule['value'] ?? null;

        if (($rule['source_type'] ?? null) === 'current_post_field') {
            $sourceKey = $this->cleanFieldKey((string) ($rule['source_key'] ?? $fieldKey));

            if ($currentPost) {
                $value = $this->getCurrentPostFieldValue((int) $currentPost->id, $sourceKey);
            }
        }

        if ($value === null || $value === '') {
            $query->whereRaw('1 = 0');
            return;
        }

        if (Schema::hasColumn($this->postsTable, $fieldKey)) {
            $this->applyColumnCompare($query, 'dp.' . $fieldKey, $value, $compare, $valueType);
            return;
        }

        $this->applyCustomFieldRule($query, $fieldKey, $value, $compare, $valueType);
    }

    private function applyCustomFieldRule(
        $query,
        string $fieldKey,
        mixed $value,
        string $compare,
        string $valueType
    ): void {
        if (
            ! Schema::hasTable($this->customFieldsTable)
            || ! Schema::hasTable($this->customFieldValuesTable)
        ) {
            $query->whereRaw('1 = 0');
            return;
        }

        $postColumn = $this->customFieldPostColumn();
        $fieldColumn = $this->customFieldIdColumn();

        if (! $postColumn || ! $fieldColumn) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereExists(function ($sub) use (
            $fieldKey,
            $value,
            $compare,
            $valueType,
            $postColumn,
            $fieldColumn
        ) {
            $sub->select(DB::raw(1))
                ->from($this->customFieldValuesTable . ' as cfv')
                ->join($this->customFieldsTable . ' as cf', 'cf.id', '=', 'cfv.' . $fieldColumn)
                ->whereColumn('cfv.' . $postColumn, 'dp.id');

            if (Schema::hasColumn($this->customFieldValuesTable, 'entity_type')) {
                $sub->where('cfv.entity_type', 'post');
            }

            $this->applyCustomFieldNameFilter($sub, $fieldKey);
            $this->applyCustomFieldValueCompare($sub, $value, $compare, $valueType);
        });
    }

    private function applyCustomFieldNameFilter($query, string $fieldKey): void
    {
        $fieldKey = trim($fieldKey);
        $slug = Str::slug($fieldKey);
        $snake = str_replace('-', '_', $slug);
        $lower = mb_strtolower($fieldKey);

        $query->where(function ($fieldQuery) use ($fieldKey, $slug, $snake, $lower) {
            $hasCondition = false;

            foreach (['field_name_slug', 'slug', 'key', 'field_key', 'name'] as $column) {
                if (Schema::hasColumn($this->customFieldsTable, $column)) {
                    $method = $hasCondition ? 'orWhereIn' : 'whereIn';
                    $fieldQuery->{$method}('cf.' . $column, array_unique([
                        $fieldKey,
                        $slug,
                        $snake,
                    ]));
                    $hasCondition = true;
                }
            }

            if (Schema::hasColumn($this->customFieldsTable, 'field_label')) {
                if ($hasCondition) {
                    $fieldQuery->orWhereRaw('LOWER(cf.field_label) = ?', [$lower]);
                } else {
                    $fieldQuery->whereRaw('LOWER(cf.field_label) = ?', [$lower]);
                    $hasCondition = true;
                }
            }

            if (! $hasCondition) {
                $fieldQuery->whereRaw('1 = 0');
            }
        });
    }

    private function applyCustomFieldValueCompare(
        $query,
        mixed $value,
        string $compare,
        string $valueType
    ): void {
        $this->applyColumnCompare(
            $query,
            $this->customFieldValueExpression($valueType),
            $value,
            $compare,
            $valueType,
            true
        );
    }

    private function applyColumnCompare(
        $query,
        string $columnExpression,
        mixed $value,
        string $compare,
        string $valueType,
        bool $isRawExpression = false
    ): void {
        $compare = strtoupper($compare === '==' ? '=' : $compare);
        $valueType = strtoupper($valueType);

        $allowed = [
            '=',
            '!=',
            '<>',
            '>',
            '>=',
            '<',
            '<=',
            'LIKE',
            'NOT LIKE',
            'IN',
            'NOT IN',
            'BETWEEN',
            'NOT BETWEEN',
        ];

        if (! in_array($compare, $allowed, true)) {
            $compare = '=';
        }

        $expr = $isRawExpression
            ? $columnExpression
            : $this->castColumnExpression($columnExpression, $valueType);

        if (in_array($compare, ['LIKE', 'NOT LIKE'], true)) {
            $query->whereRaw($expr . ' ' . $compare . ' ?', ['%' . (string) $value . '%']);
            return;
        }

        if (in_array($compare, ['IN', 'NOT IN'], true)) {
            $values = $this->normalizeListValues($value);

            if (empty($values)) {
                $query->whereRaw($compare === 'IN' ? '1 = 0' : '1 = 1');
                return;
            }

            if ($this->isNumericValueType($valueType)) {
                $values = array_map('floatval', $values);
            }

            $placeholders = implode(',', array_fill(0, count($values), '?'));

            $query->whereRaw($expr . ' ' . $compare . ' (' . $placeholders . ')', $values);
            return;
        }

        if (in_array($compare, ['BETWEEN', 'NOT BETWEEN'], true)) {
            $values = $this->normalizeBetweenValues($value);

            if (count($values) !== 2) {
                $query->whereRaw('1 = 0');
                return;
            }

            if ($this->isNumericValueType($valueType)) {
                $values = [
                    (float) $values[0],
                    (float) $values[1],
                ];
            }

            $query->whereRaw($expr . ' ' . $compare . ' ? AND ?', [$values[0], $values[1]]);
            return;
        }

        if ($this->isNumericValueType($valueType)) {
            $value = (float) $value;
        }

        $query->whereRaw($expr . ' ' . $compare . ' ?', [$value]);
    }

    private function castColumnExpression(string $column, string $valueType): string
    {
        $valueType = strtoupper($valueType);

        if ($this->isNumericValueType($valueType)) {
            return 'CAST(' . $column . ' AS DECIMAL(15,4))';
        }

        if ($valueType === 'DATE') {
            return 'DATE(' . $column . ')';
        }

        if ($valueType === 'DATETIME') {
            return 'CAST(' . $column . ' AS DATETIME)';
        }

        return $column;
    }

    private function customFieldValueExpression(string $valueType): string
    {
        $valueType = strtoupper($valueType);

        if ($this->isNumericValueType($valueType)) {
            $columns = [];

            if (Schema::hasColumn($this->customFieldValuesTable, 'value_number')) {
                $columns[] = "NULLIF(CAST(cfv.value_number AS CHAR), '')";
            }

            foreach (['value_string', 'value_text', 'value', 'field_meta_value', 'meta_value', 'field_value'] as $column) {
                if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                    $columns[] = "NULLIF(cfv.{$column}, '')";
                }
            }

            if (empty($columns)) {
                return 'NULL';
            }

            return 'CAST(COALESCE(' . implode(', ', $columns) . ') AS DECIMAL(15,4))';
        }

        if ($valueType === 'DATE') {
            if (Schema::hasColumn($this->customFieldValuesTable, 'value_date')) {
                return 'cfv.value_date';
            }

            foreach (['value_string', 'value_text', 'value', 'field_meta_value', 'meta_value', 'field_value'] as $column) {
                if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                    return "NULLIF(cfv.{$column}, '')";
                }
            }

            return 'NULL';
        }

        if ($valueType === 'DATETIME') {
            if (Schema::hasColumn($this->customFieldValuesTable, 'value_datetime')) {
                return 'cfv.value_datetime';
            }

            foreach (['value_string', 'value_text', 'value', 'field_meta_value', 'meta_value', 'field_value'] as $column) {
                if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                    return "NULLIF(cfv.{$column}, '')";
                }
            }

            return 'NULL';
        }

        $columns = [];

        foreach (['value_string', 'value_text', 'value', 'field_meta_value', 'meta_value', 'field_value'] as $column) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                $columns[] = "NULLIF(cfv.{$column}, '')";
            }
        }

        if (Schema::hasColumn($this->customFieldValuesTable, 'value_number')) {
            $columns[] = 'CAST(cfv.value_number AS CHAR)';
        }

        if (Schema::hasColumn($this->customFieldValuesTable, 'value_date')) {
            $columns[] = 'CAST(cfv.value_date AS CHAR)';
        }

        if (Schema::hasColumn($this->customFieldValuesTable, 'value_datetime')) {
            $columns[] = 'CAST(cfv.value_datetime AS CHAR)';
        }

        return empty($columns)
            ? 'NULL'
            : 'COALESCE(' . implode(', ', $columns) . ')';
    }

    private function getCurrentPostFieldValue(int $postId, string $fieldKey): mixed
    {
        if (Schema::hasColumn($this->postsTable, $fieldKey)) {
            return DB::table($this->postsTable)
                ->where('id', $postId)
                ->value($fieldKey);
        }

        if (
            ! Schema::hasTable($this->customFieldsTable)
            || ! Schema::hasTable($this->customFieldValuesTable)
        ) {
            return null;
        }

        $postColumn = $this->customFieldPostColumn();
        $fieldColumn = $this->customFieldIdColumn();

        if (! $postColumn || ! $fieldColumn) {
            return null;
        }

        $query = DB::table($this->customFieldValuesTable . ' as cfv')
            ->join($this->customFieldsTable . ' as cf', 'cf.id', '=', 'cfv.' . $fieldColumn)
            ->where('cfv.' . $postColumn, $postId);

        if (Schema::hasColumn($this->customFieldValuesTable, 'entity_type')) {
            $query->where('cfv.entity_type', 'post');
        }

        $this->applyCustomFieldNameFilter($query, $fieldKey);

        foreach (['value_number', 'value_string', 'value_text', 'value', 'field_meta_value', 'meta_value', 'field_value'] as $column) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                $value = $query->value('cfv.' . $column);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function customFieldPostColumn(): ?string
    {
        foreach (['entity_id', 'dynamic_post_id', 'post_id'] as $column) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function customFieldIdColumn(): ?string
    {
        foreach (['custom_field_id', 'field_id'] as $column) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function isNumericValueType(string $valueType): bool
    {
        return in_array(strtoupper($valueType), ['NUMERIC', 'NUMBER', 'INTEGER', 'DECIMAL'], true);
    }

    private function applyOrder(Builder $query, array $settings): void
    {
        $orderby = (string) ($settings['orderby'] ?? 'created_at');
        $order = strtoupper((string) ($settings['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        if ($orderby === 'rand') {
            $query->inRandomOrder();
            return;
        }

        $allowed = ['sort_order', 'created_at', 'updated_at', 'title', 'id'];

        if (! in_array($orderby, $allowed, true)) {
            $orderby = 'created_at';
        }

        if (! Schema::hasColumn($this->postsTable, $orderby)) {
            $orderby = Schema::hasColumn($this->postsTable, 'id') ? 'id' : 'created_at';
        }

        $query->orderBy('dp.' . $orderby, $order);
    }

    private function formatCandidatePost(object $post): array
    {
        return [
            'id' => (int) $post->id,
            'value' => (int) $post->id,
            'label' => $this->candidateLabel($post),

            'title' => $post->title ?? null,
            'slug' => $post->slug ?? null,
            'listing_code' => $post->listing_code ?? null,
            'post_type_id' => isset($post->post_type_id) ? (int) $post->post_type_id : null,

            'country_id' => $post->country_id ?? null,
            'state_id' => $post->state_id ?? null,
            'city_id' => $post->city_id ?? null,
            'area_locality' => $post->area_locality ?? null,

            'status' => $post->status ?? null,
            'live_status' => $post->live_status ?? null,
        ];
    }

    private function candidateLabel(object $post): string
    {
        $title = $post->title
            ?? $post->slug
            ?? ('Post #' . $post->id);

        if (! empty($post->listing_code)) {
            return $post->listing_code . ' - ' . $title;
        }

        return (string) $title;
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

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function normalizeListValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = str_contains($value, ',') ? explode(',', $value) : [$value];
            }
        }

        return collect((array) $value)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->values()
            ->toArray();
    }

    private function normalizeBetweenValues(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_slice($value, 0, 2));
        }

        $value = (string) $value;

        if (str_contains($value, '-')) {
            return array_map('trim', explode('-', $value, 2));
        }

        if (str_contains($value, ',')) {
            return array_map('trim', explode(',', $value, 2));
        }

        return [];
    }
}