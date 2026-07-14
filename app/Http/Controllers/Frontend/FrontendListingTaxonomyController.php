<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class FrontendListingTaxonomyController extends Controller
{
    /**
     * Fetch only defined frontend listing taxonomies and their terms.
     */
    public function index(): JsonResponse
    {
        try {
            $taxonomySlugs = config(
                'frontend_listing.taxonomies',
                [
                    'property-type',
                    'purpose',
                    'property-status',
                    'property',
                ]
            );

            $taxonomies = Taxonomy::query()
                ->select([
                    'id',
                    'name',
                    'slug',
                    'description',
                    'hierarchical',
                    'sort_order',
                    'status',
                ])
                ->whereIn('slug', $taxonomySlugs)
                ->where('status', true)
                ->with([
                    'terms' => function ($query) {
                        $query
                            ->select([
                                'id',
                                'taxonomy_id',
                                'name',
                                'slug',
                                'description',
                                'parent_id',
                                'sort_order',
                                'status',
                            ])
                            ->where('status', true)
                            ->orderBy('sort_order')
                            ->orderBy('name');
                    },
                ])
                ->get()
                ->sortBy(function (Taxonomy $taxonomy) use ($taxonomySlugs) {
                    $position = array_search(
                        $taxonomy->slug,
                        $taxonomySlugs,
                        true
                    );

                    return $position === false
                        ? PHP_INT_MAX
                        : $position;
                })
                ->values();

            $data = $taxonomies
                ->map(function (Taxonomy $taxonomy) {
                    return [
                        'id' => (int) $taxonomy->id,
                        'name' => $taxonomy->name,
                        'label' => $taxonomy->name,
                        'slug' => $taxonomy->slug,
                        'description' => $taxonomy->description,
                        'hierarchical' => (bool) $taxonomy->hierarchical,

                        'request_key' => sprintf(
                            'taxonomies.%s',
                            $taxonomy->slug
                        ),

                        'type' => 'select',
                        'multiple' => false,
                        'required' => true,

                        'terms' => $taxonomy->terms
                            ->map(function ($term) {
                                return [
                                    'id' => (int) $term->id,
                                    'taxonomy_id' => (int) $term->taxonomy_id,
                                    'name' => $term->name,
                                    'label' => $term->name,
                                    'value' => (int) $term->id,
                                    'slug' => $term->slug,
                                    'description' => $term->description,
                                    'parent_id' => $term->parent_id
                                        ? (int) $term->parent_id
                                        : null,
                                    'sort_order' => (int) ($term->sort_order ?? 0),
                                ];
                            })
                            ->values(),
                    ];
                })
                ->values();

            $missingTaxonomies = collect($taxonomySlugs)
                ->diff($taxonomies->pluck('slug'))
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Frontend listing taxonomies fetched successfully.',
                'data' => [
                    'count' => $data->count(),
                    'taxonomies' => $data,
                    'missing_taxonomies' => $missingTaxonomies,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('Frontend listing taxonomy error', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch frontend listing taxonomies.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }
}