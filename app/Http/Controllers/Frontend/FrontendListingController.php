<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\StoreFrontendListingRequest;
use App\Services\FrontendListingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class FrontendListingController extends Controller
{
    public function __construct(
        private readonly FrontendListingService $service
    ) {
    }

    /**
     * API 1:
     * Display only defined frontend taxonomies.
     */
    public function taxonomies(): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Frontend listing taxonomies fetched successfully.',
                'data' => $this->service->getTaxonomies(),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch listing taxonomies.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * API 2:
     * Display terms based on selected taxonomy.
     */
    public function terms(
        Request $request,
        int|string $taxonomy
    ): JsonResponse {
        try {
            $request->validate([
                'search' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy terms fetched successfully.',
                'data' => $this->service->getTerms(
                    $taxonomy,
                    $request->input('search')
                ),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch taxonomy terms.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * API 3:
     * Create dynamic listing.
     */
    public function store(
        StoreFrontendListingRequest $request
    ): JsonResponse {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Authentication is required.',
                ], 401);
            }

            $listing = $this->service->createListing(
                $request->validated(),
                $user
            );

            return response()->json([
                'status' => true,
                'message' => 'Listing created successfully.',
                'data' => [
                    'listing' => [
                        'id' => (int) $listing->id,
                        'listing_code' =>
                            $listing->listing_code ?? null,
                        'title' => $listing->title,
                        'slug' => $listing->slug,
                        'status' => $listing->status,
                        'live_status' =>
                            $listing->live_status,
                        'published_at' =>
                            $listing->published_at,
                        'taxonomies' =>
                            $listing->taxonomyTerms
                                ->groupBy('taxonomy_id')
                                ->map(function ($terms) {
                                    $taxonomy =
                                        $terms->first()
                                            ->taxonomy;

                                    return [
                                        'taxonomy_id' =>
                                            (int) $taxonomy->id,
                                        'taxonomy_name' =>
                                            $taxonomy->name,
                                        'taxonomy_slug' =>
                                            $taxonomy->slug,

                                        'terms' => $terms
                                            ->map(fn($term) => [
                                                'id' =>
                                                    (int) $term->id,
                                                'name' =>
                                                    $term->name,
                                                'slug' =>
                                                    $term->slug,
                                            ])
                                            ->values(),
                                    ];
                                })
                                ->values(),
                    ],
                ],
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (QueryException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Database error while creating listing.',
                'error' => $exception->getMessage(),
            ], 500);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to create listing.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }
}