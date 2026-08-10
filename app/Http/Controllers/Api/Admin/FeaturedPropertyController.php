<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeaturedProperty\CancelFeaturedPropertyRequest;
use App\Http\Requests\Admin\FeaturedProperty\StoreFeaturedPropertyRequest;
use App\Http\Requests\Admin\FeaturedProperty\UpdateFeaturedPropertyRequest;
use App\Http\Resources\Admin\FeaturedPropertyResource;
use App\Models\DynamicPost;
use App\Models\PropertyFeaturedPromotion;
use App\Models\User;
use App\Services\FeaturedProperty\FeaturedPropertyService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class FeaturedPropertyController extends Controller
{
    public function __construct(
        private readonly FeaturedPropertyService $featuredPropertyService
    ) {
    }

    /**
     * Admin featured promotion listing.
     *
     * Supports:
     * - search
     * - status
     * - source
     * - property
     * - date range
     * - pagination
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'search' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'status' => [
                    'nullable',
                    Rule::in(
                        PropertyFeaturedPromotion::STATUSES
                    ),
                ],

                'source' => [
                    'nullable',
                    Rule::in(
                        PropertyFeaturedPromotion::SOURCES
                    ),
                ],

                'dynamic_post_id' => [
                    'nullable',
                    'integer',
                    'exists:dynamic_posts,id',
                ],

                'starts_from' => [
                    'nullable',
                    'date',
                ],

                'starts_to' => [
                    'nullable',
                    'date',
                ],

                'ends_from' => [
                    'nullable',
                    'date',
                ],

                'ends_to' => [
                    'nullable',
                    'date',
                ],

                'sort_by' => [
                    'nullable',
                    Rule::in([
                        'priority',
                        'starts_at',
                        'ends_at',
                        'created_at',
                        'updated_at',
                    ]),
                ],

                'sort_order' => [
                    'nullable',
                    Rule::in([
                        'asc',
                        'desc',
                    ]),
                ],

                'per_page' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:100',
                ],
            ]);

            $query = PropertyFeaturedPromotion::query()
                ->with([
                    'property',
                    'createdBy',
                    'updatedBy',
                    'cancelledBy',
                ]);

            if (!empty($validated['status'])) {
                $query->where(
                    'status',
                    $validated['status']
                );
            }

            if (!empty($validated['source'])) {
                $query->where(
                    'source',
                    $validated['source']
                );
            }

            if (!empty($validated['dynamic_post_id'])) {
                $query->where(
                    'dynamic_post_id',
                    (int) $validated['dynamic_post_id']
                );
            }

            if (!empty($validated['starts_from'])) {
                $query->where(
                    'starts_at',
                    '>=',
                    $validated['starts_from']
                );
            }

            if (!empty($validated['starts_to'])) {
                $query->where(
                    'starts_at',
                    '<=',
                    $validated['starts_to']
                );
            }

            if (!empty($validated['ends_from'])) {
                $query->where(
                    'ends_at',
                    '>=',
                    $validated['ends_from']
                );
            }

            if (!empty($validated['ends_to'])) {
                $query->where(
                    'ends_at',
                    '<=',
                    $validated['ends_to']
                );
            }

            if (!empty($validated['search'])) {
                $search = trim(
                    (string) $validated['search']
                );

                $query->whereHas(
                    'property',
                    function ($propertyQuery) use ($search) {
                        $propertyQuery->where(
                            function ($q) use ($search) {
                                $q->where(
                                    'title',
                                    'like',
                                    "%{$search}%"
                                )
                                    ->orWhere(
                                        'slug',
                                        'like',
                                        "%{$search}%"
                                    );

                                if (
                                    Schema::hasColumn(
                                        'dynamic_posts',
                                        'listing_code'
                                    )
                                ) {
                                    $q->orWhere(
                                        'listing_code',
                                        'like',
                                        "%{$search}%"
                                    );
                                }
                            }
                        );
                    }
                );
            }

            $sortBy =
                $validated['sort_by']
                ?? 'created_at';

            $sortOrder =
                $validated['sort_order']
                ?? 'desc';

            $query->orderBy(
                $sortBy,
                $sortOrder
            );

            /*
             * Stable ordering when same sort value exists.
             */
            if ($sortBy !== 'id') {
                $query->orderByDesc('id');
            }

            $perPage = (int) (
                $validated['per_page']
                ?? 20
            );

            $promotions = $query->paginate(
                $perPage
            );

            $items = FeaturedPropertyResource::collection(
                $promotions->getCollection()
            )->resolve($request);

            return $this->successResponse(
                'Featured properties fetched successfully.',
                [
                    'items' => $items,

                    'pagination' => [
                        'current_page' =>
                            $promotions->currentPage(),

                        'per_page' =>
                            $promotions->perPage(),

                        'total' =>
                            $promotions->total(),

                        'last_page' =>
                            $promotions->lastPage(),

                        'from' =>
                            $promotions->firstItem(),

                        'to' =>
                            $promotions->lastItem(),
                    ],
                ]
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse(
                $e
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse(
                $e,
                'Database error while fetching featured properties.'
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch featured properties.',
                500,
                $e->getMessage()
            );
        }
    }

    /**
     * Create / assign featured property.
     */
    public function store(
        StoreFeaturedPropertyRequest $request
    ): JsonResponse {
        try {
            $actor = $this->authenticatedActor(
                $request
            );

            $promotion =
                $this->featuredPropertyService
                    ->create(
                        data: $request->validated(),
                        actor: $actor
                    );

            return $this->successResponse(
                'Property featured successfully.',
                (
                    new FeaturedPropertyResource(
                        $promotion
                    )
                )->resolve($request),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse(
                $e
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse(
                $e,
                'Database error while featuring property.'
            );
        } catch (AuthenticationException $e) {
            return $this->errorResponse(
                'Unauthenticated.',
                401
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to feature property.',
                500,
                $e->getMessage()
            );
        }
    }

    /**
     * Show one featured promotion.
     */
    public function show(
        Request $request,
        PropertyFeaturedPromotion $featuredProperty
    ): JsonResponse {
        try {
            $featuredProperty->load([
                'property',
                'createdBy',
                'updatedBy',
                'cancelledBy',
            ]);

            return $this->successResponse(
                'Featured property fetched successfully.',
                (
                    new FeaturedPropertyResource(
                        $featuredProperty
                    )
                )->resolve($request)
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse(
                $e,
                'Database error while fetching featured property.'
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch featured property.',
                500,
                $e->getMessage()
            );
        }
    }

    /**
     * Update / extend active or scheduled promotion.
     */
    public function update(
        UpdateFeaturedPropertyRequest $request,
        PropertyFeaturedPromotion $featuredProperty
    ): JsonResponse {
        try {
            $actor = $this->authenticatedActor(
                $request
            );

            $promotion =
                $this->featuredPropertyService
                    ->update(
                        promotion: $featuredProperty,
                        data: $request->validated(),
                        actor: $actor
                    );

            return $this->successResponse(
                'Featured property updated successfully.',
                (
                    new FeaturedPropertyResource(
                        $promotion
                    )
                )->resolve($request)
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse(
                $e
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse(
                $e,
                'Database error while updating featured property.'
            );
        } catch (AuthenticationException $e) {
            return $this->errorResponse(
                'Unauthenticated.',
                401
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to update featured property.',
                500,
                $e->getMessage()
            );
        }
    }

    /**
     * Cancel featured promotion.
     *
     * Record is NOT physically deleted.
     */
    public function cancel(
        CancelFeaturedPropertyRequest $request,
        PropertyFeaturedPromotion $featuredProperty
    ): JsonResponse {
        try {
            $actor = $this->authenticatedActor(
                $request
            );

            $promotion =
                $this->featuredPropertyService
                    ->cancel(
                        promotion: $featuredProperty,
                        actor: $actor,
                        reason: $request->validated(
                            'cancellation_reason'
                        )
                    );

            return $this->successResponse(
                'Featured property cancelled successfully.',
                (
                    new FeaturedPropertyResource(
                        $promotion
                    )
                )->resolve($request)
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse(
                $e
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse(
                $e,
                'Database error while cancelling featured property.'
            );
        } catch (AuthenticationException $e) {
            return $this->errorResponse(
                'Unauthenticated.',
                401
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to cancel featured property.',
                500,
                $e->getMessage()
            );
        }
    }

    /**
     * Property dropdown for Add Featured Property form.
     *
     * Admin can select any property listing.
     *
     * We intentionally do NOT require property to already be
     * approved/published here because admin may schedule a
     * promotion before verification completes.
     */
    public function propertyOptions(
        Request $request
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'search' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'limit' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:100',
                ],
            ]);

            $limit = (int) (
                $validated['limit']
                ?? 50
            );

            $query = DynamicPost::query()
                ->select([
                    'dynamic_posts.id',
                    'dynamic_posts.post_type_id',
                    'dynamic_posts.title',
                    'dynamic_posts.slug',
                ])
                ->whereHas(
                    'postType',
                    function ($postTypeQuery) {
                        $postTypeQuery->where(
                            'slug',
                            'property-listing'
                        );
                    }
                );

            /*
             * Select optional columns only if they exist.
             */
            foreach (
                [
                    'listing_code',
                    'author_id',
                    'status',
                    'live_status',
                    'availability_status',
                ] as $column
            ) {
                if (
                    Schema::hasColumn(
                        'dynamic_posts',
                        $column
                    )
                ) {
                    $query->addSelect(
                        'dynamic_posts.' . $column
                    );
                }
            }

            if (!empty($validated['search'])) {
                $search = trim(
                    (string) $validated['search']
                );

                $query->where(
                    function ($q) use ($search) {
                        $q->where(
                            'dynamic_posts.title',
                            'like',
                            "%{$search}%"
                        )
                            ->orWhere(
                                'dynamic_posts.slug',
                                'like',
                                "%{$search}%"
                            );

                        if (
                            Schema::hasColumn(
                                'dynamic_posts',
                                'listing_code'
                            )
                        ) {
                            $q->orWhere(
                                'dynamic_posts.listing_code',
                                'like',
                                "%{$search}%"
                            );
                        }
                    }
                );
            }

            /*
             * Load all still-open promotions in one additional query.
             *
             * This prevents N+1 and lets the admin UI know
             * whether the property already has a promotion.
             */
            $properties = $query
                ->with([
                    'featuredPromotions' =>
                        function ($promotionQuery) {
                            $promotionQuery
                                ->openPromotion()
                                ->orderBy('starts_at');
                        },
                ])
                ->orderBy(
                    'dynamic_posts.title'
                )
                ->limit($limit)
                ->get();

            $options = $properties
                ->map(function (
                    DynamicPost $property
                ) {
                    $label =
                        $property->title
                        ?: $property->slug
                        ?: (
                            'Property #'
                            . $property->id
                        );

                    if (
                        !empty(
                            $property->listing_code
                        )
                    ) {
                        $label =
                            $property->listing_code
                            . ' - '
                            . $label;
                    }

                    $openPromotions =
                        $property->featuredPromotions;

                    $firstOpenPromotion =
                        $openPromotions->first();

                    return [
                        'id' =>
                            (int) $property->id,

                        'value' =>
                            (int) $property->id,

                        'label' =>
                            (string) $label,

                        'listing_code' =>
                            $property->listing_code
                            ?? null,

                        'title' =>
                            $property->title
                            ?? null,

                        'slug' =>
                            $property->slug
                            ?? null,

                        'author_id' =>
                            !empty(
                                $property->author_id
                            )
                                ? (int) $property->author_id
                                : null,

                        'status' =>
                            $property->status
                            ?? null,

                        'live_status' =>
                            $property->live_status
                            ?? null,

                        'availability_status' =>
                            $property->availability_status
                            ?? null,

                        'has_open_featured_promotion' =>
                            $openPromotions->isNotEmpty(),

                        'open_promotions_count' =>
                            $openPromotions->count(),

                        'next_or_current_promotion' =>
                            $firstOpenPromotion
                                ? [
                                    'id' =>
                                        (int) $firstOpenPromotion->id,

                                    'status' =>
                                        $firstOpenPromotion->status,

                                    'starts_at' =>
                                        $firstOpenPromotion
                                            ->starts_at
                                            ?->toISOString(),

                                    'ends_at' =>
                                        $firstOpenPromotion
                                            ->ends_at
                                            ?->toISOString(),
                                ]
                                : null,
                    ];
                })
                ->values();

            return $this->successResponse(
                'Property options fetched successfully.',
                [
                    'count' =>
                        $options->count(),

                    'options' =>
                        $options,
                ]
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse(
                $e
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse(
                $e,
                'Database error while fetching property options.'
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch property options.',
                500,
                $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    private function authenticatedActor(
        Request $request
    ): User {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new AuthenticationException(
                'Unauthenticated.'
            );
        }

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | API responses
    |--------------------------------------------------------------------------
    */

    private function successResponse(
        string $message,
        mixed $data = null,
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    private function errorResponse(
        string $message,
        int $statusCode = 500,
        mixed $error = null
    ): JsonResponse {
        $response = [
            'status' => false,
            'message' => $message,
        ];

        if ($error !== null) {
            $response['error'] = $error;
        }

        return response()->json(
            $response,
            $statusCode
        );
    }

    private function validationErrorResponse(
        ValidationException $e
    ): JsonResponse {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => 'Validation failed.',
            'errors' => $e->errors(),
        ], 422);
    }

    private function databaseErrorResponse(
        QueryException $e,
        string $message
    ): JsonResponse {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }

    
}