<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\DynamicPost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RelatedPostQueryService
{
    private string $postsTable = 'dynamic_posts';
    private string $postTypesTable = 'post_types';
    private string $taxonomiesTable = 'taxonomies';
    private string $taxonomyTermsTable = 'taxonomy_terms';
    private string $customFieldsTable = 'custom_fields';
    private string $customFieldValuesTable = 'custom_field_values';

    private array $taxonomyPivotCandidates = [
        'post_taxonomy_terms',
        'dynamic_post_taxonomy_term',
        'dynamic_post_taxonomy_terms',
        'dynamic_post_term',
        'dynamic_post_terms',
        'taxonomy_term_dynamic_post',
        'post_taxonomy_term',
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

    private array $locationColumns = [
        'location_id',
        'country_id',
        'state_id',
        'city_id',
        'area_id',
        'locality_id',
        'sector_id',
        'zone_id',
        'location',
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

        /*
     * Current listing context.
     * Works for both:
     * - real frontend render with DynamicPost object
     * - builder preview response with entity_id/post_type_id/selected_taxonomy_term_ids
     */
        $currentPostId = $currentPost?->id
            ?? data_get($context, 'entity_id')
            ?? data_get($context, 'post_id')
            ?? data_get($context, 'dynamic_post_id')
            ?? data_get($context, 'current_post_id');

        if (! $currentPost && $currentPostId) {
            $currentPost = DynamicPost::find((int) $currentPostId);
        }

        $currentPostTypeId = $currentPost?->post_type_id
            ?? data_get($context, 'post_type_id');

        $selectedTaxonomyTermIds = data_get($context, 'selected_taxonomy_term_ids', []);

        if (empty($selectedTaxonomyTermIds)) {
            $selectedTaxonomyTermIds = $this->extractTermIdsFromPreviewContext(
                data_get($context, 'preview_values.taxonomies', [])
            );
        }

        $query = DB::table($this->postsTable . ' as dp')
            ->select('dp.*')
            ->distinct();

        /*
     * Status filter
     */
        $this->applyStatus($query, $settings);

        /*
     * Exclude current post/listing
     */
        if (($settings['exclude_current'] ?? true) && $currentPostId) {
            $query->where('dp.id', '!=', (int) $currentPostId);
        }

        /*
     * Match current post type
     */
        if (($settings['match_post_type'] ?? true) && $currentPostTypeId) {
            $query->where('dp.post_type_id', (int) $currentPostTypeId);
        }

        /*
     * Match current taxonomy + terms.
     *
     * Important:
     * It groups selected term IDs by taxonomy.
     * Example:
     * purpose => Sell
     * property => Residential
     * property-type => Apartment
     * property-status => Ready To Move
     *
     * Candidate post must match each taxonomy group.
     */
        if ($settings['match_taxonomy_terms'] ?? true) {
            $taxonomyGroups = collect();

            if (! empty($selectedTaxonomyTermIds)) {
                $taxonomyGroups = $this->groupTermIdsByTaxonomy(
                    termIds: $selectedTaxonomyTermIds,
                    onlyLocationTaxonomies: false
                );
            } elseif ($currentPostId) {
                $taxonomyGroups = $this->currentPostTermsGroupedByTaxonomy(
                    postId: (int) $currentPostId,
                    onlyLocationTaxonomies: false
                );
            }

            foreach ($taxonomyGroups as $termIds) {
                $query->whereExists(
                    $this->termExistsQuery($termIds)
                );
            }
        }

        /*
     * Match locations.
     *
     * Works if location is saved as:
     * - dynamic_posts.city_id / area_id / location_id etc.
     * - taxonomy terms with slug city/location/area etc.
     */
        if ($settings['match_locations'] ?? true) {
            if ($currentPost) {
                $this->applyCurrentPostLocationMatch($query, $currentPost);
            } elseif (! empty($selectedTaxonomyTermIds)) {
                $locationGroups = $this->groupTermIdsByTaxonomy(
                    termIds: $selectedTaxonomyTermIds,
                    onlyLocationTaxonomies: true
                );

                foreach ($locationGroups as $termIds) {
                    $query->whereExists(
                        $this->termExistsQuery($termIds)
                    );
                }
            }
        }

        /*
     * Manual Query section:
     * bedroom = 1BHK
     * price BETWEEN 1000-2000
     * taxonomy custom rules
     * location custom rules
     */
        $this->applyBuilderQuery(
            query: $query,
            builderQuery: $settings['query'] ?? [],
            currentPost: $currentPost
        );

        $this->applyOrder($query, $settings);

        $limit = max(1, min((int) ($settings['posts_per_page'] ?? 6), 50));

        return $query->limit($limit)->get();
    }

    public function normalizeSettings(array $settings): array
    {
        return [
            'title' => $settings['title'] ?? 'Related Posts',

            'exclude_current' => $this->boolValue($settings['exclude_current'] ?? true),
            'match_post_type' => $this->boolValue($settings['match_post_type'] ?? true),
            'match_taxonomy_terms' => $this->boolValue($settings['match_taxonomy_terms'] ?? true),
            'match_locations' => $this->boolValue($settings['match_locations'] ?? true),

            'posts_per_page' => (int) ($settings['posts_per_page'] ?? 6),
            'status' => $settings['status'] ?? null,

            'query' => $this->normalizeQuery($settings['query'] ?? []),

            'orderby' => $settings['orderby'] ?? 'created_at',
            'order' => strtoupper((string) ($settings['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];
    }

    private function applyStatus($query, array $settings): void
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

    private function applyCurrentPostTaxonomyMatch($query, int $currentPostId, bool $onlyLocationTaxonomies): void
    {
        $groups = $this->currentPostTermsGroupedByTaxonomy($currentPostId, $onlyLocationTaxonomies);

        if ($groups->isEmpty()) {
            return;
        }

        foreach ($groups as $termIds) {
            $query->whereExists($this->termExistsQuery($termIds));
        }
    }

    private function applyCurrentPostLocationMatch($query, DynamicPost $currentPost): void
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

        $this->applyCurrentPostTaxonomyMatch($query, (int) $currentPost->id, true);
    }

    private function applyBuilderQuery($query, array $builderQuery, ?DynamicPost $currentPost): void
    {
        $relation = strtoupper((string) ($builderQuery['relation'] ?? 'AND'));
        $rules = $builderQuery['rules'] ?? [];

        foreach ($rules as $rule) {
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

                $this->applyCustomFieldRule($innerQuery, $rule);
            };

            $relation === 'OR'
                ? $query->orWhere($callback)
                : $query->where($callback);
        }
    }

    private function applyTaxonomyRule($query, array $rule, ?DynamicPost $currentPost): void
    {
        $taxonomy = $this->resolveTaxonomy($rule['taxonomy'] ?? null);

        if (! $taxonomy) {
            throw new RuntimeException('Invalid taxonomy in related posts query.');
        }

        $operator = strtoupper((string) ($rule['operator'] ?? 'IN'));
        $source = $rule['source'] ?? $rule['terms_from'] ?? 'manual';

        if ($source === 'current_post') {
            $termIds = $currentPost?->id
                ? $this->currentPostTermIds((int) $currentPost->id, (int) $taxonomy->id)
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
            return;
        }

        if (! empty($rule['taxonomy'])) {
            $this->applyTaxonomyRule($query, $rule, $currentPost);
        }
    }

    private function applyCustomFieldRule($query, array $rule): void
    {
        if (! Schema::hasTable($this->customFieldsTable) || ! Schema::hasTable($this->customFieldValuesTable)) {
            return;
        }

        $fieldKey = $rule['key'] ?? $rule['field'] ?? null;

        if (! $fieldKey) {
            throw new RuntimeException('Custom field key is required in related posts query.');
        }

        $compare = strtoupper((string) ($rule['compare'] ?? '='));
        $valueType = strtoupper((string) ($rule['value_type'] ?? 'CHAR'));
        $value = $rule['value'] ?? null;

        if ($compare === 'NOT EXISTS') {
            $query->whereNotExists($this->customFieldBaseExistsQuery((string) $fieldKey));
            return;
        }

        if ($compare === 'EXISTS') {
            $query->whereExists($this->customFieldBaseExistsQuery((string) $fieldKey));
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

    private function customFieldBaseExistsQuery(string $fieldKey): \Closure
    {
        return function ($sub) use ($fieldKey) {
            [$postColumn, $fieldColumn] = $this->customFieldValueColumns();

            $sub->select(DB::raw(1))
                ->from($this->customFieldValuesTable . ' as cfv')
                ->join($this->customFieldsTable . ' as cf', 'cf.id', '=', 'cfv.' . $fieldColumn)
                ->whereColumn('cfv.' . $postColumn, 'dp.id')
                ->where(function ($fieldQuery) use ($fieldKey) {
                    $fieldQuery->where('cf.field_name_slug', $fieldKey);

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

    private function applyCustomFieldValueCompare($query, mixed $value, string $compare, string $valueType): void
    {
        $compare = $compare === '==' ? '=' : $compare;

        $isNumeric = in_array($valueType, [
            'NUMERIC',
            'NUMBER',
            'DECIMAL',
            'INTEGER',
        ], true);

        if ($compare === 'BETWEEN' || $compare === 'NOT BETWEEN') {
            [$min, $max] = $this->betweenValues($value);

            $expression = $this->numericValueExpression();

            $compare === 'NOT BETWEEN'
                ? $query->whereNotBetween($expression, [(float) $min, (float) $max])
                : $query->whereBetween($expression, [(float) $min, (float) $max]);

            return;
        }

        $expression = $isNumeric
            ? $this->numericValueExpression()
            : $this->stringValueExpression();

        if ($compare === 'IN' || $compare === 'NOT IN') {
            $values = $this->parseList($value);

            $compare === 'NOT IN'
                ? $query->whereNotIn($expression, $values)
                : $query->whereIn($expression, $values);

            return;
        }

        if ($compare === 'LIKE' || $compare === 'NOT LIKE') {
            $query->where(
                $this->stringValueExpression(),
                $compare,
                '%' . trim((string) $value) . '%'
            );

            return;
        }

        if (! in_array($compare, ['=', '!=', '<>', '>', '>=', '<', '<='], true)) {
            $compare = '=';
        }

        $query->where($expression, $compare, $isNumeric ? (float) $value : $value);
    }

    private function stringValueExpression()
    {
        $parts = [];

        foreach (['value_string', 'value_text', 'value', 'field_meta_value'] as $column) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                $parts[] = 'cfv.' . $column;
            }
        }

        if (Schema::hasColumn($this->customFieldValuesTable, 'value_json')) {
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$.value'))";
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$.amount'))";
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$.price'))";
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$'))";
        }

        if (empty($parts)) {
            throw new RuntimeException('No custom field text value column found.');
        }

        return DB::raw('COALESCE(' . implode(', ', $parts) . ')');
    }

    private function numericValueExpression()
    {
        $parts = [];

        if (Schema::hasColumn($this->customFieldValuesTable, 'value_number')) {
            $parts[] = 'cfv.value_number';
        }

        foreach (['value_string', 'value_text', 'value'] as $column) {
            if (Schema::hasColumn($this->customFieldValuesTable, $column)) {
                $parts[] = "NULLIF(cfv.{$column}, '')";
            }
        }

        if (Schema::hasColumn($this->customFieldValuesTable, 'value_json')) {
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$.value'))";
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$.amount'))";
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$.price'))";
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT(cfv.value_json, '$'))";
        }

        if (empty($parts)) {
            throw new RuntimeException('No custom field numeric value column found.');
        }

        return DB::raw('CAST(COALESCE(' . implode(', ', $parts) . ') AS DECIMAL(18,2))');
    }

    private function customFieldValueColumns(): array
    {
        $postColumn = $this->firstExistingColumn($this->customFieldValuesTable, [
            'entity_id',
            'dynamic_post_id',
            'post_id',
            'content_id',
            'object_id',
        ]);

        $fieldColumn = $this->firstExistingColumn($this->customFieldValuesTable, [
            'custom_field_id',
            'field_id',
        ]);

        if (! $postColumn || ! $fieldColumn) {
            throw new RuntimeException('custom_field_values post/field columns not found.');
        }

        return [$postColumn, $fieldColumn];
    }

    private function currentPostTermsGroupedByTaxonomy(int $postId, bool $onlyLocationTaxonomies): \Illuminate\Support\Collection
    {
        $pivot = $this->taxonomyPivotTable();

        if (! $pivot) {
            return collect();
        }

        [$postColumn, $termColumn] = $this->taxonomyPivotColumns($pivot);

        if (! $postColumn || ! $termColumn) {
            return collect();
        }

        $query = DB::table($pivot . ' as p')
            ->join($this->taxonomyTermsTable . ' as tt', 'tt.id', '=', 'p.' . $termColumn)
            ->join($this->taxonomiesTable . ' as tx', 'tx.id', '=', 'tt.taxonomy_id')
            ->where('p.' . $postColumn, $postId)
            ->select('tt.id', 'tt.taxonomy_id', 'tx.slug as taxonomy_slug');

        if (Schema::hasColumn($pivot, 'entity_type')) {
            $query->where('p.entity_type', 'post');
        }

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

    private function currentPostTermIds(int $postId, int $taxonomyId): array
    {
        $pivot = $this->taxonomyPivotTable();

        if (! $pivot) {
            return [];
        }

        [$postColumn, $termColumn] = $this->taxonomyPivotColumns($pivot);

        if (! $postColumn || ! $termColumn) {
            return [];
        }

        $query = DB::table($pivot . ' as p')
            ->join($this->taxonomyTermsTable . ' as tt', 'tt.id', '=', 'p.' . $termColumn)
            ->where('p.' . $postColumn, $postId)
            ->where('tt.taxonomy_id', $taxonomyId);

        if (Schema::hasColumn($pivot, 'entity_type')) {
            $query->where('p.entity_type', 'post');
        }

        return $query->pluck('tt.id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function termExistsQuery(array $termIds): \Closure
    {
        return function ($sub) use ($termIds) {
            $pivot = $this->taxonomyPivotTable();

            if (! $pivot) {
                $sub->select(DB::raw(1))->whereRaw('1 = 0');
                return;
            }

            [$postColumn, $termColumn] = $this->taxonomyPivotColumns($pivot);

            if (! $postColumn || ! $termColumn) {
                $sub->select(DB::raw(1))->whereRaw('1 = 0');
                return;
            }

            $sub->select(DB::raw(1))
                ->from($pivot . ' as taxp')
                ->whereColumn('taxp.' . $postColumn, 'dp.id')
                ->whereIn('taxp.' . $termColumn, $termIds);

            if (Schema::hasColumn($pivot, 'entity_type')) {
                $sub->where('taxp.entity_type', 'post');
            }
        };
    }

    private function resolveTaxonomy(mixed $value): ?object
    {
        if (! Schema::hasTable($this->taxonomiesTable)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $query = DB::table($this->taxonomiesTable);

        if (is_numeric($value)) {
            return $query->where('id', (int) $value)->first();
        }

        return $query->where(function ($q) use ($value) {
            $q->where('slug', $value)
                ->orWhere('name', $value);
        })->first();
    }

    private function resolveTermIds(mixed $value, int $taxonomyId): array
    {
        if (! Schema::hasTable($this->taxonomyTermsTable)) {
            return [];
        }

        $items = $this->parseList($value);

        if (empty($items)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            $query = DB::table($this->taxonomyTermsTable)
                ->where('taxonomy_id', $taxonomyId);

            $term = is_numeric($item)
                ? $query->where('id', (int) $item)->first()
                : $query->where(function ($q) use ($item) {
                    $q->where('slug', $item)
                        ->orWhere('name', $item);
                })->first();

            if ($term) {
                $ids[] = (int) $term->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function applyOrder($query, array $settings): void
    {
        $orderby = (string) ($settings['orderby'] ?? 'created_at');
        $order = (string) ($settings['order'] ?? 'DESC');

        if (in_array($orderby, ['rand', 'random'], true)) {
            $query->inRandomOrder();
            return;
        }

        if (Schema::hasColumn($this->postsTable, $orderby)) {
            $query->orderBy('dp.' . $orderby, $order);
            return;
        }

        $query->orderBy('dp.id', 'DESC');
    }

    private function normalizeQuery(array $query): array
    {
        $relation = strtoupper((string) ($query['relation'] ?? 'AND'));
        $rules = [];

        if (isset($query['rules']) && is_array($query['rules'])) {
            $rules = $query['rules'];
        } elseif (isset($query['items']) && is_array($query['items'])) {
            $rules = $query['items'];
        } else {
            foreach ($query as $key => $value) {
                if ($key === 'relation') {
                    continue;
                }

                if (is_array($value)) {
                    $rules[] = $value;
                }
            }
        }

        return [
            'relation' => $relation === 'OR' ? 'OR' : 'AND',
            'rules' => array_values(array_filter($rules, fn($rule) => is_array($rule))),
        ];
    }

    private function parseList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn($item) => trim((string) $item))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->parseList($decoded);
        }

        return collect(explode(',', $value))
            ->map(fn($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    private function betweenValues(mixed $value): array
    {
        if (is_array($value)) {
            return [
                $value[0] ?? 0,
                $value[1] ?? 0,
            ];
        }

        $value = trim((string) $value);

        if (str_contains($value, '-')) {
            $parts = explode('-', $value, 2);

            return [
                trim($parts[0]),
                trim($parts[1]),
            ];
        }

        $parts = explode(',', $value, 2);

        return [
            trim($parts[0] ?? '0'),
            trim($parts[1] ?? '0'),
        ];
    }

    private function taxonomyPivotTable(): ?string
    {
        foreach ($this->taxonomyPivotCandidates as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function taxonomyPivotColumns(string $pivot): array
    {
        return [
            $this->firstExistingColumn($pivot, [
                'dynamic_post_id',
                'post_id',
                'entity_id',
                'content_id',
                'object_id',
            ]),
            $this->firstExistingColumn($pivot, [
                'taxonomy_term_id',
                'term_id',
            ]),
        ];
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

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
