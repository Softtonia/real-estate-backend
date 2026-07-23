<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\DynamicPost;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private array $locationTaxonomySlugs = [
        'location',
        'locations',
        'country',
        'state',
        'city',
        'area',
        'locality',
        'sector',
        'zone',
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
            ->map(fn($post) => $this->formatCandidatePost($post))
            ->values();
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
            $currentPost = DynamicPost::find((int) $currentPostId);
        }

        $currentPostTypeId = $currentPost?->post_type_id
            ?? data_get($context, 'post_type_id');

        $selectedTaxonomyTermIds = $this->normalizeIds(
            data_get($context, 'selected_taxonomy_term_ids', [])
        );

        if (empty($selectedTaxonomyTermIds) && $currentPostId) {
            $selectedTaxonomyTermIds = $this->currentPostTermIds((int) $currentPostId);
        }

        $query = DB::table($this->postsTable . ' as dp')
            ->select('dp.*')
            ->distinct();

        $this->applyStatus($query, $settings);
        $this->applyLiveStatus($query);

        if (($settings['exclude_current'] ?? true) && $currentPostId) {
            $query->where('dp.id', '!=', (int) $currentPostId);
        }

        if (($settings['match_post_type'] ?? true) && $currentPostTypeId) {
            $query->where('dp.post_type_id', (int) $currentPostTypeId);
        }

        if (($settings['match_taxonomy_terms'] ?? true) && ! empty($selectedTaxonomyTermIds)) {
            $taxonomyGroups = $this->groupTermIdsByTaxonomy(
                termIds: $selectedTaxonomyTermIds,
                onlyLocationTaxonomies: false
            );

            foreach ($taxonomyGroups as $termIds) {
                $query->whereExists($this->termExistsQuery($termIds));
            }
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
        $this->applyLiveStatus($query);

        $posts = $query->get();

        $limit = max(1, min((int) ($settings['posts_per_page'] ?? 6), 50));

        return $posts
            ->sortBy(fn($post) => array_search((int) $post->id, $selectedPostIds, true))
            ->take($limit)
            ->values();
    }

    public function normalizeSettings(array $settings): array
    {
        $queryMapping = $settings['query_mapping'] ?? [];
        $oldQuery = $settings['query'] ?? [];

        return [
            'title' => $settings['title'] ?? 'Related Posts',

            'exclude_current' => filter_var($settings['exclude_current'] ?? true, FILTER_VALIDATE_BOOLEAN),

            'match_post_type' => filter_var(
                data_get($settings, 'post_type_mapping.enabled', $settings['match_post_type'] ?? true),
                FILTER_VALIDATE_BOOLEAN
            ),

            'match_taxonomy_terms' => filter_var(
                data_get($settings, 'taxonomy_mapping.enabled', $settings['match_taxonomy_terms'] ?? true),
                FILTER_VALIDATE_BOOLEAN
            ),

            'match_locations' => filter_var(
                data_get($settings, 'location_mapping.enabled', $settings['match_locations'] ?? true),
                FILTER_VALIDATE_BOOLEAN
            ),

            'posts_per_page' => max(1, min((int) ($settings['posts_per_page'] ?? 6), 50)),

            'selected_post_ids' => $this->normalizeIds($settings['selected_post_ids'] ?? []),

            'post_type_mapping' => [
                'enabled' => filter_var(data_get($settings, 'post_type_mapping.enabled', true), FILTER_VALIDATE_BOOLEAN),
                'target' => data_get($settings, 'post_type_mapping.target', 'same_post_type'),
            ],

            'taxonomy_mapping' => [
                'enabled' => filter_var(data_get($settings, 'taxonomy_mapping.enabled', true), FILTER_VALIDATE_BOOLEAN),
                'relation' => strtoupper((string) data_get($settings, 'taxonomy_mapping.relation', 'AND')),
                'items' => data_get($settings, 'taxonomy_mapping.items', []),
            ],

            'location_mapping' => [
                'enabled' => filter_var(data_get($settings, 'location_mapping.enabled', true), FILTER_VALIDATE_BOOLEAN),
                'relation' => strtoupper((string) data_get($settings, 'location_mapping.relation', 'AND')),
                'items' => data_get($settings, 'location_mapping.items', []),
            ],

            'query' => $this->normalizeBuilderQuery(! empty($queryMapping) ? $queryMapping : $oldQuery),

            'orderby' => $settings['orderby'] ?? 'created_at',
            'order' => strtoupper((string) ($settings['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];
    }

    private function normalizeBuilderQuery(array $query): array
    {
        $relation = strtoupper((string) ($query['relation'] ?? 'AND'));

        $items = $query['items']
            ?? $query['rules']
            ?? [];

        $rules = collect($items)
            ->filter(fn($item) => is_array($item))
            ->map(function (array $item) {
                $sourceType = $item['source_type'] ?? 'manual';

                $targetKey = $item['target_key']
                    ?? $item['key']
                    ?? $item['field']
                    ?? null;

                if (! $targetKey) {
                    return null;
                }

                return [
                    'type' => 'custom_field',
                    'field' => $targetKey,
                    'key' => $targetKey,
                    'compare' => $item['compare'] ?? '=',
                    'value' => $sourceType === 'current_post_field'
                        ? null
                        : ($item['manual_value'] ?? $item['value'] ?? null),
                    'source_type' => $sourceType,
                    'source_key' => $item['source_key'] ?? null,
                    'value_type' => $item['value_type'] ?? 'CHAR',
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

    private function applyStatus(Builder $query, array $settings): void
    {
        if (! Schema::hasColumn($this->postsTable, 'status')) {
            return;
        }

        if (! empty($settings['status'])) {
            $query->where('dp.status', $settings['status']);
            return;
        }

        $query->where(function ($q) {
            $q->where('dp.status', 'published')
                ->orWhere('dp.status', 'active')
                ->orWhere('dp.status', true)
                ->orWhere('dp.status', 1)
                ->orWhere('dp.status', '1');
        });
    }

    private function applyLiveStatus(Builder $query): void
    {
        if (! Schema::hasColumn($this->postsTable, 'live_status')) {
            return;
        }

        $query->where(function ($q) {
            $q->where('dp.live_status', 'approve')
                ->orWhereNull('dp.live_status')
                ->orWhere('dp.live_status', '');
        });
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

    private function currentPostTermIds(int $postId): array
    {
        if (! Schema::hasTable($this->pivotTable)) {
            return [];
        }

        if (
            ! Schema::hasColumn($this->pivotTable, 'dynamic_post_id')
            || ! Schema::hasColumn($this->pivotTable, 'taxonomy_term_id')
        ) {
            return [];
        }

        return DB::table($this->pivotTable)
            ->where('dynamic_post_id', $postId)
            ->pluck('taxonomy_term_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function currentPostTermIdsByTaxonomy(int $postId, int $taxonomyId): array
    {
        if (! Schema::hasTable($this->pivotTable)) {
            return [];
        }

        if (! Schema::hasColumn($this->pivotTable, 'taxonomy_id')) {
            return [];
        }

        return DB::table($this->pivotTable)
            ->where('dynamic_post_id', $postId)
            ->where('taxonomy_id', $taxonomyId)
            ->pluck('taxonomy_term_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function groupTermIdsByTaxonomy(
        array $termIds,
        bool $onlyLocationTaxonomies = false
    ): Collection {
        if (
            ! Schema::hasTable($this->taxonomyTermsTable)
            || ! Schema::hasTable($this->taxonomiesTable)
        ) {
            return collect();
        }

        $termIds = $this->normalizeIds($termIds);

        if (empty($termIds)) {
            return collect();
        }

        $query = DB::table($this->taxonomyTermsTable . ' as tt')
            ->join($this->taxonomiesTable . ' as tx', 'tx.id', '=', 'tt.taxonomy_id')
            ->whereIn('tt.id', $termIds)
            ->select('tt.id', 'tt.taxonomy_id', 'tx.slug as taxonomy_slug');

        if ($onlyLocationTaxonomies) {
            $query->whereIn('tx.slug', $this->locationTaxonomySlugs);
        } else {
            $query->whereNotIn('tx.slug', $this->locationTaxonomySlugs);
        }

        return $query->get()
            ->groupBy('taxonomy_id')
            ->map(function ($rows) {
                return $rows->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->toArray();
            })
            ->filter(fn($ids) => ! empty($ids));
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
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $callback = function ($innerQuery) use ($rule, $currentPost) {
                    $type = strtolower((string) ($rule['type'] ?? 'custom_field'));

                    if ($type === 'taxonomy') {
                        $this->applyTaxonomyRule($innerQuery, $rule, $currentPost);
                        return;
                    }

                    if ($type === 'location') {
                        $this->applyLocationRule($innerQuery, $rule, $currentPost);
                        return;
                    }

                    $this->applyCustomFieldRule($innerQuery, $rule, $currentPost);
                };

                if ($relation === 'OR') {
                    $mainQuery->orWhere($callback);
                } else {
                    $mainQuery->where($callback);
                }
            }
        });
    }

    private function applyTaxonomyRule($query, array $rule, ?DynamicPost $currentPost): void
    {
        $taxonomy = $this->resolveTaxonomy($rule['taxonomy'] ?? null);

        if (! $taxonomy) {
            return;
        }

        $operator = strtoupper((string) ($rule['operator'] ?? 'IN'));
        $source = $rule['source'] ?? $rule['terms_from'] ?? 'manual';

        if ($source === 'current_post') {
            $termIds = $currentPost
                ? $this->currentPostTermIdsByTaxonomy((int) $currentPost->id, (int) $taxonomy->id)
                : [];
        } else {
            $termIds = $this->resolveTermIds(
                $rule['terms'] ?? $rule['value'] ?? [],
                (int) $taxonomy->id
            );
        }

        if (empty($termIds)) {
            if ($operator !== 'NOT IN') {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($operator === 'AND') {
            foreach ($termIds as $termId) {
                $query->whereExists($this->termExistsQuery([(int) $termId]));
            }

            return;
        }

        if ($operator === 'NOT IN') {
            $query->whereNotExists($this->termExistsQuery($termIds));
            return;
        }

        $query->whereExists($this->termExistsQuery($termIds));
    }

    private function applyLocationRule($query, array $rule, ?DynamicPost $currentPost): void
    {
        $source = $rule['source'] ?? 'manual';

        if ($source === 'current_post') {
            if ($currentPost) {
                $this->applyCurrentPostLocationMatch($query, $currentPost);
            }

            return;
        }

        $column = $rule['column'] ?? null;

        if ($column && Schema::hasColumn($this->postsTable, (string) $column)) {
            $query->where('dp.' . $column, $rule['value'] ?? null);
        }
    }

    private function applyCustomFieldRule($query, array $rule, ?DynamicPost $currentPost = null): void
    {
        if (
            ! Schema::hasTable($this->customFieldsTable)
            || ! Schema::hasTable($this->customFieldValuesTable)
        ) {
            return;
        }

        $fieldKey = $rule['key'] ?? $rule['field'] ?? null;

        if (! $fieldKey) {
            return;
        }

        $compare = strtoupper((string) ($rule['compare'] ?? '='));
        $valueType = strtoupper((string) ($rule['value_type'] ?? 'CHAR'));

        $value = $rule['value'] ?? null;

        if (($rule['source_type'] ?? null) === 'current_post_field') {
            $sourceKey = $rule['source_key'] ?? null;

            if ($sourceKey && $currentPost) {
                $value = $this->getCurrentPostCustomFieldValue((int) $currentPost->id, (string) $sourceKey);
            }
        }

        if ($compare === 'NOT EXISTS') {
            $query->whereNotExists($this->customFieldBaseExistsQuery((string) $fieldKey));
            return;
        }

        if ($compare === 'EXISTS') {
            $query->whereExists($this->customFieldBaseExistsQuery((string) $fieldKey));
            return;
        }

        if ($value === null || $value === '') {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereExists(
            $this->customFieldCompareExistsQuery(
                (string) $fieldKey,
                $value,
                $compare,
                $valueType
            )
        );
    }
    private function getCurrentPostCustomFieldValue(int $postId, string $fieldKey): mixed
    {
        $postColumn = $this->customFieldPostColumn();
        $fieldColumn = $this->customFieldIdColumn();

        if (! $postColumn || ! $fieldColumn) {
            return null;
        }

        $valueColumn = $this->customFieldValueColumn();

        if (! $valueColumn) {
            return null;
        }

        $row = DB::table($this->customFieldValuesTable . ' as cfv')
            ->join($this->customFieldsTable . ' as cf', 'cf.id', '=', 'cfv.' . $fieldColumn)
            ->where('cfv.' . $postColumn, $postId)
            ->where(function ($query) use ($fieldKey) {
                if (Schema::hasColumn($this->customFieldsTable, 'field_name_slug')) {
                    $query->where('cf.field_name_slug', $fieldKey);
                }

                if (Schema::hasColumn($this->customFieldsTable, 'field_label')) {
                    $query->orWhere('cf.field_label', $fieldKey);
                }

                if (Schema::hasColumn($this->customFieldsTable, 'name')) {
                    $query->orWhere('cf.name', $fieldKey);
                }
            })
            ->select('cfv.' . $valueColumn . ' as field_value')
            ->first();

        return $row->field_value ?? null;
    }

    private function customFieldValueColumn(): ?string
    {
        foreach (
            [
                'value',
                'field_value',
                'meta_value',
                'value_text',
                'value_string',
                'value_number',
            ] as $column
        ) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                return $column;
            }
        }

        return null;
    }
private function customFieldBaseExistsQuery(string $fieldKey): \Closure
{
    return function ($sub) use ($fieldKey) {
        $postColumn = $this->customFieldPostColumn();
        $fieldColumn = $this->customFieldIdColumn();

        if (! $postColumn || ! $fieldColumn) {
            $sub->select(DB::raw(1))->whereRaw('1 = 0');
            return;
        }

        $sub->select(DB::raw(1))
            ->from($this->customFieldValuesTable . ' as cfv')
            ->join($this->customFieldsTable . ' as cf', 'cf.id', '=', 'cfv.' . $fieldColumn)
            ->whereColumn('cfv.' . $postColumn, 'dp.id')
            ->where(function ($fieldQuery) use ($fieldKey) {
                if (Schema::hasColumn($this->customFieldsTable, 'field_name_slug')) {
                    $fieldQuery->where('cf.field_name_slug', $fieldKey);
                }

                if (Schema::hasColumn($this->customFieldsTable, 'field_label')) {
                    $fieldQuery->orWhere('cf.field_label', $fieldKey);
                }

                if (Schema::hasColumn($this->customFieldsTable, 'name')) {
                    $fieldQuery->orWhere('cf.name', $fieldKey);
                }
            });

        if (Schema::hasColumn($this->customFieldValuesTable, 'entity_type')) {
            $sub->where('cfv.entity_type', 'post');
        }
    };
}

    private function customFieldCompareExistsQuery(
        string $fieldKey,
        mixed $value,
        string $compare,
        string $valueType
    ): \Closure {
        return function ($sub) use ($fieldKey, $value, $compare, $valueType) {
            $base = $this->customFieldBaseExistsQuery($fieldKey);
            $base($sub);

            $this->applyCustomFieldValueCompare($sub, $value, $compare, $valueType);
        };
    }

    private function applyCustomFieldValueCompare(
        $query,
        mixed $value,
        string $compare,
        string $valueType
    ): void {
        $compare = $compare === '==' ? '=' : $compare;

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

        $expr = $this->customFieldValueExpression($valueType);

        if ($compare === 'LIKE') {
            $query->whereRaw($expr . ' LIKE ?', ['%' . (string) $value . '%']);
            return;
        }

        if ($compare === 'NOT LIKE') {
            $query->whereRaw($expr . ' NOT LIKE ?', ['%' . (string) $value . '%']);
            return;
        }

        if (in_array($compare, ['IN', 'NOT IN'], true)) {
            $values = $this->normalizeListValues($value);

            if (empty($values)) {
                $query->whereRaw($compare === 'IN' ? '1 = 0' : '1 = 1');
                return;
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

            $query->whereRaw($expr . ' ' . $compare . ' ? AND ?', [$values[0], $values[1]]);
            return;
        }

        $query->whereRaw($expr . ' ' . $compare . ' ?', [$value]);
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

    private function customFieldValueExpression(string $valueType): string
    {
        $columns = [];

        foreach (
            [
                'value_number',
                'value_string',
                'value_text',
                'value_date',
                'value_datetime',
                'value',
                'field_meta_value',
            ] as $column
        ) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                $columns[] = 'NULLIF(cfv.' . $column . ", '')";
            }
        }

        if (empty($columns)) {
            return 'NULL';
        }

        $expr = 'COALESCE(' . implode(', ', $columns) . ')';

        if (in_array($valueType, ['NUMERIC', 'NUMBER', 'INTEGER', 'DECIMAL'], true)) {
            return 'CAST(' . $expr . ' AS DECIMAL(15,4))';
        }

        return $expr;
    }

    private function resolveTaxonomy(mixed $taxonomy): ?object
    {
        if (! $taxonomy || ! Schema::hasTable($this->taxonomiesTable)) {
            return null;
        }

        return DB::table($this->taxonomiesTable)
            ->where(function ($query) use ($taxonomy) {
                if (is_numeric($taxonomy)) {
                    $query->where('id', (int) $taxonomy);
                }

                $query->orWhere('slug', (string) $taxonomy)
                    ->orWhere('name', (string) $taxonomy);
            })
            ->first();
    }

    private function resolveTermIds(mixed $terms, int $taxonomyId): array
    {
        if (! Schema::hasTable($this->taxonomyTermsTable)) {
            return [];
        }

        if ($terms === null || $terms === '') {
            return [];
        }

        if (is_string($terms)) {
            $decoded = json_decode($terms, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $terms = $decoded;
            } else {
                $terms = str_contains($terms, ',') ? explode(',', $terms) : [$terms];
            }
        }

        if (! is_array($terms)) {
            $terms = [$terms];
        }

        $numericIds = collect($terms)
            ->filter(fn($term) => is_numeric($term))
            ->map(fn($term) => (int) $term)
            ->values()
            ->toArray();

        $slugsOrNames = collect($terms)
            ->reject(fn($term) => is_numeric($term))
            ->map(fn($term) => trim((string) $term))
            ->filter()
            ->values()
            ->toArray();

        $query = DB::table($this->taxonomyTermsTable)
            ->where('taxonomy_id', $taxonomyId)
            ->where(function ($q) use ($numericIds, $slugsOrNames) {
                if (! empty($numericIds)) {
                    $q->whereIn('id', $numericIds);
                }

                if (! empty($slugsOrNames)) {
                    $q->orWhereIn('slug', $slugsOrNames)
                        ->orWhereIn('name', $slugsOrNames);
                }
            });

        return $query->pluck('id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
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
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
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
            ->map(fn($item) => trim((string) $item))
            ->filter(fn($item) => $item !== '')
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
