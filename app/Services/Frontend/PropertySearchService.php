<?php

namespace App\Services\Frontend;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PropertySearchService
{
    public function options(): array
    {
        return Cache::store('redis')->remember(
            'frontend:property-search:options',
            now()->addHours(6),
            function () {
                return [
                    'tabs' => $this->taxonomyOptions('purpose'),
                    'property_types' => $this->taxonomyOptions('property_type'),
                    'bedrooms' => $this->taxonomyOptions('bedrooms'),
                    'budget_options' => config('property_search.budget_options', []),
                ];
            }
        );
    }

    public function locationSuggestions(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return [];
        }

        $cacheKey = 'frontend:property-search:locations:' . md5(mb_strtolower($search));

        return Cache::store('redis')->remember($cacheKey, now()->addMinutes(30), function () use ($search) {
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
                    ->when(Schema::hasColumn('cities', 'status'), fn ($q) => $q->where('cities.status', 1))
                    ->orderBy('cities.name')
                    ->limit(10)
                    ->get()
                    ->map(function ($city) {
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

            if (Schema::hasTable('dynamic_posts') && Schema::hasColumn('dynamic_posts', 'area_locality')) {
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
                    ->map(function ($row) {
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

            return $results->unique(fn ($item) => $item['type'] . ':' . $item['label'])
                ->values()
                ->all();
        });
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $postTypeId = $this->propertyPostTypeId();

        $perPage = min(
            max((int) ($filters['per_page'] ?? config('property_search.default_per_page', 20)), 1),
            (int) config('property_search.max_per_page', 50)
        );

        $termIds = $this->resolveFilterTermIds($filters);
        $priceFieldIds = $this->priceFieldIds();

        $query = DB::table('dynamic_posts as p')
            ->leftJoin('countries as country', 'country.id', '=', 'p.country_id')
            ->leftJoin('states as state', 'state.id', '=', 'p.state_id')
            ->leftJoin('cities as city', 'city.id', '=', 'p.city_id')
            ->when(! empty($priceFieldIds), function ($q) use ($priceFieldIds) {
                $q->leftJoin('custom_field_values as price_meta', function ($join) use ($priceFieldIds) {
                    $join->on('price_meta.entity_id', '=', 'p.id')
                        ->where('price_meta.entity_type', '=', 'post')
                        ->whereIn('price_meta.custom_field_id', $priceFieldIds);
                });
            })
            ->where('p.post_type_id', $postTypeId)
            ->where('p.status', 'published')
            ->where('p.live_status', 'approve')
            ->when(! empty($filters['country_id']), fn ($q) => $q->where('p.country_id', (int) $filters['country_id']))
            ->when(! empty($filters['state_id']), fn ($q) => $q->where('p.state_id', (int) $filters['state_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('p.city_id', (int) $filters['city_id']))
            ->when(! empty($filters['area_locality']), function ($q) use ($filters) {
                $q->where('p.area_locality', 'like', '%' . trim((string) $filters['area_locality']) . '%');
            })
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $search = trim((string) $filters['search']);

                $q->where(function ($subQuery) use ($search) {
                    $subQuery->where('p.title', 'like', "%{$search}%")
                        ->orWhere('p.area_locality', 'like', "%{$search}%")
                        ->orWhere('city.name', 'like', "%{$search}%")
                        ->orWhere('state.name', 'like', "%{$search}%")
                        ->orWhere('country.name', 'like', "%{$search}%");
                });
            })
            ->when(! empty($termIds), function ($q) use ($termIds) {
                foreach ($termIds as $termId) {
                    $q->whereExists(function ($exists) use ($termId) {
                        $exists->selectRaw('1')
                            ->from('post_taxonomy_terms as ptt')
                            ->whereColumn('ptt.dynamic_post_id', 'p.id')
                            ->where('ptt.taxonomy_term_id', (int) $termId);
                    });
                }
            })
            ->when(! empty($priceFieldIds) && isset($filters['price_min']) && $filters['price_min'] !== '', function ($q) use ($filters) {
                $q->where('price_meta.value_number', '>=', (float) $filters['price_min']);
            })
            ->when(! empty($priceFieldIds) && isset($filters['price_max']) && $filters['price_max'] !== '', function ($q) use ($filters) {
                $q->where('price_meta.value_number', '<=', (float) $filters['price_max']);
            })
            ->select([
                'p.id',
                'p.listing_code',
                'p.title',
                'p.slug',
                'p.excerpt',
                'p.featured_image_id',
                'p.gallery_image_ids',
                'p.country_id',
                'p.state_id',
                'p.city_id',
                'p.area_locality',
                'p.published_at',
                'p.created_at',
                'country.name as country_name',
                'state.name as state_name',
                'city.name as city_name',
            ])
            ->selectRaw(! empty($priceFieldIds) ? 'MAX(price_meta.value_number) as price' : 'NULL as price')
            ->groupBy([
                'p.id',
                'p.listing_code',
                'p.title',
                'p.slug',
                'p.excerpt',
                'p.featured_image_id',
                'p.gallery_image_ids',
                'p.country_id',
                'p.state_id',
                'p.city_id',
                'p.area_locality',
                'p.published_at',
                'p.created_at',
                'country.name',
                'state.name',
                'city.name',
            ]);

        $sortBy = $filters['sort_by'] ?? 'newest';

        match ($sortBy) {
            'oldest' => $query->orderBy('p.id', 'asc'),
            'price_low' => $query->orderByRaw('price IS NULL ASC')->orderBy('price', 'asc'),
            'price_high' => $query->orderBy('price', 'desc'),
            default => $query->orderBy('p.published_at', 'desc')->orderBy('p.id', 'desc'),
        };

        $paginator = $query->paginate($perPage);

        $postIds = $paginator->getCollection()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $termsByPost = $this->termsByPost($postIds);

        $paginator->getCollection()->transform(function ($row) use ($termsByPost) {
            $terms = $termsByPost[(int) $row->id] ?? collect();

            return [
                'id' => (int) $row->id,
                'listing_code' => $row->listing_code,
                'title' => $row->title,
                'slug' => $row->slug,
                'excerpt' => $row->excerpt,

                'price' => $row->price !== null ? round((float) $row->price, 2) : null,
                'display_price' => $this->displayPrice($row->price),

                'location' => [
                    'country_id' => $row->country_id ? (int) $row->country_id : null,
                    'country_name' => $row->country_name,
                    'state_id' => $row->state_id ? (int) $row->state_id : null,
                    'state_name' => $row->state_name,
                    'city_id' => $row->city_id ? (int) $row->city_id : null,
                    'city_name' => $row->city_name,
                    'area_locality' => $row->area_locality,
                    'full_address' => collect([
                        $row->area_locality,
                        $row->city_name,
                        $row->state_name,
                        $row->country_name,
                    ])->filter()->implode(', '),
                ],

                'taxonomy_terms' => $terms->values(),
                'purpose' => $this->firstTermByTaxonomy($terms, 'property_purpose'),
                'property_type' => $this->firstTermByTaxonomy($terms, 'property_type'),
                'bedrooms' => $this->firstTermByTaxonomy($terms, 'bedrooms'),

                'featured_image_id' => $row->featured_image_id ? (int) $row->featured_image_id : null,
                'published_at' => $row->published_at,
            ];
        });

        return $paginator;
    }

    private function taxonomyOptions(string $key): array
    {
        $taxonomySlugs = config("property_search.taxonomy_slugs.{$key}", []);

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
            ->when(Schema::hasColumn('taxonomy_terms', 'status'), fn ($q) => $q->where('status', true))
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
                'parent_id' => $term->parent_id ? (int) $term->parent_id : null,
            ])
            ->values()
            ->all();
    }

    private function propertyPostTypeId(): int
    {
        $slug = config('property_search.post_type_slug', 'property-listing');

        $id = DB::table('post_types')
            ->where('slug', $slug)
            ->value('id');

        if (! $id) {
            abort(404, 'Property listing post type not found.');
        }

        return (int) $id;
    }

    private function priceFieldIds(): array
    {
        $slugs = config('property_search.price_field_slugs', []);

        if (empty($slugs) || ! Schema::hasTable('custom_fields')) {
            return [];
        }

        return DB::table('custom_fields')
            ->whereIn('field_name_slug', $slugs)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function resolveFilterTermIds(array $filters): array
    {
        $ids = $this->normalizeIds($filters['taxonomy_term_ids'] ?? null);

        foreach ([
            'purpose' => $filters['purpose'] ?? null,
            'property_type' => $filters['property_type'] ?? null,
            'bedrooms' => $filters['bedrooms'] ?? null,
        ] as $key => $slug) {
            if (! $slug) {
                continue;
            }

            $termId = $this->termIdBySlug($key, (string) $slug);

            if ($termId) {
                $ids[] = $termId;
            }
        }

        return collect($ids)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function termIdBySlug(string $taxonomyKey, string $termSlug): ?int
    {
        $taxonomySlugs = config("property_search.taxonomy_slugs.{$taxonomyKey}", []);

        if (empty($taxonomySlugs)) {
            return null;
        }

        return DB::table('taxonomy_terms as tt')
            ->join('taxonomies as t', 't.id', '=', 'tt.taxonomy_id')
            ->whereIn('t.slug', $taxonomySlugs)
            ->where(function ($q) use ($termSlug) {
                $q->where('tt.slug', $termSlug)
                    ->orWhere('tt.name', $termSlug);
            })
            ->value('tt.id');
    }

    private function normalizeIds(array|string|null $ids): array
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

        return collect($ids)
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function termsByPost(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }

        return DB::table('post_taxonomy_terms as ptt')
            ->join('taxonomy_terms as tt', 'tt.id', '=', 'ptt.taxonomy_term_id')
            ->join('taxonomies as t', 't.id', '=', 'tt.taxonomy_id')
            ->select([
                'ptt.dynamic_post_id',
                'tt.id',
                'tt.name',
                'tt.slug',
                't.slug as taxonomy_slug',
                't.name as taxonomy_name',
            ])
            ->whereIn('ptt.dynamic_post_id', $postIds)
            ->get()
            ->groupBy('dynamic_post_id')
            ->map(function ($rows) {
                return $rows->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'taxonomy_slug' => $row->taxonomy_slug,
                    'taxonomy_name' => $row->taxonomy_name,
                ]);
            })
            ->all();
    }

    private function firstTermByTaxonomy(Collection $terms, string $taxonomySlug): ?array
    {
        $slugs = [
            $taxonomySlug,
            str_replace('_', '-', $taxonomySlug),
        ];

        $term = $terms->first(fn ($item) => in_array($item['taxonomy_slug'], $slugs, true));

        return $term ?: null;
    }

    private function displayPrice(mixed $price): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }

        $price = (float) $price;

        if ($price >= 10000000) {
            return '₹' . round($price / 10000000, 2) . ' Cr';
        }

        if ($price >= 100000) {
            return '₹' . round($price / 100000, 2) . ' Lac';
        }

        return '₹' . number_format($price);
    }
}