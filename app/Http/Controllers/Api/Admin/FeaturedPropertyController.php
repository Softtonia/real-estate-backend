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
    ) {}

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

                /*
            |--------------------------------------------------------------------------
            | Generic Post Type Filters
            |--------------------------------------------------------------------------
            */

                'post_type_id' => [
                    'nullable',
                    'integer',
                    'exists:post_types,id',
                ],

                'post_type' => [
                    'nullable',
                    'string',
                    'max:100',
                ],

                /*
            |--------------------------------------------------------------------------
            | Date Filters
            |--------------------------------------------------------------------------
            */

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

                /*
            |--------------------------------------------------------------------------
            | Sorting / Pagination
            |--------------------------------------------------------------------------
            */

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

                'page' => [
                    'nullable',
                    'integer',
                    'min:1',
                ],
            ]);

            /*
        |--------------------------------------------------------------------------
        | Base Query
        |--------------------------------------------------------------------------
        |
        | property relation name is intentionally preserved.
        | It now represents any DynamicPost.
        |
        */

            $query = PropertyFeaturedPromotion::query()
                ->with([
                    'property.postType',
                    'createdBy',
                    'updatedBy',
                    'cancelledBy',
                ]);

            /*
        |--------------------------------------------------------------------------
        | Promotion Status
        |--------------------------------------------------------------------------
        */

            if (!empty($validated['status'])) {
                $query->where(
                    'status',
                    $validated['status']
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Source
        |--------------------------------------------------------------------------
        |
        | admin
        | membership
        |
        */

            if (!empty($validated['source'])) {
                $query->where(
                    'source',
                    $validated['source']
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Exact DynamicPost
        |--------------------------------------------------------------------------
        */

            if (!empty($validated['dynamic_post_id'])) {
                $query->where(
                    'dynamic_post_id',
                    (int) $validated['dynamic_post_id']
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Post Type ID
        |--------------------------------------------------------------------------
        */

            if (!empty($validated['post_type_id'])) {
                $postTypeId =
                    (int) $validated['post_type_id'];

                $query->whereHas(
                    'property',
                    function ($postQuery) use (
                        $postTypeId
                    ) {
                        $postQuery->where(
                            'post_type_id',
                            $postTypeId
                        );
                    }
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Post Type Slug / Name
        |--------------------------------------------------------------------------
        |
        | Examples:
        |
        | property-listing
        | project-listing
        | developer-listing
        | guest-post
        |
        */

            if (!empty($validated['post_type'])) {
                $postType =
                    trim(
                        (string) $validated['post_type']
                    );

                $query->whereHas(
                    'property.postType',
                    function ($postTypeQuery) use (
                        $postType
                    ) {
                        $postTypeQuery->where(
                            function ($q) use (
                                $postType
                            ) {
                                $q->where(
                                    'slug',
                                    $postType
                                )
                                    ->orWhere(
                                        'name',
                                        $postType
                                    );
                            }
                        );
                    }
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Date Filters
        |--------------------------------------------------------------------------
        */

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

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        |
        | Search DynamicPost:
        | - title
        | - slug
        | - listing_code
        |
        | Also search PostType:
        | - name
        | - slug
        |
        */

            if (!empty($validated['search'])) {
                $search =
                    trim(
                        (string) $validated['search']
                    );

                $query->where(
                    function ($promotionQuery) use (
                        $search
                    ) {
                        $promotionQuery->whereHas(
                            'property',
                            function ($postQuery) use (
                                $search
                            ) {
                                $postQuery->where(
                                    function ($q) use (
                                        $search
                                    ) {
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
                        )
                            ->orWhereHas(
                                'property.postType',
                                function ($postTypeQuery) use (
                                    $search
                                ) {
                                    $postTypeQuery
                                        ->where(
                                            'name',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'slug',
                                            'like',
                                            "%{$search}%"
                                        );
                                }
                            );
                    }
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

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
         * Stable pagination.
         */
            $query->orderByDesc('id');

            /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

            $perPage =
                (int) (
                    $validated['per_page']
                    ?? 20
                );

            $promotions =
                $query->paginate(
                    $perPage
                );

            /*
        |--------------------------------------------------------------------------
        | Resource
        |--------------------------------------------------------------------------
        */

            $items =
                FeaturedPropertyResource::collection(
                    $promotions->getCollection()
                )->resolve($request);

            return $this->successResponse(
                'Featured listings fetched successfully.',
                [
                    'items' =>
                    $items,

                    'filters' => [
                        'search' =>
                        $validated['search']
                            ?? null,

                        'status' =>
                        $validated['status']
                            ?? null,

                        'source' =>
                        $validated['source']
                            ?? null,

                        'dynamic_post_id' =>
                        !empty($validated['dynamic_post_id'])
                            ? (int) $validated['dynamic_post_id']
                            : null,

                        'post_type_id' =>
                        !empty($validated['post_type_id'])
                            ? (int) $validated['post_type_id']
                            : null,

                        'post_type' =>
                        $validated['post_type']
                            ?? null,
                    ],

                    'pagination' => [
                        'current_page' =>
                        $promotions
                            ->currentPage(),

                        'per_page' =>
                        $promotions
                            ->perPage(),

                        'total' =>
                        $promotions
                            ->total(),

                        'last_page' =>
                        $promotions
                            ->lastPage(),

                        'from' =>
                        $promotions
                            ->firstItem(),

                        'to' =>
                        $promotions
                            ->lastItem(),
                    ],
                ]
            );
        } catch (ValidationException $e) {
            return $this
                ->validationErrorResponse(
                    $e
                );
        } catch (QueryException $e) {
            return $this
                ->databaseErrorResponse(
                    $e,
                    'Database error while fetching featured listings.'
                );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch featured listings.',
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

                'post_type_id' => [
                    'nullable',
                    'integer',
                    'exists:post_types,id',
                ],

                'post_type' => [
                    'nullable',
                    'string',
                    'max:100',
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

            /*
        |--------------------------------------------------------------------------
        | All Dynamic Post Types
        |--------------------------------------------------------------------------
        |
        | There is intentionally NO property-listing restriction here.
        |
        | This can return:
        | - property-listing
        | - project-listing
        | - developer-listing
        | - guest-post
        | - any future post type
        |
        */
            $query = DynamicPost::query()
                ->select([
                    'dynamic_posts.id',
                    'dynamic_posts.post_type_id',
                    'dynamic_posts.title',
                    'dynamic_posts.slug',
                ])
                ->with([
                    'postType:id,name,slug',

                    'featuredPromotions' =>
                    function ($promotionQuery) {
                        $promotionQuery
                            ->openPromotion()
                            ->orderBy('starts_at');
                    },
                ]);

            /*
        |--------------------------------------------------------------------------
        | Optional Post Type ID Filter
        |--------------------------------------------------------------------------
        */
            if (!empty($validated['post_type_id'])) {
                $query->where(
                    'dynamic_posts.post_type_id',
                    (int) $validated['post_type_id']
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Optional Post Type Slug / Name Filter
        |--------------------------------------------------------------------------
        |
        | Examples:
        | post_type=property-listing
        | post_type=project-listing
        | post_type=developer-listing
        |
        */
            if (!empty($validated['post_type'])) {
                $postTypeValue = trim(
                    (string) $validated['post_type']
                );

                $query->whereHas(
                    'postType',
                    function ($postTypeQuery) use (
                        $postTypeValue
                    ) {
                        $postTypeQuery->where(
                            function ($q) use (
                                $postTypeValue
                            ) {
                                $q->where(
                                    'slug',
                                    $postTypeValue
                                )
                                    ->orWhere(
                                        'name',
                                        $postTypeValue
                                    );
                            }
                        );
                    }
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Optional DynamicPost columns
        |--------------------------------------------------------------------------
        */
            foreach (
                [
                    'listing_code',
                    'author_id',
                    'status',
                    'live_status',
                    'availability_status',
                    'featured_image_id',
                    'published_at',
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

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
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

                        /*
                     * Search by Post Type name/slug too.
                     */
                        $q->orWhereHas(
                            'postType',
                            function ($postTypeQuery) use (
                                $search
                            ) {
                                $postTypeQuery
                                    ->where(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'slug',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                    }
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Fetch
        |--------------------------------------------------------------------------
        */
            $posts = $query
                ->orderBy(
                    'dynamic_posts.title'
                )
                ->orderBy(
                    'dynamic_posts.id'
                )
                ->limit($limit)
                ->get();

            /*
        |--------------------------------------------------------------------------
        | Dropdown Response
        |--------------------------------------------------------------------------
        */
            $options = $posts
                ->map(function (
                    DynamicPost $post
                ) {
                    $label =
                        $post->title
                        ?: $post->slug
                        ?: (
                            'Dynamic Post #'
                            . $post->id
                        );

                    if (
                        !empty($post->listing_code)
                    ) {
                        $label =
                            $post->listing_code
                            . ' - '
                            . $label;
                    }

                    /*
                 * Helpful for admin when different post types
                 * have similar titles.
                 */
                    if ($post->postType) {
                        $postTypeLabel =
                            $post->postType->name
                            ?: $post->postType->slug;

                        if ($postTypeLabel) {
                            $label .=
                                ' ['
                                . $postTypeLabel
                                . ']';
                        }
                    }

                    $openPromotions =
                        $post->featuredPromotions;

                    $firstOpenPromotion =
                        $openPromotions->first();

                    return [
                        'id' =>
                        (int) $post->id,

                        'value' =>
                        (int) $post->id,

                        'label' =>
                        (string) $label,

                        /*
                    |--------------------------------------------------------------------------
                    | Dynamic Post
                    |--------------------------------------------------------------------------
                    */
                        'dynamic_post_id' =>
                        (int) $post->id,

                        'listing_code' =>
                        $post->listing_code
                            ?? null,

                        'title' =>
                        $post->title
                            ?? null,

                        'slug' =>
                        $post->slug
                            ?? null,

                        /*
                    |--------------------------------------------------------------------------
                    | Post Type
                    |--------------------------------------------------------------------------
                    */
                        'post_type' =>
                        $post->postType
                            ? [
                                'id' =>
                                (int) $post->postType->id,

                                'name' =>
                                $post->postType->name,

                                'slug' =>
                                $post->postType->slug,
                            ]
                            : null,

                        'post_type_id' =>
                        $post->postType
                            ? (int) $post->postType->id
                            : (
                                !empty($post->post_type_id)
                                ? (int) $post->post_type_id
                                : null
                            ),

                        /*
                    |--------------------------------------------------------------------------
                    | Owner
                    |--------------------------------------------------------------------------
                    */
                        'author_id' =>
                        !empty($post->author_id)
                            ? (int) $post->author_id
                            : null,

                        /*
                    |--------------------------------------------------------------------------
                    | Current Post Status
                    |--------------------------------------------------------------------------
                    */
                        'status' =>
                        $post->status
                            ?? null,

                        'live_status' =>
                        $post->live_status
                            ?? null,

                        /*
                     * Property may use this.
                     * Other post types can simply return null.
                     */
                        'availability_status' =>
                        $post->availability_status
                            ?? null,

                        /*
                    |--------------------------------------------------------------------------
                    | Featured State
                    |--------------------------------------------------------------------------
                    */
                        'has_open_featured_promotion' =>
                        $openPromotions->isNotEmpty(),

                        'open_promotions_count' =>
                        $openPromotions->count(),

                        'next_or_current_promotion' =>
                        $firstOpenPromotion
                            ? [
                                'id' =>
                                (int) $firstOpenPromotion->id,

                                'source' =>
                                $firstOpenPromotion->source,

                                'status' =>
                                $firstOpenPromotion->status,

                                'priority' =>
                                (int) $firstOpenPromotion->priority,

                                'starts_at' =>
                                $firstOpenPromotion
                                    ->starts_at
                                    ?->toISOString(),

                                'ends_at' =>
                                $firstOpenPromotion
                                    ->ends_at
                                    ?->toISOString(),

                                'is_currently_featured' =>
                                $firstOpenPromotion
                                    ->isCurrentlyFeatured(),
                            ]
                            : null,
                    ];
                })
                ->values();

            return $this->successResponse(
                'Dynamic post options fetched successfully.',
                [
                    'count' =>
                    $options->count(),

                    'filters' => [
                        'post_type_id' =>
                        !empty($validated['post_type_id'])
                            ? (int) $validated['post_type_id']
                            : null,

                        'post_type' =>
                        $validated['post_type']
                            ?? null,

                        'search' =>
                        $validated['search']
                            ?? null,
                    ],

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
                'Database error while fetching dynamic post options.'
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch dynamic post options.',
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
