<?php

namespace App\Services\PropertySearch;

use App\Models\CustomField;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PropertyCategoryAliasResolver
{
    /**
     * Cache TTL in seconds (1 hour).
     */
    protected int $cacheTtl;

    public function __construct()
    {
        $this->cacheTtl = (int) config('property_search.cache.field_id_map_ttl', 3600);
    }

    /**
     * Resolve custom field IDs for a given generic alias and active category slugs.
     *
     * @param string $alias e.g. 'price', 'rent', 'area', 'bhk', 'furnishing', 'gallery'
     * @param array<string> $categories e.g. ['residential', 'commercial'] or empty for all
     * @return array<int> Array of matching custom_field_id integers
     */
    public function resolveFieldIds(string $alias, array $categories = []): array
    {
        $fieldMap = $this->getAllCustomFieldsMap();
        $aliasConfig = config("property_search.custom_field_aliases.{$alias}", []);

        $targetSlugs = [];

        // Normalize categories
        $categories = array_values(array_filter(array_map('strtolower', $categories)));

        if (!empty($categories)) {
            foreach ($categories as $category) {
                if (!empty($aliasConfig[$category])) {
                    $targetSlugs[] = $aliasConfig[$category];
                }
            }
        }

        // If no category-specific slug matched, try wildcard '*' fallback
        if (empty($targetSlugs) && !empty($aliasConfig['*'])) {
            $targetSlugs[] = $aliasConfig['*'];
        }

        $matchedIds = [];

        // 1. Resolve explicitly configured slugs
        foreach ($targetSlugs as $slug) {
            $cleanSlug = Str::slug($slug, '_');
            $cleanSlugHyphen = Str::slug($slug, '-');
            if (isset($fieldMap[$cleanSlug])) {
                $matchedIds[] = $fieldMap[$cleanSlug];
            } elseif (isset($fieldMap[$cleanSlugHyphen])) {
                $matchedIds[] = $fieldMap[$cleanSlugHyphen];
            }
        }

        // 2. Intelligent dynamic fallback: If no explicit config slug mapped to an existing DB field,
        // search cached custom fields by keyword heuristic (e.g. 'price', 'bhk', 'area', 'gallery')
        if (empty($matchedIds)) {
            $matchedIds = $this->findFieldIdsByHeuristic($alias, $categories, $fieldMap);
        }

        return array_values(array_unique(array_filter($matchedIds)));
    }

    /**
     * Heuristic fallback to find field IDs when exact config slug isn't matched.
     * Searches field_name_slugs in the database matching category prefixes or keyword patterns.
     */
    protected function findFieldIdsByHeuristic(string $alias, array $categories, array $fieldMap): array
    {
        $found = [];

        $searchPatterns = match ($alias) {
            'price' => ['expected_price', 'total_price', 'sale_price', 'price'],
            'rent' => ['expected_monthly_rent', 'monthly_rent', 'expected_rent', 'rent'],
            'area' => ['carpet_area', 'built_up_area', 'super_area', 'plot_area', 'area_sq_ft', 'areasqft', 'area'],
            'bhk' => ['bedrooms', 'bedroom', 'bhk', 'no_of_bedrooms'],
            'bathrooms' => ['bathrooms', 'bathroom', 'washrooms'],
            'floor' => ['floor_number', 'floor_no', 'floor'],
            'furnishing' => ['furnishing_status', 'furnishing'],
            'facing' => ['property_facing', 'facing'],
            'ownership' => ['ownership_type', 'ownership'],
            'possession' => ['possession_status', 'possession_date', 'possession'],
            'rera' => ['rera_registration_number', 'rera_number', 'rera_id', 'rera'],
            'gallery' => ['property_gallery', 'gallery_images', 'gallery', 'property_images', 'images'],
            default => [$alias],
        };

        foreach ($fieldMap as $slug => $id) {
            $normalizedSlug = str_replace('-', '_', strtolower($slug));

            // Check if matches category + pattern
            if (!empty($categories)) {
                foreach ($categories as $cat) {
                    $catNorm = str_replace('-', '_', strtolower($cat));
                    foreach ($searchPatterns as $pattern) {
                        if ($normalizedSlug === "{$catNorm}_{$pattern}" || str_contains($normalizedSlug, "{$catNorm}_{$pattern}")) {
                            $found[] = $id;
                        }
                    }
                }
            }

            // Also check generic pattern match
            if (empty($found)) {
                foreach ($searchPatterns as $pattern) {
                    if ($normalizedSlug === $pattern || str_ends_with($normalizedSlug, "_{$pattern}")) {
                        $found[] = $id;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Get all active custom fields as [slug => id] map with caching.
     */
    public function getAllCustomFieldsMap(): array
    {
        return Cache::remember('prop_search_all_custom_fields_map', $this->cacheTtl, function () {
            return CustomField::query()
                ->select(['id', 'field_name_slug', 'field_label'])
                ->get()
                ->flatMap(function ($field) {
                    $slugs = [];
                    if (!empty($field->field_name_slug)) {
                        $s = strtolower(trim($field->field_name_slug));
                        $slugs[$s] = (int) $field->id;
                        $slugs[str_replace('-', '_', $s)] = (int) $field->id;
                        $slugs[str_replace('_', '-', $s)] = (int) $field->id;
                    }
                    if (!empty($field->field_label)) {
                        $l = Str::slug($field->field_label, '_');
                        $slugs[$l] = (int) $field->id;
                        $slugs[Str::slug($field->field_label, '-')] = (int) $field->id;
                    }
                    return $slugs;
                })
                ->toArray();
        });
    }

    /**
     * Resolve taxonomy term IDs from term slugs for a specific taxonomy.
     *
     * @param string $taxonomySlug e.g. 'purpose', 'property', 'property-type', 'property-status', 'amenities'
     * @param array<string|int> $termSlugsOrIds
     * @return array<int>
     */
    public function resolveTaxonomyTermIds(string $taxonomySlug, array $termSlugsOrIds): array
    {
        if (empty($termSlugsOrIds)) {
            return [];
        }

        $termMap = $this->getTaxonomyTermsMap($taxonomySlug);
        $resolved = [];

        foreach ($termSlugsOrIds as $item) {
            if (is_numeric($item)) {
                $resolved[] = (int) $item;
                continue;
            }

            $slug = strtolower(trim((string) $item));
            if (isset($termMap[$slug])) {
                $resolved[] = $termMap[$slug];
            } elseif (isset($termMap[Str::slug($slug, '-')])) {
                $resolved[] = $termMap[Str::slug($slug, '-')];
            } elseif (isset($termMap[Str::slug($slug, '_')])) {
                $resolved[] = $termMap[Str::slug($slug, '_')];
            }
        }

        return array_values(array_unique(array_filter($resolved)));
    }

    /**
     * Get taxonomy term map [slug => id] for a taxonomy slug.
     */
    public function getTaxonomyTermsMap(string $taxonomySlug): array
    {
        $cacheKey = "prop_search_terms_map_{$taxonomySlug}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($taxonomySlug) {
            $taxonomy = Taxonomy::where('slug', $taxonomySlug)->first();
            if (!$taxonomy) {
                return [];
            }

            return TaxonomyTerm::where('taxonomy_id', $taxonomy->id)
                ->select(['id', 'slug', 'name'])
                ->get()
                ->flatMap(function ($term) {
                    $slug = strtolower(trim($term->slug));
                    $nameSlug = Str::slug($term->name, '-');
                    return [
                        $slug => (int) $term->id,
                        str_replace('-', '_', $slug) => (int) $term->id,
                        $nameSlug => (int) $term->id,
                        strtolower(trim($term->name)) => (int) $term->id,
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Clear all cached maps (call on custom field / taxonomy updates).
     */
    public function clearCache(): void
    {
        Cache::forget('prop_search_all_custom_fields_map');
        foreach (['purpose', 'property', 'property-type', 'property-status', 'amenities'] as $tax) {
            Cache::forget("prop_search_terms_map_{$tax}");
        }
    }
}
