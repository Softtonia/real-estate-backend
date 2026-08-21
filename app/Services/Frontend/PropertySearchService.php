<?php

namespace App\Services\Frontend;

use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PropertyFeaturedPromotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PropertySearchService
{
    public function options(array $filters = []): array
    {
        return Cache::store('redis')->remember(
            'frontend:property-search:options:v9',
            now()->addHours(6),
            function (): array {
                $purposes = $this->taxonomyOptions('purpose');
                $propertyTypes = $this->taxonomyOptions('property_type');
                $groupedPropertyTypes = $this->buildGroupedPropertyTypes($propertyTypes);

                return [
                    'purposes' => $purposes,
                    'property_types' => $propertyTypes,
                    'grouped_property_types' => $groupedPropertyTypes,
                    'bedrooms' => $this->bedroomOptions(),
                    'budget_options' => $this->dynamicBudgetOptions(),
                ];
            }
        );
    }

    private function dynamicBudgetOptions(): array
    {
        $priceFieldIds = $this->priceFieldIds();

        $minPrice = null;
        $maxPrice = null;

        if (!empty($priceFieldIds) && Schema::hasTable('custom_field_values')) {
            $stats = DB::table('custom_field_values')
                ->where('entity_type', 'post')
                ->whereIn('custom_field_id', $priceFieldIds)
                ->whereNotNull('value_number')
                ->where('value_number', '>', 0)
                ->selectRaw('MIN(value_number) as min_val, MAX(value_number) as max_val')
                ->first();

            if ($stats && $stats->min_val !== null && $stats->max_val !== null) {
                $minPrice = (float) $stats->min_val;
                $maxPrice = (float) $stats->max_val;
            }
        }

        $configuredBudget = config('property_search.budget_options', []);
        $rawMinList = $configuredBudget['min'] ?? [500000, 1000000, 2000000, 3000000, 4000000, 5000000, 7500000, 10000000, 15000000, 20000000];
        $rawMaxList = $configuredBudget['max'] ?? [1000000, 2000000, 3000000, 5000000, 7500000, 10000000, 15000000, 20000000, 30000000, 50000000];

        $minOptions = collect($rawMinList)->map(function ($val) {
            return [
                'value' => (float) $val,
                'label' => $this->displayPrice($val),
            ];
        })->values()->all();

        $maxOptions = collect($rawMaxList)->map(function ($val) {
            return [
                'value' => (float) $val,
                'label' => $this->displayPrice($val),
            ];
        })->values()->all();

        return [
            'db_min_price' => $minPrice,
            'db_max_price' => $maxPrice,
            'db_min_formatted' => $minPrice ? $this->displayPrice($minPrice) : null,
            'db_max_formatted' => $maxPrice ? $this->displayPrice($maxPrice) : null,
            'min' => $rawMinList,
            'max' => $rawMaxList,
            'min_options' => $minOptions,
            'max_options' => $maxOptions,
        ];
    }

    private function buildGroupedPropertyTypes(array $propertyTypes): array
    {
        $standardGroups = [
            'residential' => [
                'id' => 1,
                'name' => 'Residential',
                'label' => 'Residential',
                'slug' => 'residential',
                'taxonomy_id' => 2,
                'children' => [],
                'has_children' => false,
            ],
            'commercial' => [
                'id' => 2,
                'name' => 'Commercial',
                'label' => 'Commercial',
                'slug' => 'commercial',
                'taxonomy_id' => 2,
                'children' => [],
                'has_children' => false,
            ],
            'agricultural' => [
                'id' => 3,
                'name' => 'Agricultural',
                'label' => 'Agricultural',
                'slug' => 'agricultural',
                'taxonomy_id' => 2,
                'children' => [],
                'has_children' => false,
            ],
            'industrial' => [
                'id' => 4,
                'name' => 'Industrial',
                'label' => 'Industrial',
                'slug' => 'industrial',
                'taxonomy_id' => 2,
                'children' => [],
                'has_children' => false,
            ],
        ];

        $termIdToGroupSlug = [];

        if (Schema::hasTable('taxonomy_terms') && Schema::hasTable('taxonomies')) {
            $dbGroupTerms = DB::table('taxonomy_terms')
                ->join('taxonomies', 'taxonomies.id', '=', 'taxonomy_terms.taxonomy_id')
                ->where('taxonomies.slug', 'property')
                ->select('taxonomy_terms.id', 'taxonomy_terms.name', 'taxonomy_terms.slug', 'taxonomy_terms.taxonomy_id')
                ->get();

            foreach ($dbGroupTerms as $gt) {
                $gSlug = mb_strtolower((string) $gt->slug);
                if (isset($standardGroups[$gSlug])) {
                    $standardGroups[$gSlug]['id'] = (int) $gt->id;
                    $standardGroups[$gSlug]['taxonomy_id'] = (int) $gt->taxonomy_id;
                    $termIdToGroupSlug[(int) $gt->id] = $gSlug;
                }
            }

            $parentIds = collect($propertyTypes)->pluck('parent_id')->filter()->unique()->all();

            if (!empty($parentIds)) {
                $allParents = DB::table('taxonomy_terms')
                    ->whereIn('id', $parentIds)
                    ->pluck('slug', 'id');

                foreach ($allParents as $pId => $pSlug) {
                    $s = mb_strtolower((string) $pSlug);
                    if (isset($standardGroups[$s])) {
                        $termIdToGroupSlug[(int) $pId] = $s;
                    }
                }
            }
        }

        if (Schema::hasTable('taxonomy_term_relations')) {
            $relations = DB::table('taxonomy_term_relations')->get();
            foreach ($relations as $r) {
                if (property_exists($r, 'taxonomy_term_id') && property_exists($r, 'relation_value_term_id')) {
                    $termId = (int) $r->taxonomy_term_id;
                    $relId = (int) $r->relation_value_term_id;

                    if (isset($termIdToGroupSlug[$relId]) && !isset($termIdToGroupSlug[$termId])) {
                        $termIdToGroupSlug[$termId] = $termIdToGroupSlug[$relId];
                    }
                }
            }
        }

        foreach ($propertyTypes as $term) {
            $termSlug = mb_strtolower((string) ($term['slug'] ?? ''));
            $termId = (int) $term['id'];
            $parentId = isset($term['parent_id']) && $term['parent_id'] !== null ? (int) $term['parent_id'] : null;

            // Skip top-level group header terms themselves from being added as children
            if (empty($parentId) && in_array($termSlug, ['residential', 'commercial', 'agricultural', 'industrial'], true)) {
                continue;
            }

            $groupSlug = null;

            if ($parentId && isset($termIdToGroupSlug[$parentId])) {
                $groupSlug = $termIdToGroupSlug[$parentId];
            }

            if (!$groupSlug && isset($termIdToGroupSlug[$termId])) {
                $groupSlug = $termIdToGroupSlug[$termId];
            }

            if (!$groupSlug) {
                $slug = mb_strtolower((string) ($term['slug'] ?? ''));
                $name = mb_strtolower((string) ($term['name'] ?? ''));
                $text = $slug . ' ' . $name;

                if (Str::contains($text, ['office', 'showroom', 'commercial', 'warehouse', 'hotel', 'restaurant', 'business', 'co-working', 'coworking', 'shop', 'mall', 'retail', 'hospitality', 'pg'])) {
                    $groupSlug = 'commercial';
                } elseif (Str::contains($text, ['agricultural', 'crop', 'orchard', 'plantation', 'horticultural', 'dairy', 'poultry', 'farm', 'pasture', 'irrigated', 'fallow', 'agroforestry'])) {
                    $groupSlug = 'agricultural';
                } elseif (Str::contains($text, ['industrial', 'factory', 'manufacturing', 'shed', 'godown', 'workshop', 'logistics', 'cold-storage', 'estate'])) {
                    $groupSlug = 'industrial';
                } else {
                    $groupSlug = 'residential';
                }
            }

            // Do not add top-level group header term as its own child option
            if ($termSlug === $groupSlug && empty($parentId)) {
                continue;
            }

            if (isset($standardGroups[$groupSlug])) {
                $standardGroups[$groupSlug]['children'][] = $term;
                $standardGroups[$groupSlug]['has_children'] = true;
            }
        }

        return array_values(array_filter($standardGroups, fn ($g) => !empty($g['children'])));
    }

    public function locationSuggestions(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return [];
        }

        $cacheKey = 'frontend:property-search:locations:v2:' . md5(mb_strtolower($search));

        return Cache::store('redis')->remember(
            $cacheKey,
            now()->addMinutes(30),
            function () use ($search): array {
                $results = collect();

                if (Schema::hasTable('cities')) {
                    $cities = DB::table('cities')
                        ->leftJoin('states', 'states.id', '=', 'cities.state_id')
                        ->leftJoin('countries', 'countries.id', '=', 'states.country_id')
                        ->select([
                            'cities.id as city_id',
                            'cities.name as city_name',
                            'cities.state_id',
                            'states.name as state_name',
                            'states.country_id',
                            'countries.name as country_name',
                        ])
                        ->where('cities.name', 'like', "%{$search}%")
                        ->when(
                            Schema::hasColumn('cities', 'status'),
                            fn ($q) => $q->where('cities.status', 1)
                        )
                        ->orderBy('cities.name')
                        ->limit(10)
                        ->get()
                        ->map(function ($city): array {
                            return [
                                'type' => 'city',
                                'id' => (int) $city->city_id,
                                'value' => (int) $city->city_id,
                                'label' => $city->city_name,
                                'name' => $city->city_name,
                                'city_id' => (int) $city->city_id,
                                'state_id' => $city->state_id ? (int) $city->state_id : null,
                                'country_id' => $city->country_id ? (int) $city->country_id : null,
                                'full_location' => collect([
                                    $city->city_name,
                                    $city->state_name,
                                    $city->country_name,
                                ])->filter()->implode(', '),
                            ];
                        });

                    $results = $results->merge($cities);
                }

                if (
                    Schema::hasTable('dynamic_posts')
                    && Schema::hasColumn('dynamic_posts', 'area_locality')
                ) {
                    $localities = DB::table('dynamic_posts')
                        ->leftJoin('cities', 'cities.id', '=', 'dynamic_posts.city_id')
                        ->leftJoin('states', 'states.id', '=', 'dynamic_posts.state_id')
                        ->leftJoin('countries', 'countries.id', '=', 'dynamic_posts.country_id')
                        ->select([
                            'dynamic_posts.area_locality',
                            'dynamic_posts.city_id',
                            'dynamic_posts.state_id',
                            'dynamic_posts.country_id',
                            'cities.name as city_name',
                            'states.name as state_name',
                            'countries.name as country_name',
                        ])
                        ->where('dynamic_posts.status', 'published')
                        ->where('dynamic_posts.live_status', 'approve')
                        ->whereNotNull('dynamic_posts.area_locality')
                        ->where('dynamic_posts.area_locality', 'like', "%{$search}%")
                        ->groupBy([
                            'dynamic_posts.area_locality',
                            'dynamic_posts.city_id',
                            'dynamic_posts.state_id',
                            'dynamic_posts.country_id',
                            'cities.name',
                            'states.name',
                            'countries.name',
                        ])
                        ->orderBy('dynamic_posts.area_locality')
                        ->limit(10)
                        ->get()
                        ->map(function ($row): array {
                            return [
                                'type' => 'locality',
                                'id' => null,
                                'value' => $row->area_locality,
                                'label' => collect([
                                    $row->area_locality,
                                    $row->city_name,
                                ])->filter()->implode(', '),
                                'name' => $row->area_locality,
                                'area_locality' => $row->area_locality,
                                'city_id' => $row->city_id ? (int) $row->city_id : null,
                                'state_id' => $row->state_id ? (int) $row->state_id : null,
                                'country_id' => $row->country_id ? (int) $row->country_id : null,
                                'full_location' => collect([
                                    $row->area_locality,
                                    $row->city_name,
                                    $row->state_name,
                                    $row->country_name,
                                ])->filter()->implode(', '),
                            ];
                        });

                    $results = $results->merge($localities);
                }

                return $results
                    ->unique(fn (array $item) => $item['type'] . ':' . mb_strtolower((string) $item['label']))
                    ->values()
                    ->all();
            }
        );
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $postTypeId = $this->propertyPostTypeId();

        $perPage = min(
            max(
                (int) ($filters['per_page'] ?? config('property_search.default_per_page', 20)),
                1
            ),
            (int) config('property_search.max_per_page', 50)
        );

        $query = DynamicPost::query()
            ->select('dynamic_posts.*')
            ->leftJoin('countries as search_country', 'search_country.id', '=', 'dynamic_posts.country_id')
            ->leftJoin('states as search_state', 'search_state.id', '=', 'dynamic_posts.state_id')
            ->leftJoin('cities as search_city', 'search_city.id', '=', 'dynamic_posts.city_id')
            ->addSelect([
                'search_country.name as search_country_name',
                'search_state.name as search_state_name',
                'search_city.name as search_city_name',
            ])
            ->with($this->listingRelations())
            ->where('dynamic_posts.status', 'published')
            ->where('dynamic_posts.live_status', 'approve');

        $this->applyPostTypeFilter($query, $filters);
        $this->applyPublicAvailabilityScope($query);

        $this->applyLocationFilters($query, $filters);
        $this->applyGeneralSearch($query, $filters);
        $this->applyTaxonomyFilters($query, $filters);
        $this->applyBedroomFilter($query, $filters);
        $this->applyPriceFilter($query, $filters);
        $this->applyPriceSelect($query);
        $this->applyPromotionFilters($query, $filters);
        $this->applySort($query, $filters);

        $paginator = $query->paginate(
            $perPage,
            ['*'],
            'page',
            max((int) ($filters['page'] ?? 1), 1)
        );

        $posts = $paginator->getCollection();

        if ($posts->isEmpty()) {
            return $paginator;
        }

        $postIds = $posts->pluck('id')->map(fn ($id) => (int) $id)->all();

        $mediaById = $this->mediaByIdForPosts($posts);
        $repeaterValues = $this->repeaterValuesByPost($postIds);
        $keywordsByPost = $this->keywordsByPost($postIds);
        $relationshipsByPost = $this->relationshipsByPost($postIds);

        $posts->transform(function (DynamicPost $post) use (
            $mediaById,
            $repeaterValues,
            $keywordsByPost,
            $relationshipsByPost
        ): array {
            return $this->formatCompleteListing(
                post: $post,
                mediaById: $mediaById,
                repeaterValues: $repeaterValues[(int) $post->id] ?? [],
                keywords: $keywordsByPost[(int) $post->id] ?? [],
                relationships: $relationshipsByPost[(int) $post->id] ?? []
            );
        });

        return $paginator;
    }

    private function listingRelations(): array
    {
        return [
            'postType',
            'parent:id,post_type_id,title,slug,status,live_status',
            'children:id,post_type_id,parent_id,title,slug,status,live_status',
            'taxonomyTerms.taxonomy',
            'meta.customField.options',
            'meta.customField.repeaters.options',
            'latestVerificationRevision',
            'currentFeaturedPromotion',
        ];
    }

    private function applyPublicAvailabilityScope(Builder $query): void
    {
        if (!Schema::hasColumn('dynamic_posts', 'availability_status')) {
            return;
        }

        $query->where(function (Builder $availabilityQuery): void {
            $availabilityQuery
                ->whereNull('dynamic_posts.availability_status')
                ->orWhereIn('dynamic_posts.availability_status', [
                    'available',
                    'reserved',
                ]);

            if (
                Schema::hasColumn('dynamic_posts', 'availability_public_until')
                && Schema::hasColumn('dynamic_posts', 'availability_hidden_at')
            ) {
                $availabilityQuery->orWhere(function (Builder $soldQuery): void {
                    $soldQuery
                        ->where('dynamic_posts.availability_status', 'sold')
                        ->whereNull('dynamic_posts.availability_hidden_at')
                        ->whereNotNull('dynamic_posts.availability_public_until')
                        ->where('dynamic_posts.availability_public_until', '>', now());
                });
            }
        });
    }

    private function applyLocationFilters(Builder $query, array $filters): void
    {
        $location = trim((string) ($filters['location'] ?? ''));

        $rawCity = $filters['city_id'] ?? $filters['city'] ?? $filters['city_name'] ?? null;
        $cityId = null;

        if (!empty($rawCity)) {
            if (is_numeric($rawCity) && (int) $rawCity > 0) {
                $cityId = (int) $rawCity;
            } elseif (is_string($rawCity) && trim($rawCity) !== '') {
                $cName = mb_strtolower(trim($rawCity));
                if (Schema::hasTable('cities')) {
                    $foundId = DB::table('cities')
                        ->whereRaw('LOWER(name) = ?', [$cName])
                        ->orWhereRaw('LOWER(slug) = ?', [$cName])
                        ->value('id');
                    if ($foundId) {
                        $cityId = (int) $foundId;
                    }
                }
            }
        }

        if (!$cityId && $location !== '') {
            if (Schema::hasTable('cities')) {
                $foundCityId = DB::table('cities')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($location)])
                    ->orWhereRaw('LOWER(slug) = ?', [mb_strtolower($location)])
                    ->value('id');

                if ($foundCityId) {
                    $cityId = (int) $foundCityId;
                }
            }
        }

        if (!empty($filters['country_id']) && is_numeric($filters['country_id']) && (int) $filters['country_id'] > 0) {
            $query->where('dynamic_posts.country_id', (int) $filters['country_id']);
        }

        if (!empty($filters['state_id']) && is_numeric($filters['state_id']) && (int) $filters['state_id'] > 0) {
            $query->where('dynamic_posts.state_id', (int) $filters['state_id']);
        }

        if ($cityId) {
            $query->where('dynamic_posts.city_id', $cityId);
        } elseif ($location !== '') {
            $query->where(function (Builder $locQuery) use ($location): void {
                $locQuery->where('search_city.name', 'like', "%{$location}%")
                    ->orWhere('dynamic_posts.area_locality', 'like', "%{$location}%");
            });
        }

        if (!empty($filters['area_locality'])) {
            $locality = trim((string) $filters['area_locality']);

            $query->where(
                'dynamic_posts.area_locality',
                'like',
                '%' . $locality . '%'
            );
        }
    }

    private function applyGeneralSearch(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($search): void {
            $searchQuery->where('dynamic_posts.title', 'like', "%{$search}%");

            if (Schema::hasColumn('dynamic_posts', 'slug')) {
                $searchQuery->orWhere('dynamic_posts.slug', 'like', "%{$search}%");
            }

            if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                $searchQuery->orWhere('dynamic_posts.listing_code', 'like', "%{$search}%");
            }

            if (Schema::hasColumn('dynamic_posts', 'excerpt')) {
                $searchQuery->orWhere('dynamic_posts.excerpt', 'like', "%{$search}%");
            }

            if (Schema::hasColumn('dynamic_posts', 'content')) {
                $searchQuery->orWhere('dynamic_posts.content', 'like', "%{$search}%");
            }

            if (Schema::hasColumn('dynamic_posts', 'area_locality')) {
                $searchQuery->orWhere('dynamic_posts.area_locality', 'like', "%{$search}%");
            }

            $searchQuery
                ->orWhere('search_city.name', 'like', "%{$search}%")
                ->orWhere('search_state.name', 'like', "%{$search}%")
                ->orWhere('search_country.name', 'like', "%{$search}%");

            if (
                Schema::hasTable('post_taxonomy_terms')
                && Schema::hasTable('taxonomy_terms')
            ) {
                $searchQuery->orWhereExists(function ($exists) use ($search): void {
                    $exists
                        ->selectRaw('1')
                        ->from('post_taxonomy_terms as search_ptt')
                        ->join(
                            'taxonomy_terms as search_tt',
                            'search_tt.id',
                            '=',
                            'search_ptt.taxonomy_term_id'
                        )
                        ->whereColumn(
                            'search_ptt.dynamic_post_id',
                            'dynamic_posts.id'
                        )
                        ->where(function ($termQuery) use ($search): void {
                            $termQuery
                                ->where('search_tt.name', 'like', "%{$search}%")
                                ->orWhere('search_tt.slug', 'like', "%{$search}%");
                        });
                });
            }

            if (
                Schema::hasTable('custom_field_values')
                && Schema::hasTable('custom_fields')
            ) {
                $searchQuery->orWhereExists(function ($exists) use ($search): void {
                    $exists
                        ->selectRaw('1')
                        ->from('custom_field_values as search_cfv')
                        ->join(
                            'custom_fields as search_cf',
                            'search_cf.id',
                            '=',
                            'search_cfv.custom_field_id'
                        )
                        ->whereColumn(
                            'search_cfv.entity_id',
                            'dynamic_posts.id'
                        )
                        ->where('search_cfv.entity_type', 'post')
                        ->where(function ($customQuery) use ($search): void {
                            $customQuery
                                ->where('search_cfv.value_string', 'like', "%{$search}%")
                                ->orWhere('search_cfv.value_text', 'like', "%{$search}%")
                                ->orWhere('search_cf.field_label', 'like', "%{$search}%")
                                ->orWhere('search_cf.field_name_slug', 'like', "%{$search}%");

                            if (Schema::hasColumn('custom_field_values', 'value_number')) {
                                $customQuery->orWhereRaw(
                                    'CAST(search_cfv.value_number AS CHAR) LIKE ?',
                                    ["%{$search}%"]
                                );
                            }
                        });
                });
            }
        });
    }

    private function applyTaxonomyFilters(Builder $query, array $filters): void
    {
        $groups = [];

        foreach (['purpose', 'property_type'] as $key) {
            $values = $this->normalizeFilterValues($filters[$key] ?? null);

            if (empty($values)) {
                continue;
            }

            $termIds = $this->resolveTaxonomyTermIds($key, $values);

            if (!empty($termIds)) {
                $taxonomyId = $this->taxonomyIdForTerm((int) $termIds[0]);

                if ($taxonomyId) {
                    $groups[$taxonomyId] = array_values(array_unique(array_merge(
                        $groups[$taxonomyId] ?? [],
                        $termIds
                    )));
                }
            }
        }

        $explicitIds = $this->normalizeIds($filters['taxonomy_term_ids'] ?? null);

        if (!empty($explicitIds)) {
            $explicitRows = DB::table('taxonomy_terms')
                ->select(['id', 'taxonomy_id'])
                ->whereIn('id', $explicitIds)
                ->get();

            foreach ($explicitRows as $row) {
                $taxonomyId = (int) $row->taxonomy_id;

                $groups[$taxonomyId][] = (int) $row->id;
                $groups[$taxonomyId] = array_values(array_unique($groups[$taxonomyId]));
            }
        }

        foreach ($groups as $termIds) {
            $query->whereHas(
                'taxonomyTerms',
                fn (Builder $termQuery) => $termQuery->whereIn(
                    'taxonomy_terms.id',
                    $termIds
                )
            );
        }
    }

    private function applyBedroomFilter(Builder $query, array $filters): void
    {
        $values = $this->normalizeFilterValues($filters['bedrooms'] ?? null);

        if (empty($values)) {
            return;
        }

        $fieldIds = $this->bedroomFieldIds();

        if (!empty($fieldIds) && Schema::hasTable('custom_field_values')) {
            $query->whereExists(function ($exists) use ($values, $fieldIds): void {
                $exists
                    ->selectRaw('1')
                    ->from('custom_field_values as bedroom_cfv')
                    ->whereColumn(
                        'bedroom_cfv.entity_id',
                        'dynamic_posts.id'
                    )
                    ->where('bedroom_cfv.entity_type', 'post')
                    ->whereIn('bedroom_cfv.custom_field_id', $fieldIds)
                    ->where(function ($valueQuery) use ($values): void {
                        foreach ($values as $value) {
                            $normalized = mb_strtolower(trim((string) $value));

                            $valueQuery->orWhere(function ($single) use ($value, $normalized): void {
                                if (is_numeric($value)) {
                                    $single->where(
                                        'bedroom_cfv.custom_field_option_id',
                                        (int) $value
                                    );

                                    if (Schema::hasColumn('custom_field_values', 'value_number')) {
                                        $single->orWhere(
                                            'bedroom_cfv.value_number',
                                            (float) $value
                                        );
                                    }
                                }

                                $single
                                    ->orWhereRaw(
                                        "LOWER(TRIM(COALESCE(bedroom_cfv.value_string, ''))) = ?",
                                        [$normalized]
                                    )
                                    ->orWhereRaw(
                                        "LOWER(TRIM(COALESCE(bedroom_cfv.value_text, ''))) = ?",
                                        [$normalized]
                                    );

                                if (Schema::hasTable('custom_field_options')) {
                                    $single->orWhereExists(function ($optionQuery) use ($normalized): void {
                                        $optionQuery
                                            ->selectRaw('1')
                                            ->from('custom_field_options as bedroom_cfo')
                                            ->whereColumn(
                                                'bedroom_cfo.id',
                                                'bedroom_cfv.custom_field_option_id'
                                            )
                                            ->where(function ($optionMatch) use ($normalized): void {
                                                $optionMatch
                                                    ->whereRaw(
                                                        "LOWER(TRIM(COALESCE(bedroom_cfo.name, ''))) = ?",
                                                        [$normalized]
                                                    );

                                                if (Schema::hasColumn('custom_field_options', 'value')) {
                                                    $optionMatch->orWhereRaw(
                                                        "LOWER(TRIM(COALESCE(bedroom_cfo.value, ''))) = ?",
                                                        [$normalized]
                                                    );
                                                }
                                            });
                                    });
                                }
                            });
                        }
                    });
            });

            return;
        }

        // Backward-compatible fallback if Bedrooms is configured as a taxonomy.
        $termIds = $this->resolveTaxonomyTermIds('bedrooms', $values);

        if (!empty($termIds)) {
            $query->whereHas(
                'taxonomyTerms',
                fn (Builder $termQuery) => $termQuery->whereIn(
                    'taxonomy_terms.id',
                    $termIds
                )
            );
        }
    }

    private function applyPriceFilter(Builder $query, array $filters): void
    {
        $hasMin = array_key_exists('price_min', $filters)
            && $filters['price_min'] !== null
            && $filters['price_min'] !== '';

        $hasMax = array_key_exists('price_max', $filters)
            && $filters['price_max'] !== null
            && $filters['price_max'] !== '';

        if (!$hasMin && !$hasMax) {
            return;
        }

        $priceFieldIds = $this->priceFieldIds();

        if (empty($priceFieldIds) || !Schema::hasTable('custom_field_values')) {
            // A price filter was requested, but no price field exists.
            // Returning no rows is safer than silently ignoring the filter.
            $query->whereRaw('1 = 0');

            return;
        }

        $priceExpression = $this->priceSqlExpression('price_cfv');

        $query->whereExists(function ($exists) use (
            $priceFieldIds,
            $priceExpression,
            $filters,
            $hasMin,
            $hasMax
        ): void {
            $exists
                ->selectRaw('1')
                ->from('custom_field_values as price_cfv')
                ->whereColumn('price_cfv.entity_id', 'dynamic_posts.id')
                ->where('price_cfv.entity_type', 'post')
                ->whereIn('price_cfv.custom_field_id', $priceFieldIds)
                ->whereRaw("{$priceExpression} IS NOT NULL");

            if ($hasMin) {
                $exists->whereRaw(
                    "{$priceExpression} >= ?",
                    [(float) $filters['price_min']]
                );
            }

            if ($hasMax) {
                $exists->whereRaw(
                    "{$priceExpression} <= ?",
                    [(float) $filters['price_max']]
                );
            }
        });
    }

    private function applyPriceSelect(Builder $query): void
    {
        $priceFieldIds = $this->priceFieldIds();

        if (empty($priceFieldIds) || !Schema::hasTable('custom_field_values')) {
            $query->addSelect(DB::raw('NULL as search_price'));

            return;
        }

        $expression = $this->priceSqlExpression('selected_price_cfv');

        $priceSubquery = DB::table('custom_field_values as selected_price_cfv')
            ->selectRaw("MAX({$expression})")
            ->whereColumn(
                'selected_price_cfv.entity_id',
                'dynamic_posts.id'
            )
            ->where('selected_price_cfv.entity_type', 'post')
            ->whereIn('selected_price_cfv.custom_field_id', $priceFieldIds);

        $query->addSelect([
            'search_price' => $priceSubquery,
        ]);
    }

    private function applyPostTypeFilter(Builder $query, array $filters): void
    {
        $postType = $filters['post_type'] ?? null;

        if (empty($postType)) {
            // When no post_type is explicitly specified, include all standard property and project post types
            $propertyPostTypeSlugs = [
                'property-listing',
                'property',
                'project',
                'builder-project',
                'consultancy-project',
                'agent-project',
            ];

            $postTypeIds = DB::table('post_types')
                ->whereIn('slug', $propertyPostTypeSlugs)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (!empty($postTypeIds)) {
                $query->whereIn('dynamic_posts.post_type_id', $postTypeIds);
            }

            return;
        }

        if (is_string($postType) && mb_strtolower(trim($postType)) === 'all') {
            return;
        }

        $slugs = is_array($postType) ? $postType : explode(',', (string) $postType);
        $slugs = array_map(fn ($s) => mb_strtolower(trim((string) $s)), $slugs);
        $slugs = array_filter($slugs, fn ($s) => $s !== '');

        if (empty($slugs)) {
            return;
        }

        $numericIds = array_filter($slugs, 'is_numeric');
        $stringSlugs = array_diff($slugs, $numericIds);

        $postTypeIds = DB::table('post_types')
            ->where(function ($q) use ($numericIds, $stringSlugs): void {
                if (!empty($numericIds)) {
                    $q->whereIn('id', array_map('intval', $numericIds));
                }
                if (!empty($stringSlugs)) {
                    $q->orWhereIn('slug', $stringSlugs);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!empty($postTypeIds)) {
            $query->whereIn('dynamic_posts.post_type_id', $postTypeIds);
        }
    }

    private function applyPromotionFilters(Builder $query, array $filters): void
    {
        $isSponsored = $filters['is_sponsored'] ?? $filters['sponsored'] ?? null;
        $isFeatured = $filters['is_featured'] ?? $filters['featured'] ?? null;
        $promotionType = isset($filters['promotion_type']) && is_string($filters['promotion_type'])
            ? mb_strtolower(trim($filters['promotion_type']))
            : null;

        if ($promotionType === 'sponsored') {
            $isSponsored = true;
        } elseif ($promotionType === 'featured') {
            $isFeatured = true;
        }

        if ($isSponsored !== null) {
            $isSponsoredBool = is_bool($isSponsored)
                ? $isSponsored
                : in_array($isSponsored, [1, '1', 'true', 'yes'], true);

            if ($isSponsoredBool) {
                $query->where(function (Builder $q) use ($promotionType): void {
                    if (Schema::hasTable('property_featured_promotions')) {
                        $q->whereHas('featuredPromotions', function (Builder $sub) use ($promotionType): void {
                            $sub->whereNull('cancelled_at')
                                ->where(function (Builder $statusQuery): void {
                                    $statusQuery->whereIn('status', [PropertyFeaturedPromotion::STATUS_ACTIVE, '1', 1, 'active', 'approved'])
                                        ->orWhereNull('status');
                                })
                                ->where(function (Builder $st): void {
                                    $st->whereNull('starts_at')
                                        ->orWhere('starts_at', '<=', now());
                                })
                                ->where(function (Builder $et): void {
                                    $et->whereNull('ends_at')
                                        ->orWhere('ends_at', '>=', now());
                                });

                            if ($promotionType === 'sponsored') {
                                $sub->where('promotion_type', PropertyFeaturedPromotion::TYPE_SPONSORED);
                            }
                        });
                    }

                    if (Schema::hasColumn('dynamic_posts', 'is_sponsored')) {
                        $q->orWhere('dynamic_posts.is_sponsored', 1);
                    }

                    if (Schema::hasColumn('dynamic_posts', 'sponsored')) {
                        $q->orWhere('dynamic_posts.sponsored', 1);
                    }

                    if (Schema::hasColumn('dynamic_posts', 'is_featured')) {
                        $q->orWhere('dynamic_posts.is_featured', 1);
                    }

                    if (Schema::hasColumn('dynamic_posts', 'featured')) {
                        $q->orWhere('dynamic_posts.featured', 1);
                    }
                });
            } else {
                $query->where(function (Builder $q): void {
                    if (Schema::hasTable('property_featured_promotions')) {
                        $q->whereDoesntHave('featuredPromotions', function (Builder $sub): void {
                            $sub->whereNull('cancelled_at')
                                ->where(function (Builder $statusQuery): void {
                                    $statusQuery->whereIn('status', [PropertyFeaturedPromotion::STATUS_ACTIVE, '1', 1, 'active', 'approved'])
                                        ->orWhereNull('status');
                                })
                                ->where(function (Builder $st): void {
                                    $st->whereNull('starts_at')
                                        ->orWhere('starts_at', '<=', now());
                                })
                                ->where(function (Builder $et): void {
                                    $et->whereNull('ends_at')
                                        ->orWhere('ends_at', '>=', now());
                                });
                        });
                    }

                    if (Schema::hasColumn('dynamic_posts', 'is_sponsored')) {
                        $q->where(function (Builder $sub): void {
                            $sub->whereNull('dynamic_posts.is_sponsored')
                                ->orWhere('dynamic_posts.is_sponsored', 0);
                        });
                    }
                });
            }
        } elseif ($isFeatured !== null) {
            $isFeaturedBool = is_bool($isFeatured)
                ? $isFeatured
                : in_array($isFeatured, [1, '1', 'true', 'yes'], true);

            if ($isFeaturedBool) {
                $query->where(function (Builder $q) use ($promotionType): void {
                    if (Schema::hasTable('property_featured_promotions')) {
                        $q->whereHas('featuredPromotions', function (Builder $sub) use ($promotionType): void {
                            $sub->whereNull('cancelled_at')
                                ->where(function (Builder $statusQuery): void {
                                    $statusQuery->whereIn('status', [PropertyFeaturedPromotion::STATUS_ACTIVE, '1', 1, 'active', 'approved'])
                                        ->orWhereNull('status');
                                })
                                ->where(function (Builder $st): void {
                                    $st->whereNull('starts_at')
                                        ->orWhere('starts_at', '<=', now());
                                })
                                ->where(function (Builder $et): void {
                                    $et->whereNull('ends_at')
                                        ->orWhere('ends_at', '>=', now());
                                });

                            if ($promotionType === 'featured') {
                                $sub->where('promotion_type', PropertyFeaturedPromotion::TYPE_FEATURED);
                            }
                        });
                    }

                    if (Schema::hasColumn('dynamic_posts', 'is_featured')) {
                        $q->orWhere('dynamic_posts.is_featured', 1);
                    }

                    if (Schema::hasColumn('dynamic_posts', 'featured')) {
                        $q->orWhere('dynamic_posts.featured', 1);
                    }
                });
            }
        }
    }

    private function applySort(Builder $query, array $filters): void
    {
        $sortBy = (string) ($filters['sort_by'] ?? 'newest');

        if ($sortBy === 'oldest') {
            $query->orderBy('dynamic_posts.id', 'asc');

            return;
        }

        if ($sortBy === 'price_low') {
            $query
                ->orderByRaw('search_price IS NULL ASC')
                ->orderBy('search_price', 'asc')
                ->orderBy('dynamic_posts.id', 'desc');

            return;
        }

        if ($sortBy === 'price_high') {
            $query
                ->orderByRaw('search_price IS NULL ASC')
                ->orderBy('search_price', 'desc')
                ->orderBy('dynamic_posts.id', 'desc');

            return;
        }

        if (
            $sortBy === 'relevance'
            && trim((string) ($filters['search'] ?? '')) !== ''
        ) {
            $search = trim((string) $filters['search']);

            if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                $query->orderByRaw(
                    'CASE
                        WHEN dynamic_posts.title = ? THEN 0
                        WHEN dynamic_posts.title LIKE ? THEN 1
                        WHEN dynamic_posts.listing_code LIKE ? THEN 2
                        ELSE 3
                    END',
                    [
                        $search,
                        $search . '%',
                        '%' . $search . '%',
                    ]
                );
            } else {
                $query->orderByRaw(
                    'CASE
                        WHEN dynamic_posts.title = ? THEN 0
                        WHEN dynamic_posts.title LIKE ? THEN 1
                        ELSE 2
                    END',
                    [
                        $search,
                        $search . '%',
                    ]
                );
            }

            $query
                ->orderByDesc('dynamic_posts.published_at')
                ->orderByDesc('dynamic_posts.id');

            return;
        }

        $query
            ->orderByDesc('dynamic_posts.published_at')
            ->orderByDesc('dynamic_posts.id');
    }

    private function formatCompleteListing(
        DynamicPost $post,
        Collection $mediaById,
        array $repeaterValues,
        array $keywords,
        array $relationships
    ): array {
        // This preserves every selected dynamic_posts column plus eager-loaded relations.
        $data = $post->toArray();

        $countryName = $post->getAttribute('search_country_name');
        $stateName = $post->getAttribute('search_state_name');
        $cityName = $post->getAttribute('search_city_name');

        unset(
            $data['search_country_name'],
            $data['search_state_name'],
            $data['search_city_name']
        );

        $price = $this->priceFromPost($post);

        if ($price === null && $post->getAttribute('search_price') !== null) {
            $price = (float) $post->getAttribute('search_price');
        }

        unset($data['search_price']);

        $featuredMedia = null;

        if (!empty($post->featured_image_id)) {
            $featured = $mediaById->get((int) $post->featured_image_id);

            if ($featured instanceof MediaFile) {
                $featuredMedia = $this->formatMediaFile($featured);
            }
        }

        $galleryIds = $this->normalizeIds($post->gallery_image_ids ?? []);

        $galleryMedia = collect($galleryIds)
            ->map(function (int $mediaId) use ($mediaById): ?array {
                $media = $mediaById->get($mediaId);

                return $media instanceof MediaFile
                    ? $this->formatMediaFile($media)
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        $terms = collect($post->taxonomyTerms ?? collect());

        $latestRevision = $post->latestVerificationRevision;

        $workflowStatus = $latestRevision?->status
            ?: $this->workflowStatusFromLegacy(
                $post->status ?? null,
                $post->live_status ?? null
            );

        $data['price'] = $price;
        $data['display_price'] = $this->displayPrice($price);

        $data['post_type'] = $post->postType
            ? [
                'id' => (int) $post->postType->id,
                'name' => $post->postType->name,
                'slug' => $post->postType->slug,
            ]
            : null;

        $data['location'] = [
            'country_id' => $post->country_id ? (int) $post->country_id : null,
            'country_name' => $countryName,
            'state_id' => $post->state_id ? (int) $post->state_id : null,
            'state_name' => $stateName,
            'city_id' => $post->city_id ? (int) $post->city_id : null,
            'city_name' => $cityName,
            'area_locality' => $post->area_locality,
            'full_address' => collect([
                $post->area_locality,
                $cityName,
                $stateName,
                $countryName,
            ])->filter(fn ($value) => $value !== null && $value !== '')
                ->implode(', '),
        ];

        $data['country_name'] = $countryName;
        $data['state_name'] = $stateName;
        $data['city_name'] = $cityName;
        $data['full_address'] = $data['location']['full_address'];

        $data['selected_taxonomies'] = $this->formatSelectedTaxonomies($terms);

        $data['purpose'] = $this->firstTermForKey($terms, 'purpose');
        $data['property_type'] = $this->firstTermForKey($terms, 'property_type');
        $data['bedrooms'] = $this->bedroomFromPost($post);

        $data['custom_fields'] = $this->formatCustomFields(
            $post,
            $repeaterValues
        );

        // Keep "meta" as well because the caller asked for complete listing data.
        $data['meta'] = $post->meta?->toArray() ?? [];

        $data['featured_image'] = $featuredMedia['url'] ?? null;
        $data['featured_image_media'] = $featuredMedia;
        $data['gallery_images'] = collect($galleryMedia)
            ->pluck('url')
            ->filter()
            ->values()
            ->all();
        $data['gallery_image_files'] = $galleryMedia;

        $data['keywords'] = $keywords;
        $data['selected_keywords'] = $keywords;
        $data['related_posts'] = $relationships;

        $data['workflow_status'] = $workflowStatus;
        $data['verification_status'] = $workflowStatus;
        $data['review_status_label'] = $this->reviewStatusLabel($workflowStatus);

        $data['latest_verification_revision'] = $latestRevision
            ? [
                'id' => (int) $latestRevision->id,
                'dynamic_post_id' => (int) $latestRevision->dynamic_post_id,
                'version' => (int) $latestRevision->version,
                'source' => $latestRevision->source,
                'status' => $latestRevision->status,
                'submitted_by' => $latestRevision->submitted_by
                    ? (int) $latestRevision->submitted_by
                    : null,
                'assigned_to' => $latestRevision->assigned_to
                    ? (int) $latestRevision->assigned_to
                    : null,
                'assigned_by' => $latestRevision->assigned_by
                    ? (int) $latestRevision->assigned_by
                    : null,
                'decided_by' => $latestRevision->decided_by
                    ? (int) $latestRevision->decided_by
                    : null,
                'submitted_at' => optional($latestRevision->submitted_at)->toISOString(),
                'assigned_at' => optional($latestRevision->assigned_at)->toISOString(),
                'verification_started_at' => optional($latestRevision->verification_started_at)->toISOString(),
                'decided_at' => optional($latestRevision->decided_at)->toISOString(),
                'rejection_reason' => $latestRevision->rejection_reason,
            ]
            : null;

        $data['availability'] = $this->availabilityData($post);

        $featuredPromotion = $post->currentFeaturedPromotion;
        $isFeatured = $featuredPromotion !== null;
        $isSponsored = $featuredPromotion !== null && $featuredPromotion->promotion_type === PropertyFeaturedPromotion::TYPE_SPONSORED;

        if (!$isSponsored && Schema::hasColumn('dynamic_posts', 'is_sponsored') && !empty($post->is_sponsored)) {
            $isSponsored = true;
        }

        $data['is_featured'] = $isFeatured;
        $data['is_sponsored'] = $isSponsored;
        $data['promotion_type'] = $featuredPromotion?->promotion_type ?? ($isSponsored ? 'sponsored' : ($isFeatured ? 'featured' : null));
        $data['featured_promotion'] = $featuredPromotion ? [
            'id' => (int) $featuredPromotion->id,
            'promotion_type' => $featuredPromotion->promotion_type,
            'source' => $featuredPromotion->source,
            'status' => $featuredPromotion->status,
            'display_label' => $featuredPromotion->promotion_type === PropertyFeaturedPromotion::TYPE_SPONSORED ? 'Sponsored' : 'Featured',
        ] : null;
        $data['promotion_label'] = $featuredPromotion
            ? ($featuredPromotion->promotion_type === PropertyFeaturedPromotion::TYPE_SPONSORED ? 'Sponsored' : 'Featured')
            : ($isSponsored ? 'Sponsored' : null);

        $data['is_active'] =
            ($post->status ?? null) === 'published'
            && ($post->live_status ?? null) === 'approve';

        return $data;
    }

    private function formatSelectedTaxonomies(Collection $terms): array
    {
        return $terms
            ->groupBy('taxonomy_id')
            ->map(function (Collection $taxonomyTerms): ?array {
                $first = $taxonomyTerms->first();
                $taxonomy = $first?->taxonomy;

                if (!$taxonomy) {
                    return null;
                }

                return [
                    'taxonomy_id' => (int) $taxonomy->id,
                    'taxonomy_name' => $taxonomy->name,
                    'taxonomy_slug' => $taxonomy->slug,
                    'selected_term_ids' => $taxonomyTerms
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all(),
                    'selected_terms' => $taxonomyTerms
                        ->map(fn ($term) => [
                            'id' => (int) $term->id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function formatCustomFields(
        DynamicPost $post,
        array $repeaterValues
    ): array {
        return collect($post->meta ?? collect())
            ->map(function ($meta) use ($repeaterValues): array {
                $field = $meta->customField;

                $value = $this->customFieldRawValue($meta);

                $item = [
                    'id' => isset($meta->id) ? (int) $meta->id : null,
                    'entity_type' => $meta->entity_type ?? 'post',
                    'entity_id' => isset($meta->entity_id) ? (int) $meta->entity_id : null,
                    'custom_field_id' => isset($meta->custom_field_id)
                        ? (int) $meta->custom_field_id
                        : null,
                    'custom_field_option_id' => !empty($meta->custom_field_option_id)
                        ? (int) $meta->custom_field_option_id
                        : null,
                    'value_text' => $meta->value_text ?? null,
                    'value_string' => $meta->value_string ?? null,
                    'value_number' => $meta->value_number ?? null,
                    'value_date' => $meta->value_date ?? null,
                    'value_datetime' => $meta->value_datetime ?? null,
                    'value_json' => $meta->value_json ?? null,
                    'value' => $value,
                    'custom_field' => $field?->toArray(),
                ];

                if (
                    $field
                    && ($field->field_type ?? null) === 'repeater'
                ) {
                    $item['repeater_values'] =
                        $repeaterValues[(int) $field->id] ?? [];
                }

                return $item;
            })
            ->values()
            ->all();
    }

    private function customFieldRawValue($meta): mixed
    {
        if (!empty($meta->custom_field_option_id)) {
            $field = $meta->customField;

            if ($field && $field->relationLoaded('options')) {
                $option = $field->options->firstWhere(
                    'id',
                    (int) $meta->custom_field_option_id
                );

                if ($option) {
                    return $option->name
                        ?? $option->value
                        ?? (int) $option->id;
                }
            }
        }

        foreach ([
            'value_number',
            'value_string',
            'value_text',
            'value_date',
            'value_datetime',
            'value_json',
        ] as $column) {
            $value = $meta->{$column} ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function availabilityData(DynamicPost $post): array
    {
        $status = $post->availability_status ?? null;
        $pending = $post->availability_pending_status ?? null;

        return [
            'status' => $status,
            'pending_status' => $pending,
            'review_pending' => !empty($pending),
            'public_until' => $this->dateToIso($post->availability_public_until ?? null),
            'hidden_at' => $this->dateToIso($post->availability_hidden_at ?? null),
            'changed_at' => $this->dateToIso($post->availability_changed_at ?? null),
            'sold_at' => $this->dateToIso($post->sold_at ?? null),
        ];
    }

    private function dateToIso(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toISOString')) {
            return $value->toISOString();
        }

        return $value;
    }

    private function mediaByIdForPosts(Collection $posts): Collection
    {
        $ids = [];

        foreach ($posts as $post) {
            if (!empty($post->featured_image_id)) {
                $ids[] = (int) $post->featured_image_id;
            }

            $ids = array_merge(
                $ids,
                $this->normalizeIds($post->gallery_image_ids ?? [])
            );
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return collect();
        }

        return MediaFile::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (MediaFile $media) => (int) $media->id);
    }

    private function formatMediaFile(MediaFile $media): array
    {
        $path = trim((string) ($media->path ?? ''));
        $disk = $media->disk ?: 'public';

        $url = null;

        if ($path !== '') {
            if (
                Str::startsWith($path, 'http://')
                || Str::startsWith($path, 'https://')
            ) {
                $url = $path;
            } else {
                try {
                    $url = Storage::disk($disk)->url($path);
                } catch (\Throwable) {
                    $url = url($path);
                }
            }
        }

        return [
            'id' => (int) $media->id,
            'disk' => $disk,
            'context' => $media->context ?? null,
            'post_type_slug' => $media->post_type_slug ?? null,
            'field_slug' => $media->field_slug ?? null,
            'directory' => $media->directory ?? null,
            'path' => $media->path ?? null,
            'url' => $url,
            'file_name' => $media->file_name ?? null,
            'original_name' => $media->original_name ?? null,
            'mime_type' => $media->mime_type ?? null,
            'extension' => $media->extension ?? null,
            'size' => $media->size ?? null,
            'size_kb' => !empty($media->size)
                ? round(((int) $media->size) / 1024, 2)
                : null,
        ];
    }

    private function repeaterValuesByPost(array $postIds): array
    {
        if (
            empty($postIds)
            || !Schema::hasTable('custom_field_repeater_values')
            || !Schema::hasColumn('custom_field_repeater_values', 'entity_id')
        ) {
            return [];
        }

        $query = DB::table('custom_field_repeater_values')
            ->whereIn('entity_id', $postIds);

        if (Schema::hasColumn('custom_field_repeater_values', 'entity_type')) {
            $query->where('entity_type', 'post');
        }

        if (Schema::hasColumn('custom_field_repeater_values', 'row_index')) {
            $query->orderBy('row_index');
        }

        if (Schema::hasColumn('custom_field_repeater_values', 'sort_order')) {
            $query->orderBy('sort_order');
        }

        $rows = $query->get();

        $result = [];

        foreach ($rows as $row) {
            $postId = (int) ($row->entity_id ?? 0);
            $customFieldId = (int) ($row->custom_field_id ?? 0);
            $rowIndex = (int) ($row->row_index ?? 0);
            $slug = (string) ($row->field_name_slug ?? '');

            if (!$postId || !$customFieldId || $slug === '') {
                continue;
            }

            $value = null;

            foreach ([
                'field_meta_value',
                'value_string',
                'value_text',
                'value_number',
                'value_date',
                'value_datetime',
                'value_json',
            ] as $column) {
                if (!property_exists($row, $column)) {
                    continue;
                }

                $candidate = $row->{$column};

                if ($candidate !== null && $candidate !== '') {
                    $value = $this->decodeJsonValue($candidate);
                    break;
                }
            }

            $result[$postId][$customFieldId][$rowIndex][$slug] = $value;
        }

        foreach ($result as $postId => $fields) {
            foreach ($fields as $fieldId => $rows) {
                ksort($rows);
                $result[$postId][$fieldId] = array_values($rows);
            }
        }

        return $result;
    }

    private function keywordsByPost(array $postIds): array
    {
        if (
            empty($postIds)
            || !Schema::hasTable('keywords')
            || !Schema::hasTable('keyword_dynamic_post')
        ) {
            return [];
        }

        return DB::table('keyword_dynamic_post as kdp')
            ->join('keywords as k', 'k.id', '=', 'kdp.keyword_id')
            ->whereIn('kdp.dynamic_post_id', $postIds)
            ->select([
                'kdp.dynamic_post_id',
                'k.id',
                'k.keyword',
                'k.status',
            ])
            ->orderBy('k.keyword')
            ->get()
            ->groupBy('dynamic_post_id')
            ->map(function (Collection $rows): array {
                return $rows
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'value' => $row->keyword,
                        'label' => $row->keyword,
                        'keyword' => $row->keyword,
                        'status' => $row->status,
                    ])
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function relationshipsByPost(array $postIds): array
    {
        if (
            empty($postIds)
            || !Schema::hasTable('dynamic_post_relationships')
        ) {
            return [];
        }

        $rows = DB::table('dynamic_post_relationships as dpr')
            ->join(
                'dynamic_posts as related_post',
                'related_post.id',
                '=',
                'dpr.related_post_id'
            )
            ->leftJoin(
                'post_types as related_type',
                'related_type.id',
                '=',
                'dpr.related_post_type_id'
            )
            ->whereIn('dpr.dynamic_post_id', $postIds)
            ->select([
                'dpr.dynamic_post_id',
                'dpr.related_post_type_id',
                'dpr.related_post_id',
                'related_type.name as related_post_type_name',
                'related_type.slug as related_post_type_slug',
                'related_post.title',
                'related_post.slug',
                'related_post.status',
                'related_post.live_status',
            ])
            ->get();

        return $rows
            ->groupBy('dynamic_post_id')
            ->map(function (Collection $items): array {
                return $items
                    ->map(fn ($row) => [
                        'post_type_id' => (int) $row->related_post_type_id,
                        'post_type_name' => $row->related_post_type_name,
                        'post_type_slug' => $row->related_post_type_slug,
                        'post_id' => (int) $row->related_post_id,
                        'title' => $row->title,
                        'slug' => $row->slug,
                        'status' => $row->status,
                        'live_status' => $row->live_status,
                    ])
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function propertyPostTypeId(): int
    {
        $slug = config('property_search.post_type_slug', 'property-listing');

        $id = DB::table('post_types')
            ->where('slug', $slug)
            ->value('id');

        if (!$id) {
            abort(404, 'Property listing post type not found.');
        }

        return (int) $id;
    }

    private function taxonomyOptions(string $key): array
    {
        $taxonomySlugs = $this->taxonomySlugs($key);

        if (empty($taxonomySlugs)) {
            return [];
        }

        $taxonomyIds = DB::table('taxonomies')
            ->whereIn('slug', $taxonomySlugs)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($taxonomyIds)) {
            return [];
        }

        return DB::table('taxonomy_terms')
            ->select([
                'id',
                'taxonomy_id',
                'parent_id',
                'name',
                'slug',
            ])
            ->whereIn('taxonomy_id', $taxonomyIds)
            ->when(
                Schema::hasColumn('taxonomy_terms', 'status'),
                fn ($q) => $q->where('status', true)
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($term) => [
                'id' => (int) $term->id,
                'value' => $term->slug,
                'label' => $term->name,
                'name' => $term->name,
                'slug' => $term->slug,
                'taxonomy_id' => (int) $term->taxonomy_id,
                'parent_id' => $term->parent_id
                    ? (int) $term->parent_id
                    : null,
            ])
            ->values()
            ->all();
    }

    private function bedroomOptions(): array
    {
        $taxonomyOptions = $this->taxonomyOptions('bedrooms');

        if (!empty($taxonomyOptions)) {
            return $taxonomyOptions;
        }

        $fieldIds = $this->bedroomFieldIds();

        if (empty($fieldIds)) {
            return [];
        }

        $options = collect();

        if (Schema::hasTable('custom_field_options')) {
            $optionColumns = ['id', 'custom_field_id', 'name'];

            if (Schema::hasColumn('custom_field_options', 'value')) {
                $optionColumns[] = 'value';
            }

            $options = DB::table('custom_field_options')
                ->select($optionColumns)
                ->whereIn('custom_field_id', $fieldIds)
                ->when(
                    Schema::hasColumn('custom_field_options', 'status'),
                    fn ($q) => $q->where('status', true)
                )
                ->orderBy('id')
                ->get()
                ->map(function ($option): array {
                    $value = property_exists($option, 'value')
                        && $option->value !== null
                        && $option->value !== ''
                        ? $option->value
                        : $option->name;

                    return [
                        'id' => (int) $option->id,
                        'value' => $value,
                        'label' => $option->name,
                        'name' => $option->name,
                        'custom_field_id' => (int) $option->custom_field_id,
                    ];
                });
        }

        if ($options->isNotEmpty()) {
            return $options
                ->unique(fn (array $item) => mb_strtolower((string) $item['value']))
                ->values()
                ->all();
        }

        if (!Schema::hasTable('custom_field_values')) {
            return [];
        }

        $rows = DB::table('custom_field_values')
            ->where('entity_type', 'post')
            ->whereIn('custom_field_id', $fieldIds)
            ->select([
                'value_number',
                'value_string',
                'value_text',
            ])
            ->get()
            ->map(function ($row): mixed {
                foreach ([
                    $row->value_number ?? null,
                    $row->value_string ?? null,
                    $row->value_text ?? null,
                ] as $value) {
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }

                return null;
            })
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->unique(fn ($value) => mb_strtolower(trim((string) $value)))
            ->sortBy(fn ($value) => is_numeric($value) ? (float) $value : PHP_FLOAT_MAX)
            ->values();

        return $rows
            ->map(fn ($value) => [
                'id' => null,
                'value' => (string) $value,
                'label' => (string) $value,
                'name' => (string) $value,
            ])
            ->all();
    }

    private function taxonomySlugs(string $key): array
    {
        $configured = config("property_search.taxonomy_slugs.{$key}", []);

        if (is_string($configured)) {
            $configured = [$configured];
        }

        $fallback = match ($key) {
            'purpose' => [
                'purpose',
                'property-purpose',
                'property_purpose',
            ],
            'property_type' => [
                'property-type',
                'property_type',
            ],
            'bedrooms' => [
                'bedrooms',
                'bedroom',
                'bhk',
            ],
            default => [],
        };

        return collect(array_merge(
            is_array($configured) ? $configured : [],
            $fallback
        ))
            ->map(fn ($slug) => Str::slug((string) $slug))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function resolveTaxonomyTermIds(
        string $taxonomyKey,
        array $values
    ): array {
        $taxonomySlugs = $this->taxonomySlugs($taxonomyKey);

        if (empty($taxonomySlugs) || empty($values)) {
            return [];
        }

        if ($taxonomyKey === 'purpose') {
            $expandedValues = [];
            foreach ($values as $val) {
                $norm = mb_strtolower(trim((string) $val));
                if (in_array($norm, ['buy', 'sell', 'sale', 'for-sale', 'purchase'], true)) {
                    $expandedValues = array_merge($expandedValues, ['sell', 'sale', 'buy', 'for-sale', 'purchase']);
                } elseif (in_array($norm, ['rent', 'rental', 'lease', 'for-rent'], true)) {
                    $expandedValues = array_merge($expandedValues, ['rent', 'rental', 'lease', 'for-rent']);
                } else {
                    $expandedValues[] = $val;
                }
            }
            $values = array_values(array_unique($expandedValues));
        }

        $query = DB::table('taxonomy_terms as tt')
            ->join('taxonomies as t', 't.id', '=', 'tt.taxonomy_id')
            ->whereIn('t.slug', $taxonomySlugs)
            ->where(function ($matchQuery) use ($values): void {
                foreach ($values as $value) {
                    $normalized = trim((string) $value);

                    if ($normalized === '') {
                        continue;
                    }

                    $matchQuery->orWhere(function ($single) use ($normalized): void {
                        if (ctype_digit($normalized)) {
                            $single->where('tt.id', (int) $normalized);
                        }

                        $single
                            ->orWhereRaw(
                                'LOWER(tt.slug) = ?',
                                [mb_strtolower(Str::slug($normalized))]
                            )
                            ->orWhereRaw(
                                'LOWER(tt.name) = ?',
                                [mb_strtolower($normalized)]
                            );
                    });
                }
            });

        return $query
            ->pluck('tt.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function taxonomyIdForTerm(int $termId): ?int
    {
        $taxonomyId = DB::table('taxonomy_terms')
            ->where('id', $termId)
            ->value('taxonomy_id');

        return $taxonomyId ? (int) $taxonomyId : null;
    }

    private function priceFieldIds(): array
    {
        return $this->customFieldIdsBySlugs(
            config('property_search.price_field_slugs', [
                'price',
                'property_price',
                'property-price',
            ]),
            [
                'price',
                'property_price',
                'property-price',
            ]
        );
    }

    private function bedroomFieldIds(): array
    {
        return $this->customFieldIdsBySlugs(
            config('property_search.bedroom_field_slugs', [
                'bedrooms',
                'bedroom',
                'bhk',
            ]),
            [
                'bedrooms',
                'bedroom',
                'bhk',
            ]
        );
    }

    private function customFieldIdsBySlugs(
        mixed $configured,
        array $fallback
    ): array {
        if (!Schema::hasTable('custom_fields')) {
            return [];
        }

        if (is_string($configured)) {
            $configured = [$configured];
        }

        $slugs = collect(array_merge(
            is_array($configured) ? $configured : [],
            $fallback
        ))
            ->map(fn ($slug) => trim((string) $slug))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return DB::table('custom_fields')
            ->whereIn('field_name_slug', $slugs)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function priceSqlExpression(string $alias): string
    {
        /*
         * Current production data stores price inconsistently:
         * - value_number
         * - value_string
         * - value_text
         *
         * MySQL 8 REGEXP guard prevents non-numeric HTML/text from becoming 0.
         */
        $stringValue = "TRIM(REPLACE(REPLACE(REPLACE(COALESCE({$alias}.value_string, ''), ',', ''), '₹', ''), ' ', ''))";
        $textValue = "TRIM(REPLACE(REPLACE(REPLACE(COALESCE({$alias}.value_text, ''), ',', ''), '₹', ''), ' ', ''))";

        return "CASE
            WHEN {$alias}.value_number IS NOT NULL
                THEN CAST({$alias}.value_number AS DECIMAL(18,2))
            WHEN {$stringValue} REGEXP '^[0-9]+([.][0-9]+)?$'
                THEN CAST({$stringValue} AS DECIMAL(18,2))
            WHEN {$textValue} REGEXP '^[0-9]+([.][0-9]+)?$'
                THEN CAST({$textValue} AS DECIMAL(18,2))
            ELSE NULL
        END";
    }

    private function priceFromPost(DynamicPost $post): ?float
    {
        $priceSlugs = collect(array_merge(
            (array) config('property_search.price_field_slugs', []),
            ['price', 'property_price', 'property-price']
        ))
            ->map(fn ($slug) => trim((string) $slug))
            ->filter()
            ->unique()
            ->all();

        foreach ($post->meta ?? collect() as $meta) {
            $slug = $meta->customField?->field_name_slug;

            if (!$slug || !in_array($slug, $priceSlugs, true)) {
                continue;
            }

            foreach ([
                $meta->value_number ?? null,
                $meta->value_string ?? null,
                $meta->value_text ?? null,
            ] as $candidate) {
                $number = $this->numericValue($candidate);

                if ($number !== null) {
                    return $number;
                }
            }
        }

        return null;
    }

    private function bedroomFromPost(DynamicPost $post): mixed
    {
        $bedroomSlugs = collect(array_merge(
            (array) config('property_search.bedroom_field_slugs', []),
            ['bedrooms', 'bedroom', 'bhk']
        ))
            ->map(fn ($slug) => trim((string) $slug))
            ->filter()
            ->unique()
            ->all();

        foreach ($post->meta ?? collect() as $meta) {
            $slug = $meta->customField?->field_name_slug;

            if (!$slug || !in_array($slug, $bedroomSlugs, true)) {
                continue;
            }

            return $this->customFieldRawValue($meta);
        }

        $term = $this->firstTermForKey(
            collect($post->taxonomyTerms ?? collect()),
            'bedrooms'
        );

        return $term;
    }

    private function firstTermForKey(
        Collection $terms,
        string $key
    ): ?array {
        $allowedSlugs = $this->taxonomySlugs($key);

        $term = $terms->first(function ($term) use ($allowedSlugs): bool {
            $taxonomySlug = $term->taxonomy?->slug;

            return $taxonomySlug
                && in_array($taxonomySlug, $allowedSlugs, true);
        });

        if (!$term) {
            return null;
        }

        return [
            'id' => (int) $term->id,
            'name' => $term->name,
            'slug' => $term->slug,
            'taxonomy_id' => (int) $term->taxonomy_id,
            'taxonomy_name' => $term->taxonomy?->name,
            'taxonomy_slug' => $term->taxonomy?->slug,
        ];
    }

    private function normalizeFilterValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
            ) {
                $value = $decoded;
            } elseif (str_contains($trimmed, ',')) {
                $value = explode(',', $trimmed);
            } else {
                $value = [$trimmed];
            }
        } elseif (!is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->map(function ($item): mixed {
                if (is_array($item)) {
                    return $item['value']
                        ?? $item['id']
                        ?? $item['slug']
                        ?? $item['name']
                        ?? null;
                }

                if (is_object($item)) {
                    return $item->value
                        ?? $item->id
                        ?? $item->slug
                        ?? $item->name
                        ?? null;
                }

                return $item;
            })
            ->filter(fn ($item) => $item !== null && trim((string) $item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeIds(mixed $ids): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
            ) {
                $ids = $decoded;
            } else {
                $ids = str_contains($ids, ',')
                    ? explode(',', $ids)
                    : [$ids];
            }
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return collect($ids)
            ->map(function ($id): mixed {
                if (is_array($id)) {
                    return $id['id'] ?? $id['value'] ?? null;
                }

                if (is_object($id)) {
                    return $id->id ?? $id->value ?? null;
                }

                return $id;
            })
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function numericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace(
            [',', '₹', ' '],
            '',
            trim(strip_tags($value))
        );

        return is_numeric($normalized)
            ? (float) $normalized
            : null;
    }

    private function displayPrice(mixed $price): ?string
    {
        $price = $this->numericValue($price);

        if ($price === null) {
            return null;
        }

        if ($price >= 10000000) {
            return '₹' . round($price / 10000000, 2) . ' Cr';
        }

        if ($price >= 100000) {
            return '₹' . round($price / 100000, 2) . ' Lac';
        }

        return '₹' . number_format($price);
    }

    private function workflowStatusFromLegacy(
        ?string $status,
        ?string $liveStatus
    ): string {
        if ($status === 'published' && $liveStatus === 'approve') {
            return 'approved';
        }

        if (in_array($liveStatus, ['reject', 'disapprove'], true)) {
            return 'rejected';
        }

        if (in_array($liveStatus, ['under_review', 'submit'], true)) {
            return 'under_review';
        }

        return 'pending';
    }

    private function reviewStatusLabel(?string $status): string
    {
        return match ($status) {
            'approve', 'approved' => 'Approved',
            'reject', 'rejected' => 'Rejected',
            'disapprove' => 'Disapproved',
            'under_review' => 'Under Review',
            'resubmission' => 'Resubmitted',
            'assigned' => 'Assigned',
            'in_verification' => 'In Verification',
            'modify_review' => 'Modification Required',
            'submit' => 'Submitted',
            default => 'Pending',
        };
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $value;
    }
}
