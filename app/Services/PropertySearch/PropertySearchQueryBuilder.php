<?php

namespace App\Services\PropertySearch;

use App\Models\CustomFieldValue;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\PropertyFeaturedPromotion;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertySearchQueryBuilder
{
    protected Builder $query;
    protected array $filters = [];
    protected array $activeCategories = [];
    protected array $appliedChips = [];
    protected PropertyCategoryAliasResolver $aliasResolver;

    public function __construct(PropertyCategoryAliasResolver $aliasResolver)
    {
        $this->aliasResolver = $aliasResolver;
    }

    /**
     * Initialize the builder from an incoming Request or array of filters.
     */
    public function forRequest(Request|array $request): self
    {
        $this->filters = is_array($request) ? $request : $request->all();

        // Extract active categories early because other alias resolvers depend on it
        $categoryParam = $this->filters['category'] ?? $this->filters['categories'] ?? [];
        if (!is_array($categoryParam)) {
            $categoryParam = explode(',', (string) $categoryParam);
        }
        $this->activeCategories = array_values(array_filter(array_map('trim', $categoryParam)));

        $this->appliedChips = [];
        $this->initBaseQuery();

        return $this;
    }

    /**
     * Initialize base query scoped to property listings.
     */
    protected function initBaseQuery(): void
    {
        $postTypeSlug = config('property_search.post_type_slug', 'property-listing');
        $postType = Cache::remember("prop_search_post_type_{$postTypeSlug}", 3600, function () use ($postTypeSlug) {
            return PostType::where('slug', $postTypeSlug)->first();
        });

        $this->query = DynamicPost::query();

        if ($postType) {
            $this->query->where('dynamic_posts.post_type_id', $postType->id);
        }

        // Base status filtering (published properties by default)
        $status = $this->filters['status'] ?? 'published';
        if ($status !== 'all' && $status !== '*') {
            $this->query->where('dynamic_posts.status', $status);
        }
    }

    /**
     * Build and apply all filters to the query.
     */
    public function build(): self
    {
        $this->applyTaxonomyFilters();
        $this->applyLocationFilters();
        $this->applyNumericRangeFilters();
        $this->applyCustomFieldMultiFilters();
        $this->applyBooleanAndFeatureFilters();
        $this->applyAuthorAndWorkflowFilters();
        $this->applyKeywordSearch();
        $this->applySorting();
        $this->applyEagerLoading();

        return $this;
    }

    /**
     * Execute query and return standard paginator.
     */
    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?: (int) ($this->filters['per_page'] ?? config('property_search.default_per_page', 20));
        $maxPerPage = (int) config('property_search.max_per_page', 100);
        $perPage = min(max($perPage, 1), $maxPerPage);

        $paginator = $this->query->paginate($perPage);

        // Transform results into clean presentation cards
        $paginator->getCollection()->transform(function ($post) {
            return $this->formatListingCard($post);
        });

        return $paginator;
    }

    /**
     * Get the active applied filter chips for UI presentation.
     */
    public function getAppliedChips(): array
    {
        return $this->appliedChips;
    }

    /**
     * Get the underlying Eloquent builder.
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /*
    |--------------------------------------------------------------------------
    | TAXONOMY FILTERS
    |--------------------------------------------------------------------------
    */

    protected function applyTaxonomyFilters(): void
    {
        // 1. Purpose (Buy / Sell / Rent)
        $purposeInput = $this->normalizeArrayParam($this->filters['purpose'] ?? $this->filters['purpose_id'] ?? []);
        if (!empty($purposeInput)) {
            $termIds = $this->aliasResolver->resolveTaxonomyTermIds('purpose', $purposeInput);
            if (!empty($termIds)) {
                $this->query->whereHas('taxonomyTerms', function ($q) use ($termIds) {
                    $q->whereIn('taxonomy_terms.id', $termIds);
                });

                foreach ($purposeInput as $p) {
                    $this->addChip('purpose', ucfirst((string) $p), $p);
                }
            }
        }

        // 2. Property Category (Residential / Commercial / Agricultural / Industrial)
        if (!empty($this->activeCategories)) {
            $termIds = $this->aliasResolver->resolveTaxonomyTermIds('property', $this->activeCategories);
            if (!empty($termIds)) {
                $this->query->whereHas('taxonomyTerms', function ($q) use ($termIds) {
                    $q->whereIn('taxonomy_terms.id', $termIds);
                });

                foreach ($this->activeCategories as $cat) {
                    $this->addChip('category', ucfirst((string) $cat), $cat);
                }
            }
        }

        // 3. Property Type / Sub-Type (Apartment, Villa, Office Space, etc.)
        $typeInput = $this->normalizeArrayParam($this->filters['type'] ?? $this->filters['property_type'] ?? $this->filters['property_type_id'] ?? []);
        if (!empty($typeInput)) {
            $termIds = $this->aliasResolver->resolveTaxonomyTermIds('property-type', $typeInput);
            if (!empty($termIds)) {
                $this->query->whereHas('taxonomyTerms', function ($q) use ($termIds) {
                    $q->whereIn('taxonomy_terms.id', $termIds);
                });

                foreach ($typeInput as $t) {
                    $this->addChip('type', Str::headline((string) $t), $t);
                }
            }
        }

        // 4. Possession Status (ready-to-move, under-construction, new-launch, resale)
        $statusInput = $this->normalizeArrayParam($this->filters['possession_status'] ?? $this->filters['property_status'] ?? []);
        if (!empty($statusInput)) {
            $termIds = $this->aliasResolver->resolveTaxonomyTermIds('property-status', $statusInput);
            if (!empty($termIds)) {
                $this->query->whereHas('taxonomyTerms', function ($q) use ($termIds) {
                    $q->whereIn('taxonomy_terms.id', $termIds);
                });

                foreach ($statusInput as $st) {
                    $this->addChip('possession_status', Str::headline((string) $st), $st);
                }
            }
        }

        // 5. Amenities (Multi-Select: Pool, Gym, Lift, Parking, etc.)
        $amenityInput = $this->normalizeArrayParam($this->filters['amenity'] ?? $this->filters['amenities'] ?? []);
        if (!empty($amenityInput)) {
            $termIds = $this->aliasResolver->resolveTaxonomyTermIds('amenities', $amenityInput);
            if (!empty($termIds)) {
                // Must match all requested amenities (AND logic)
                foreach ($termIds as $termId) {
                    $this->query->whereHas('taxonomyTerms', function ($q) use ($termId) {
                        $q->where('taxonomy_terms.id', $termId);
                    });
                }

                foreach ($amenityInput as $am) {
                    $this->addChip('amenity', Str::headline((string) $am), $am);
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LOCATION FILTERS
    |--------------------------------------------------------------------------
    */

    protected function applyLocationFilters(): void
    {
        // 1. City ID(s)
        $cityIds = $this->normalizeNumericArray($this->filters['city_id'] ?? $this->filters['cities'] ?? []);
        if (!empty($cityIds)) {
            $this->query->whereIn('dynamic_posts.city_id', $cityIds);
            foreach ($cityIds as $cid) {
                $this->addChip('city_id', "City ID: {$cid}", $cid);
            }
        }

        // 2. State ID
        if (!empty($this->filters['state_id'])) {
            $stateId = (int) $this->filters['state_id'];
            $this->query->where('dynamic_posts.state_id', $stateId);
        }

        // 3. Locality / Area
        $localities = $this->normalizeArrayParam($this->filters['locality'] ?? $this->filters['area_locality'] ?? $this->filters['localities'] ?? []);
        if (!empty($localities)) {
            $this->query->where(function ($q) use ($localities) {
                foreach ($localities as $loc) {
                    $cleanLoc = trim((string) $loc);
                    $q->orWhere('dynamic_posts.area_locality', 'LIKE', "%{$cleanLoc}%");
                }
            });

            foreach ($localities as $loc) {
                $this->addChip('locality', (string) $loc, $loc);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | NUMERIC RANGE FILTERS (Price, Rent, Area, Floor)
    |--------------------------------------------------------------------------
    */

    protected function applyNumericRangeFilters(): void
    {
        // 1. Price Range
        $priceMin = isset($this->filters['price_min']) && is_numeric($this->filters['price_min']) ? (float) $this->filters['price_min'] : null;
        $priceMax = isset($this->filters['price_max']) && is_numeric($this->filters['price_max']) ? (float) $this->filters['price_max'] : null;

        if ($priceMin !== null || $priceMax !== null) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('price', $this->activeCategories);
            $this->applyCustomFieldNumericRange($fieldIds, $priceMin, $priceMax);

            if ($priceMin !== null && $priceMax !== null) {
                $this->addChip('price', "₹ " . $this->formatIndianCurrency($priceMin) . " - ₹ " . $this->formatIndianCurrency($priceMax), "{$priceMin}-{$priceMax}");
            } elseif ($priceMin !== null) {
                $this->addChip('price_min', "Min ₹ " . $this->formatIndianCurrency($priceMin), $priceMin);
            } elseif ($priceMax !== null) {
                $this->addChip('price_max', "Max ₹ " . $this->formatIndianCurrency($priceMax), $priceMax);
            }
        }

        // 2. Rent Range
        $rentMin = isset($this->filters['rent_min']) && is_numeric($this->filters['rent_min']) ? (float) $this->filters['rent_min'] : null;
        $rentMax = isset($this->filters['rent_max']) && is_numeric($this->filters['rent_max']) ? (float) $this->filters['rent_max'] : null;

        if ($rentMin !== null || $rentMax !== null) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('rent', $this->activeCategories);
            $this->applyCustomFieldNumericRange($fieldIds, $rentMin, $rentMax);

            if ($rentMin !== null && $rentMax !== null) {
                $this->addChip('rent', "Rent: ₹ " . number_format($rentMin) . " - ₹ " . number_format($rentMax), "{$rentMin}-{$rentMax}");
            } elseif ($rentMin !== null) {
                $this->addChip('rent_min', "Min Rent ₹ " . number_format($rentMin), $rentMin);
            } elseif ($rentMax !== null) {
                $this->addChip('rent_max', "Max Rent ₹ " . number_format($rentMax), $rentMax);
            }
        }

        // 3. Area Range (Sq.Ft)
        $areaMin = isset($this->filters['area_min']) && is_numeric($this->filters['area_min']) ? (float) $this->filters['area_min'] : null;
        $areaMax = isset($this->filters['area_max']) && is_numeric($this->filters['area_max']) ? (float) $this->filters['area_max'] : null;

        if ($areaMin !== null || $areaMax !== null) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('area', $this->activeCategories);
            $this->applyCustomFieldNumericRange($fieldIds, $areaMin, $areaMax);

            if ($areaMin !== null && $areaMax !== null) {
                $this->addChip('area', "{$areaMin} - {$areaMax} sq.ft", "{$areaMin}-{$areaMax}");
            } elseif ($areaMin !== null) {
                $this->addChip('area_min', "Min {$areaMin} sq.ft", $areaMin);
            } elseif ($areaMax !== null) {
                $this->addChip('area_max', "Max {$areaMax} sq.ft", $areaMax);
            }
        }

        // 4. Floor Range
        $floorMin = isset($this->filters['floor_min']) && is_numeric($this->filters['floor_min']) ? (int) $this->filters['floor_min'] : null;
        $floorMax = isset($this->filters['floor_max']) && is_numeric($this->filters['floor_max']) ? (int) $this->filters['floor_max'] : null;

        if ($floorMin !== null || $floorMax !== null) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('floor', $this->activeCategories);
            $this->applyCustomFieldNumericRange($fieldIds, $floorMin, $floorMax);

            if ($floorMin !== null && $floorMax !== null) {
                $this->addChip('floor', "Floor: {$floorMin} - {$floorMax}", "{$floorMin}-{$floorMax}");
            } elseif ($floorMin !== null) {
                $this->addChip('floor_min', "Min Floor {$floorMin}", $floorMin);
            } elseif ($floorMax !== null) {
                $this->addChip('floor_max', "Max Floor {$floorMax}", $floorMax);
            }
        }
    }

    /**
     * Helper to apply numeric range filter via SQL EXISTS subquery.
     */
    protected function applyCustomFieldNumericRange(array $fieldIds, ?float $min, ?float $max): void
    {
        if (empty($fieldIds)) {
            return;
        }

        $this->query->whereHas('customFieldValues', function ($q) use ($fieldIds, $min, $max) {
            $q->whereIn('custom_field_id', $fieldIds);

            if ($min !== null && $max !== null) {
                $q->whereBetween('value_number', [$min, $max]);
            } elseif ($min !== null) {
                $q->where('value_number', '>=', $min);
            } elseif ($max !== null) {
                $q->where('value_number', '<=', $max);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOM FIELD MULTI-SELECT FILTERS (BHK, Furnishing, Facing, Ownership, Bathrooms)
    |--------------------------------------------------------------------------
    */

    protected function applyCustomFieldMultiFilters(): void
    {
        // 1. BHK / Bedrooms
        $bhkValues = $this->normalizeArrayParam($this->filters['bhk'] ?? $this->filters['bedrooms'] ?? []);
        if (!empty($bhkValues)) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('bhk', $this->activeCategories);
            $this->applyCustomFieldStringValues($fieldIds, $bhkValues);

            foreach ($bhkValues as $bhk) {
                $this->addChip('bhk', (string) $bhk, $bhk);
            }
        }

        // 2. Furnishing Status
        $furnishingValues = $this->normalizeArrayParam($this->filters['furnishing'] ?? $this->filters['furnishing_status'] ?? []);
        if (!empty($furnishingValues)) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('furnishing', $this->activeCategories);
            $this->applyCustomFieldStringValues($fieldIds, $furnishingValues);

            foreach ($furnishingValues as $f) {
                $this->addChip('furnishing', (string) $f, $f);
            }
        }

        // 3. Facing
        $facingValues = $this->normalizeArrayParam($this->filters['facing'] ?? $this->filters['property_facing'] ?? []);
        if (!empty($facingValues)) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('facing', $this->activeCategories);
            $this->applyCustomFieldStringValues($fieldIds, $facingValues);

            foreach ($facingValues as $fc) {
                $this->addChip('facing', (string) $fc, $fc);
            }
        }

        // 4. Ownership
        $ownershipValues = $this->normalizeArrayParam($this->filters['ownership'] ?? $this->filters['ownership_type'] ?? []);
        if (!empty($ownershipValues)) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('ownership', $this->activeCategories);
            $this->applyCustomFieldStringValues($fieldIds, $ownershipValues);

            foreach ($ownershipValues as $ow) {
                $this->addChip('ownership', (string) $ow, $ow);
            }
        }

        // 5. Bathrooms
        $bathroomValues = $this->normalizeArrayParam($this->filters['bathrooms'] ?? $this->filters['bathroom'] ?? []);
        if (!empty($bathroomValues)) {
            $fieldIds = $this->aliasResolver->resolveFieldIds('bathrooms', $this->activeCategories);
            if (!empty($fieldIds)) {
                $this->query->whereHas('customFieldValues', function ($q) use ($fieldIds, $bathroomValues) {
                    $q->whereIn('custom_field_id', $fieldIds)
                        ->where(function ($sub) use ($bathroomValues) {
                            $sub->whereIn('value_string', $bathroomValues)
                                ->orWhereIn('value_number', array_filter($bathroomValues, 'is_numeric'));
                        });
                });

                foreach ($bathroomValues as $b) {
                    $this->addChip('bathrooms', "{$b} Baths", $b);
                }
            }
        }
    }

    /**
     * Helper to apply string multi-values via SQL EXISTS subquery.
     */
    protected function applyCustomFieldStringValues(array $fieldIds, array $values): void
    {
        if (empty($fieldIds) || empty($values)) {
            return;
        }

        $this->query->whereHas('customFieldValues', function ($q) use ($fieldIds, $values) {
            $q->whereIn('custom_field_id', $fieldIds)
                ->whereIn('value_string', $values);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | BOOLEAN & MEDIA FEATURE FILTERS (Photos, Videos, RERA, Verified, Featured)
    |--------------------------------------------------------------------------
    */

    protected function applyBooleanAndFeatureFilters(): void
    {
        // 1. Photos & Videos
        $hasPhotos = filter_var($this->filters['has_photos'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $hasVideos = filter_var($this->filters['has_videos'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($hasPhotos || $hasVideos) {
            $galleryFieldIds = $this->aliasResolver->resolveFieldIds('gallery', $this->activeCategories);
            if (!empty($galleryFieldIds)) {
                $this->query->whereHas('customFieldValues', function ($q) use ($galleryFieldIds, $hasVideos) {
                    $q->whereIn('custom_field_id', $galleryFieldIds)
                        ->whereNotNull('value_json')
                        ->whereRaw("JSON_LENGTH(value_json) > 0");

                    // If videos specifically requested, inspect JSON for video MIME or video extension
                    if ($hasVideos) {
                        $q->where(function ($vq) {
                            $videoExtensions = ['%.mp4%', '%.mov%', '%.webm%', '%.avi%', '%.mkv%', '%video/%'];
                            foreach ($videoExtensions as $ext) {
                                $vq->orWhere('value_json', 'LIKE', $ext);
                            }
                        });
                    }
                });

                if ($hasPhotos) {
                    $this->addChip('has_photos', 'With Photos', '1');
                }
                if ($hasVideos) {
                    $this->addChip('has_videos', 'With Videos', '1');
                }
            }
        }

        // 2. RERA Registered
        $rera = filter_var($this->filters['rera'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($rera === true) {
            $reraFieldIds = $this->aliasResolver->resolveFieldIds('rera', $this->activeCategories);
            if (!empty($reraFieldIds)) {
                $this->query->whereHas('customFieldValues', function ($q) use ($reraFieldIds) {
                    $q->whereIn('custom_field_id', $reraFieldIds)
                        ->whereNotNull('value_string')
                        ->where('value_string', '!=', '');
                });

                $this->addChip('rera', 'RERA Approved', '1');
            }
        }

        // 3. Verified Properties (live_status = 'approve')
        $verified = filter_var($this->filters['verified'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verified === true) {
            $this->query->where('dynamic_posts.live_status', 'approve');
            $this->addChip('verified', 'Verified Properties', '1');
        }

        // 4. Featured Properties
        $featured = filter_var($this->filters['featured'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($featured === true) {
            $this->query->whereHas('currentFeaturedPromotion');
            $this->addChip('featured', 'Featured Properties', '1');
        }

        // 5. Availability Status
        if (!empty($this->filters['availability_status'])) {
            $avail = strtolower(trim((string) $this->filters['availability_status']));
            $this->query->where('dynamic_posts.availability_status', $avail);
            $this->addChip('availability_status', Str::headline($avail), $avail);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHOR & WORKFLOW FILTERS
    |--------------------------------------------------------------------------
    */

    protected function applyAuthorAndWorkflowFilters(): void
    {
        // 1. Posted By (Agent, Owner, Builder roles)
        $postedBy = $this->normalizeArrayParam($this->filters['posted_by'] ?? $this->filters['author_role'] ?? []);
        if (!empty($postedBy)) {
            $this->query->whereHas('author.roles', function ($q) use ($postedBy) {
                $q->whereIn('name', $postedBy);
            });

            foreach ($postedBy as $role) {
                $this->addChip('posted_by', Str::headline((string) $role), $role);
            }
        }

        // 2. Posted Since (e.g. '7d', '30d', '3m', '6m', '1y')
        if (!empty($this->filters['posted_since'])) {
            $since = (string) $this->filters['posted_since'];
            $modifier = config("property_search.posted_since_options.{$since}");

            if ($modifier) {
                $dateThreshold = Carbon::now()->modify($modifier);
                $this->query->where('dynamic_posts.published_at', '>=', $dateThreshold);
                $this->addChip('posted_since', "Posted: {$since}", $since);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | KEYWORD SEARCH (Fulltext with LIKE fallback)
    |--------------------------------------------------------------------------
    */

    protected function applyKeywordSearch(): void
    {
        $q = trim((string) ($this->filters['q'] ?? $this->filters['search'] ?? ''));
        if (empty($q)) {
            return;
        }

        $this->addChip('q', "Keyword: {$q}", $q);

        // Clean search term
        $searchTerm = preg_replace('/[+\-><()~*\"@]+/', ' ', $q);
        $searchTerm = trim($searchTerm);

        $this->query->where(function ($sub) use ($q, $searchTerm) {
            // 1. Exact match on listing code (e.g. URPL-000013)
            $sub->where('dynamic_posts.listing_code', 'LIKE', "%{$q}%")
                // 2. LIKE query on title, slug, locality
                ->orWhere('dynamic_posts.title', 'LIKE', "%{$q}%")
                ->orWhere('dynamic_posts.slug', 'LIKE', "%{$q}%")
                ->orWhere('dynamic_posts.area_locality', 'LIKE', "%{$q}%")
                ->orWhere('dynamic_posts.excerpt', 'LIKE', "%{$q}%");

            // 3. Fulltext search if term is long enough
            if (strlen($searchTerm) >= 3) {
                try {
                    $sub->orWhereRaw(
                        "MATCH(`dynamic_posts`.`title`, `dynamic_posts`.`excerpt`, `dynamic_posts`.`area_locality`) AGAINST(? IN BOOLEAN MODE)",
                        ["*{$searchTerm}*"]
                    );
                } catch (\Throwable $e) {
                }
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | SORTING (Newest, Oldest, Price Asc/Desc, Area, Featured First, Relevance)
    |--------------------------------------------------------------------------
    */

    protected function applySorting(): void
    {
        $sort = strtolower(trim((string) ($this->filters['sort'] ?? $this->filters['sort_by'] ?? 'newest')));

        switch ($sort) {
            case 'oldest':
                $this->query->orderBy('dynamic_posts.published_at', 'asc');
                break;

            case 'price_asc':
            case 'price_low_to_high':
                $priceFieldIds = $this->aliasResolver->resolveFieldIds('price', $this->activeCategories);
                if (!empty($priceFieldIds)) {
                    $primaryPriceFieldId = $priceFieldIds[0];
                    $this->query->leftJoin('custom_field_values as sort_cfv_price', function ($join) use ($primaryPriceFieldId) {
                        $join->on('sort_cfv_price.entity_id', '=', 'dynamic_posts.id')
                            ->where('sort_cfv_price.entity_type', '=', 'post')
                            ->where('sort_cfv_price.custom_field_id', '=', $primaryPriceFieldId);
                    })
                    ->orderByRaw('sort_cfv_price.value_number IS NULL ASC, sort_cfv_price.value_number ASC')
                    ->select('dynamic_posts.*');
                } else {
                    $this->query->orderBy('dynamic_posts.published_at', 'desc');
                }
                break;

            case 'price_desc':
            case 'price_high_to_low':
                $priceFieldIds = $this->aliasResolver->resolveFieldIds('price', $this->activeCategories);
                if (!empty($priceFieldIds)) {
                    $primaryPriceFieldId = $priceFieldIds[0];
                    $this->query->leftJoin('custom_field_values as sort_cfv_price', function ($join) use ($primaryPriceFieldId) {
                        $join->on('sort_cfv_price.entity_id', '=', 'dynamic_posts.id')
                            ->where('sort_cfv_price.entity_type', '=', 'post')
                            ->where('sort_cfv_price.custom_field_id', '=', $primaryPriceFieldId);
                    })
                    ->orderByRaw('sort_cfv_price.value_number IS NULL ASC, sort_cfv_price.value_number DESC')
                    ->select('dynamic_posts.*');
                } else {
                    $this->query->orderBy('dynamic_posts.published_at', 'desc');
                }
                break;

            case 'area_asc':
            case 'area_desc':
                $areaFieldIds = $this->aliasResolver->resolveFieldIds('area', $this->activeCategories);
                $dir = ($sort === 'area_asc') ? 'ASC' : 'DESC';
                if (!empty($areaFieldIds)) {
                    $primaryAreaFieldId = $areaFieldIds[0];
                    $this->query->leftJoin('custom_field_values as sort_cfv_area', function ($join) use ($primaryAreaFieldId) {
                        $join->on('sort_cfv_area.entity_id', '=', 'dynamic_posts.id')
                            ->where('sort_cfv_area.entity_type', '=', 'post')
                            ->where('sort_cfv_area.custom_field_id', '=', $primaryAreaFieldId);
                    })
                    ->orderByRaw("sort_cfv_area.value_number IS NULL ASC, sort_cfv_area.value_number {$dir}")
                    ->select('dynamic_posts.*');
                } else {
                    $this->query->orderBy('dynamic_posts.published_at', 'desc');
                }
                break;

            case 'featured_first':
                $this->query->leftJoin('property_featured_promotions as sort_pfp', function ($join) {
                    $join->on('sort_pfp.dynamic_post_id', '=', 'dynamic_posts.id')
                        ->where('sort_pfp.status', '=', PropertyFeaturedPromotion::STATUS_ACTIVE)
                        ->where(function ($q) {
                            $q->whereNull('sort_pfp.starts_at')->orWhere('sort_pfp.starts_at', '<=', now());
                        })
                        ->where(function ($q) {
                            $q->whereNull('sort_pfp.ends_at')->orWhere('sort_pfp.ends_at', '>', now());
                        });
                })
                ->orderByRaw('sort_pfp.id IS NOT NULL DESC')
                ->orderBy('dynamic_posts.published_at', 'desc')
                ->select('dynamic_posts.*');
                break;

            case 'newest':
            default:
                $this->query->orderBy('dynamic_posts.published_at', 'desc')
                    ->orderBy('dynamic_posts.id', 'desc');
                break;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SELECTIVE EAGER LOADING (N+1 Prevention)
    |--------------------------------------------------------------------------
    */

    protected function applyEagerLoading(): void
    {
        $this->query->with([
            'city:id,name,slug,state_id',
            'state:id,name,slug',
            'author:id,name,email',
            'currentFeaturedPromotion',
            'taxonomyTerms' => function ($q) {
                $q->select(['taxonomy_terms.id', 'taxonomy_terms.name', 'taxonomy_terms.slug', 'taxonomy_terms.taxonomy_id'])
                    ->with('taxonomy:id,name,slug');
            },
            'customFieldValues' => function ($q) {
                $q->select([
                    'custom_field_values.id',
                    'custom_field_values.entity_id',
                    'custom_field_values.custom_field_id',
                    'custom_field_values.value_string',
                    'custom_field_values.value_number',
                    'custom_field_values.value_json',
                ])
                ->with('customField:id,field_name_slug,field_label,field_type');
            },
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FACETED COUNTS GENERATOR
    |--------------------------------------------------------------------------
    */

    public function generateFacets(): array
    {
        // Fast facet counts cached for 5 minutes
        $cacheKey = 'prop_search_facets_' . md5(json_encode($this->filters));
        $ttl = (int) config('property_search.cache.facets_ttl', 300);

        return Cache::remember($cacheKey, $ttl, function () {
            $baseSubquery = (clone $this->query)->select('dynamic_posts.id');

            // 1. Property Category Counts
            $propertyTaxonomy = Taxonomy::where('slug', 'property')->first();
            $categoryCounts = [];
            if ($propertyTaxonomy) {
                $categoryCounts = DB::table('post_taxonomy_terms as ptt')
                    ->join('taxonomy_terms as tt', 'tt.id', '=', 'ptt.taxonomy_term_id')
                    ->whereIn('ptt.dynamic_post_id', $baseSubquery)
                    ->where('ptt.taxonomy_id', $propertyTaxonomy->id)
                    ->groupBy('tt.slug', 'tt.name')
                    ->selectRaw('tt.slug, tt.name as label, COUNT(DISTINCT ptt.dynamic_post_id) as count')
                    ->get()
                    ->toArray();
            }

            // 2. Property Type Counts (Top types)
            $typeTaxonomy = Taxonomy::where('slug', 'property-type')->first();
            $typeCounts = [];
            if ($typeTaxonomy) {
                $typeCounts = DB::table('post_taxonomy_terms as ptt')
                    ->join('taxonomy_terms as tt', 'tt.id', '=', 'ptt.taxonomy_term_id')
                    ->whereIn('ptt.dynamic_post_id', $baseSubquery)
                    ->where('ptt.taxonomy_id', $typeTaxonomy->id)
                    ->groupBy('tt.slug', 'tt.name')
                    ->orderByDesc(DB::raw('COUNT(DISTINCT ptt.dynamic_post_id)'))
                    ->limit(10)
                    ->selectRaw('tt.slug, tt.name as label, COUNT(DISTINCT ptt.dynamic_post_id) as count')
                    ->get()
                    ->toArray();
            }

            // 3. BHK Counts
            $bhkFieldIds = $this->aliasResolver->resolveFieldIds('bhk', $this->activeCategories);
            $bhkCounts = [];
            if (!empty($bhkFieldIds)) {
                $bhkCounts = DB::table('custom_field_values as cfv')
                    ->whereIn('cfv.entity_id', $baseSubquery)
                    ->where('cfv.entity_type', 'post')
                    ->whereIn('cfv.custom_field_id', $bhkFieldIds)
                    ->whereNotNull('cfv.value_string')
                    ->groupBy('cfv.value_string')
                    ->selectRaw('cfv.value_string as value, cfv.value_string as label, COUNT(DISTINCT cfv.entity_id) as count')
                    ->get()
                    ->toArray();
            }

            return [
                'categories' => $categoryCounts,
                'property_types' => $typeCounts,
                'bhk' => $bhkCounts,
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RESULT FORMATTING (Card Representation)
    |--------------------------------------------------------------------------
    */

    protected function formatListingCard(DynamicPost $post): array
    {
        // Extract custom fields into a key => value array
        $customValues = [];
        $galleryImages = [];
        $hasVideo = false;

        if ($post->relationLoaded('customFieldValues')) {
            foreach ($post->customFieldValues as $cfv) {
                $slug = $cfv->customField?->field_name_slug ?: "field_{$cfv->custom_field_id}";
                $val = $cfv->value_number ?? $cfv->value_string ?? $cfv->value_json ?? null;
                $customValues[$slug] = $val;

                // Check gallery media
                if (!empty($cfv->value_json) && is_array($cfv->value_json)) {
                    foreach ($cfv->value_json as $item) {
                        if (is_array($item) && !empty($item['url'])) {
                            $galleryImages[] = $item['url'];
                            if (!empty($item['mime_type']) && str_starts_with($item['mime_type'], 'video/')) {
                                $hasVideo = true;
                            }
                        }
                    }
                }
            }
        }

        // Group taxonomy terms
        $termsByTaxonomy = [];
        if ($post->relationLoaded('taxonomyTerms')) {
            foreach ($post->taxonomyTerms as $term) {
                $taxSlug = $term->taxonomy?->slug ?? 'other';
                $termsByTaxonomy[$taxSlug][] = [
                    'id' => $term->id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }

        // Resolve Price / Area / BHK
        $price = $this->extractFirstNumeric($customValues, ['expected_price', 'price', 'total_price', 'sale_price', 'rent']);
        $area = $this->extractFirstNumeric($customValues, ['carpet_area', 'built_up_area', 'area_sq_ft', 'plot_area', 'area']);
        $bhk = $this->extractFirstString($customValues, ['bhk', 'bedrooms']);
        $furnishing = $this->extractFirstString($customValues, ['furnishing_status', 'furnishing']);

        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'listing_code' => $post->listing_code,
            'price' => $price,
            'price_formatted' => $price ? "₹ " . $this->formatIndianCurrency($price) : null,
            'area' => $area,
            'area_formatted' => $area ? number_format($area) . " sq.ft" : null,
            'bhk' => $bhk,
            'furnishing' => $furnishing,
            'area_locality' => $post->area_locality,
            'city' => $post->city?->name,
            'state' => $post->state?->name,
            'status' => $post->status,
            'live_status' => $post->live_status,
            'availability_status' => $post->availability_status,
            'is_verified' => $post->live_status === 'approve',
            'is_featured' => $post->currentFeaturedPromotion !== null,
            'featured_image_url' => $galleryImages[0] ?? null,
            'photos_count' => count($galleryImages),
            'has_video' => $hasVideo,
            'published_at' => $post->published_at?->toISOString(),
            'purpose' => $termsByTaxonomy['purpose'][0]['name'] ?? null,
            'category' => $termsByTaxonomy['property'][0]['name'] ?? null,
            'property_type' => $termsByTaxonomy['property-type'][0]['name'] ?? null,
            'possession_status' => $termsByTaxonomy['property-status'][0]['name'] ?? null,
            'amenities' => array_column($termsByTaxonomy['amenities'] ?? [], 'name'),
            'author' => [
                'id' => $post->author?->id,
                'name' => $post->author?->name,
                'email' => $post->author?->email,
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    protected function addChip(string $key, string $label, mixed $value): void
    {
        $this->appliedChips[] = [
            'key' => $key,
            'label' => $label,
            'value' => (string) $value,
            'removable' => true,
        ];
    }

    protected function formatIndianCurrency(float $num): string
    {
        if ($num >= 10000000) {
            return round($num / 10000000, 2) . ' Cr';
        } elseif ($num >= 100000) {
            return round($num / 100000, 2) . ' Lac';
        } elseif ($num >= 1000) {
            return round($num / 1000, 2) . ' K';
        }
        return number_format($num);
    }

    protected function normalizeArrayParam(mixed $param): array
    {
        if (empty($param)) {
            return [];
        }
        if (is_string($param)) {
            return array_values(array_filter(array_map('trim', explode(',', $param))));
        }
        if (is_array($param)) {
            return array_values(array_filter($param));
        }
        return [$param];
    }

    protected function normalizeNumericArray(mixed $param): array
    {
        $arr = $this->normalizeArrayParam($param);
        return array_values(array_filter(array_map('intval', $arr)));
    }

    protected function extractFirstNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $k) {
            foreach ($data as $dKey => $val) {
                if ((str_contains($dKey, $k) || $dKey === $k) && is_numeric($val)) {
                    return (float) $val;
                }
            }
        }
        return null;
    }

    protected function extractFirstString(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            foreach ($data as $dKey => $val) {
                if ((str_contains($dKey, $k) || $dKey === $k) && is_string($val) && !empty($val)) {
                    return $val;
                }
            }
        }
        return null;
    }
}
