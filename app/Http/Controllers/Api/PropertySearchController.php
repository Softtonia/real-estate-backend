<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PropertySearchRequest;
use App\Models\City;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use App\Services\PropertySearch\PropertySearchQueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PropertySearchController extends Controller
{
    protected PropertySearchQueryBuilder $searchBuilder;

    public function __construct(PropertySearchQueryBuilder $searchBuilder)
    {
        $this->searchBuilder = $searchBuilder;
    }

    /**
     * Search properties with full multi-filter, sorting, pagination, and chips.
     *
     * GET /api/v1/properties/search
     */
    public function search(PropertySearchRequest $request): JsonResponse
    {
        try {
            $builder = $this->searchBuilder->forRequest($request)->build();
            $paginator = $builder->paginate();
            $appliedChips = $builder->getAppliedChips();

            $responseData = [
                'listings' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                    'has_more'     => $paginator->hasMorePages(),
                ],
                'applied_filters' => $appliedChips,
            ];

            // Include facet counts if explicitly requested or on page 1
            if ($request->boolean('include_facets') || (int) $request->input('page', 1) === 1) {
                $responseData['facets'] = $builder->generateFacets();
            }

            return response()->json([
                'status' => true,
                'message' => 'Properties fetched successfully.',
                'data' => $responseData,
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('[PropertySearchController] Search error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'params' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while searching properties.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get initial filter metadata, taxonomies, terms, and budget presets for UI filters.
     *
     * GET /api/v1/properties/filter-options
     */
    public function filterOptions(Request $request): JsonResponse
    {
        $cacheKey = 'prop_search_filter_options_v1';

        $data = Cache::remember($cacheKey, 3600, function () {
            // 1. Purposes
            $purposeTerms = $this->getTermsForTaxonomy('purpose');

            // 2. Property Categories
            $categoryTerms = $this->getTermsForTaxonomy('property');

            // 3. Property Types (grouped by parent category)
            $typeTaxonomy = Taxonomy::where('slug', 'property-type')->first();
            $typesGrouped = [];
            if ($typeTaxonomy) {
                $types = TaxonomyTerm::where('taxonomy_id', $typeTaxonomy->id)
                    ->with('parent:id,name,slug')
                    ->orderBy('sort_order')
                    ->get();

                foreach ($types as $t) {
                    $parentSlug = $t->parent?->slug ?: 'other';
                    $typesGrouped[$parentSlug][] = [
                        'id' => $t->id,
                        'name' => $t->name,
                        'slug' => $t->slug,
                    ];
                }
            }

            // 4. Possession Statuses
            $statusTerms = $this->getTermsForTaxonomy('property-status');

            // 5. Amenities
            $amenityTerms = $this->getTermsForTaxonomy('amenities');

            // 6. Cities
            $cities = City::select(['id', 'name', 'slug', 'state_id'])
                ->orderBy('name')
                ->limit(50)
                ->get();

            // 7. Preset Options
            $bhkOptions = [
                ['label' => '1 RK / 1 BHK', 'value' => '1 BHK'],
                ['label' => '2 BHK', 'value' => '2 BHK'],
                ['label' => '3 BHK', 'value' => '3 BHK'],
                ['label' => '4 BHK', 'value' => '4 BHK'],
                ['label' => '5+ BHK', 'value' => '5+ BHK'],
            ];

            $furnishingOptions = [
                ['label' => 'Furnished', 'value' => 'Furnished'],
                ['label' => 'Semi-Furnished', 'value' => 'Semi-Furnished'],
                ['label' => 'Unfurnished', 'value' => 'Un-Furnished'],
            ];

            $facingOptions = [
                ['label' => 'East', 'value' => 'East'],
                ['label' => 'North', 'value' => 'North'],
                ['label' => 'West', 'value' => 'West'],
                ['label' => 'South', 'value' => 'South'],
                ['label' => 'North-East', 'value' => 'North-East'],
                ['label' => 'North-West', 'value' => 'North-West'],
                ['label' => 'South-East', 'value' => 'South-East'],
                ['label' => 'South-West', 'value' => 'South-West'],
            ];

            $budgetOptions = config('property_search.budget_options');

            return [
                'purposes' => $purposeTerms,
                'categories' => $categoryTerms,
                'property_types' => $typesGrouped,
                'possession_statuses' => $statusTerms,
                'amenities' => $amenityTerms,
                'cities' => $cities,
                'bhk_options' => $bhkOptions,
                'furnishing_options' => $furnishingOptions,
                'facing_options' => $facingOptions,
                'budget_options' => $budgetOptions,
                'sort_options' => [
                    ['label' => 'Newest First', 'value' => 'newest'],
                    ['label' => 'Price: Low to High', 'value' => 'price_asc'],
                    ['label' => 'Price: High to Low', 'value' => 'price_desc'],
                    ['label' => 'Area: Low to High', 'value' => 'area_asc'],
                    ['label' => 'Area: High to Low', 'value' => 'area_desc'],
                    ['label' => 'Featured First', 'value' => 'featured_first'],
                    ['label' => 'Oldest First', 'value' => 'oldest'],
                ],
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Filter options fetched successfully.',
            'data' => $data,
        ], 200);
    }

    protected function getTermsForTaxonomy(string $slug): array
    {
        $taxonomy = Taxonomy::where('slug', $slug)->first();
        if (!$taxonomy) {
            return [];
        }

        return TaxonomyTerm::where('taxonomy_id', $taxonomy->id)
            ->select(['id', 'name', 'slug'])
            ->orderBy('sort_order')
            ->get()
            ->toArray();
    }
}
