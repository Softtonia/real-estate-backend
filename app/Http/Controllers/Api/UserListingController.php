<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PostType;
use App\Models\SiteSetting;
use App\Models\State;
use App\Models\User;
use App\Models\CustomField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Services\Membership\MembershipCreditService;
use App\Services\PropertyVerification\PropertyWorkflowService;
use App\Models\PropertyFeaturedPromotion;

class UserListingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'users_property_listings' => [
                    'nullable',
                    'string',
                ],
                'users-Property-listings' => [
                    'nullable',
                    'string',
                ],
                'search' => ['nullable', 'string', 'max:255'],
                'is_featured' => ['nullable', 'string'],
                'featured_status' => ['nullable', 'string'],
                'sort_by' => ['nullable', 'string'],
                'sort' => ['nullable', 'string'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            if ($this->isAdminUser($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin token is not allowed for user property listings API.',
                    'current_user' => [
                        'id' => (int) $user->id,
                        'name' => $this->userDisplayName($user),
                        'email' => $user->email ?? null,
                    ],
                ], 403);
            }

            if (!Schema::hasColumn('dynamic_posts', 'author_id')) {
                return response()->json([
                    'status' => false,
                    'message' => 'author_id column is required in dynamic_posts table to fetch own uploaded listings.',
                ], 500);
            }

            $filter = $request->query('users_property_listings')
                ?? $request->query('users-Property-listings')
                ?? 'all';

            $postType = $this->propertyListingPostType($user);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Listing post type is not configured for this user role.',
                ], 404);
            }

            $baseQuery = DynamicPost::query()
                ->where('post_type_id', (int) $postType->id)
                ->where('author_id', (int) $user->id);

            $analytics = $this->buildAnalytics($baseQuery);

            $query = DynamicPost::query()
                ->with($this->listingRelations())
                ->where('post_type_id', (int) $postType->id)
                ->where('author_id', (int) $user->id);

            $this->applyListingFilter($query, $filter);

            // Additional featured filter check via query parameters
            $isFeaturedParam = $request->query('is_featured') ?? $request->query('featured_status');
            if ($isFeaturedParam !== null && $isFeaturedParam !== '') {
                $val = strtolower(trim((string) $isFeaturedParam));

                if (in_array($val, ['1', 'true', 'featured', 'yes'], true
                )) {
                    $query->whereHas('currentFeaturedPromotion');
                } elseif (in_array($val, ['0', 'false', 'unfeatured', 'not_featured', 'no'], true)) {
                    $query->whereDoesntHave('currentFeaturedPromotion');
                }
            }

            // Search by title, listing_code, slug, locality, address, city, state, country
            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($q) use ($search) {
                    if (Schema::hasColumn('dynamic_posts', 'title')) {
                        $q->where('title', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'slug')) {
                        $q->orWhere('slug', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                        $q->orWhere('listing_code', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'area_locality')) {
                        $q->orWhere('area_locality', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'full_address')) {
                        $q->orWhere('full_address', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'address')) {
                        $q->orWhere('address', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'city_id') && Schema::hasTable('cities')) {
                        $cityIds = DB::table('cities')->where('name', 'like', "%{$search}%")->pluck('id');
                        if ($cityIds->isNotEmpty()) {
                            $q->orWhereIn('city_id', $cityIds);
                        }
                    }

                    if (Schema::hasColumn('dynamic_posts', 'state_id') && Schema::hasTable('states')) {
                        $stateIds = DB::table('states')->where('name', 'like', "%{$search}%")->pluck('id');
                        if ($stateIds->isNotEmpty()) {
                            $q->orWhereIn('state_id', $stateIds);
                        }
                    }

                    if (Schema::hasColumn('dynamic_posts', 'country_id') && Schema::hasTable('countries')) {
                        $countryIds = DB::table('countries')->where('name', 'like', "%{$search}%")->pluck('id');
                        if ($countryIds->isNotEmpty()) {
                            $q->orWhereIn('country_id', $countryIds);
                        }
                    }
                });
            }

            // Sort By filters
            $sortBy = strtolower(trim((string) ($request->query('sort_by') ?? $request->query('sort') ?? '')));

            if (in_array($sortBy, ['oldest', 'oldest_first', 'created_at_asc', 'asc'], true)) {
                $query->orderBy('created_at', 'asc');
            } elseif (in_array($sortBy, ['price_high_to_low', 'price_desc', 'high_to_low'], true)) {
                if (Schema::hasColumn('dynamic_posts', 'price')) {
                    $query->orderBy('price', 'desc');
                } else {
                    $metaTable = $this->dynamicPostMetaTable();
                    if ($metaTable) {
                        $query->orderBy(
                            DB::table($metaTable)
                                ->select(DB::raw('CAST(field_meta_value AS DECIMAL(15,2))'))
                                ->whereColumn("{$metaTable}.entity_id", 'dynamic_posts.id')
                                ->limit(1),
                            'desc'
                        );
                    } else {
                        $query->orderBy('created_at', 'desc');
                    }
                }
            } elseif (in_array($sortBy, ['price_low_to_high', 'price_asc', 'low_to_high'], true)) {
                if (Schema::hasColumn('dynamic_posts', 'price')) {
                    $query->orderBy('price', 'asc');
                } else {
                    $metaTable = $this->dynamicPostMetaTable();
                    if ($metaTable) {
                        $query->orderBy(
                            DB::table($metaTable)
                                ->select(DB::raw('CAST(field_meta_value AS DECIMAL(15,2))'))
                                ->whereColumn("{$metaTable}.entity_id", 'dynamic_posts.id')
                                ->limit(1),
                            'asc'
                        );
                    } else {
                        $query->orderBy('created_at', 'desc');
                    }
                }
            } else {
                // Default: Newest first
                if (Schema::hasColumn('dynamic_posts', 'sort_order')) {
                    $query->orderBy('sort_order', 'asc');
                }
                $query->latest();
            }

            $perPage = (int) $request->get('per_page', 10);

            $listings = $query->paginate($perPage);

            $listings->getCollection()->transform(function ($listing) {
                return $this->formatFullDynamicPost($listing);
            });

            return response()->json([
                'status' => true,
                'message' => 'User listings fetched successfully.',
                'current_user' => [
                    'id' => (int) $user->id,
                    'name' => $this->userDisplayName($user),
                    'email' => $user->email ?? null,
                ],
                'filter' => $filter,
                'analytics' => $analytics,
                'data' => $listings,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user property listings.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function analytics(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            if ($this->isAdminUser($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin token is not allowed for user listing analytics API.',
                ], 403);
            }

            $postType = $this->propertyListingPostType($user);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Listing post type is not configured for this user role.',
                ], 404);
            }

            $baseQuery = DynamicPost::query()
                ->where('post_type_id', (int) $postType->id)
                ->where('author_id', (int) $user->id);

            return response()->json([
                'status' => true,
                'message' => 'User listing analytics fetched successfully.',
                'data' => $this->buildAnalytics($baseQuery),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user listing analytics.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            if ($this->isAdminUser($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin token is not allowed to create user property listing.',
                ], 403);
            }

            $this->propertyWorkflow()->assertCanSubmitProperty($user);

            // Frontend users cannot choose approval/publication status.
            $request->request->remove('status');
            $request->request->remove('live_status');

            $request->validate([
                'title' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255'],

                'status' => ['nullable', 'string', Rule::in([
                    'draft',
                    'published',
                    'private',
                    'archived',
                ])],

                'live_status' => ['nullable', 'string', Rule::in([
                    'under_review',
                    'submit',
                    'approve',
                    'reject',
                    'disapprove',
                    'modify_review',
                ])],

                'content' => ['nullable', 'string'],
                'excerpt' => ['nullable', 'string'],

                'country_id' => ['nullable', 'exists:countries,id'],
                'state_id' => ['nullable', 'exists:states,id'],
                'city_id' => ['nullable', 'exists:cities,id'],
                'area_locality' => ['nullable', 'string', 'max:255'],

                'personal_email' => ['nullable', 'email', 'max:255'],
                'business_email' => ['nullable', 'email', 'max:255'],
                'personal_phone' => ['nullable', 'string', 'max:30'],
                'business_phone' => ['nullable', 'string', 'max:30'],

                'custom_fields' => ['nullable', 'array'],
                'custom_fields.*.custom_field_id' => ['nullable', 'integer'],
                'custom_fields.*.slug' => ['nullable', 'string', 'max:255'],
                'custom_fields.*.name' => ['nullable', 'string', 'max:255'],
                'custom_fields.*.label' => ['nullable', 'string', 'max:255'],
                'custom_fields.*.value_string' => ['nullable'],
                'custom_fields.*.value_text' => ['nullable'],
                'custom_fields.*.value_number' => ['nullable'],
                'custom_fields.*.value_date' => ['nullable'],
                'custom_fields.*.value_datetime' => ['nullable'],
                'custom_fields.*.value_json' => ['nullable'],

                'taxonomy_term_ids' => ['nullable'],
                'taxonomy_term_ids.*' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],

                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*' => ['nullable'],
                'taxonomies.*.taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'taxonomies.*.taxonomy_term_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
                'taxonomies.*.taxonomy_term_ids' => ['nullable', 'array'],
                'taxonomies.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

                'featured_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
                'featured_image_id' => ['nullable'],
                'featured_image_id.*' => ['nullable'],

                'gallery_images' => ['nullable'],
                'gallery_images.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],

                'gallery_image_ids' => ['nullable'],
                'gallery_image_ids.*' => ['nullable'],
            ]);

            $this->validatePersonalBusinessContacts($request);

            $postType = $this->propertyListingPostType($user);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Listing post type is not configured for this user role.',
                ], 404);
            }

            if (!Schema::hasColumn('dynamic_posts', 'author_id')) {
                return response()->json([
                    'status' => false,
                    'message' => 'author_id column is required in dynamic_posts table.',
                ], 500);
            }

            $listing = null;

            DB::transaction(function () use ($request, $user, $postType, &$listing) {
                $payload = [];

                /*
                |--------------------------------------------------------------------------
                | Automatic unique listing identity
                |--------------------------------------------------------------------------
                | User listing title/slug are generated by backend from:
                | - selected taxonomy terms
                | - area / city / state / country
                | - post type fallback
                | - unique listing code
                |
                | Frontend title/slug are intentionally not used here.
                |--------------------------------------------------------------------------
                */
                $termIds = $this->normalizeUserListingTaxonomyTermIds($request);

                $listingCode = Schema::hasColumn('dynamic_posts', 'listing_code')
                    ? $this->generateListingCode($postType)
                    : null;

                $listingReference = $listingCode
                    ?: (
                        'LST-'
                        . now()->format('YmdHis')
                        . '-'
                        . Str::upper(Str::random(4))
                    );

                $baseTitle = $this->buildAutomaticListingTitle(
                    postType: $postType,
                    taxonomyTermIds: $termIds,
                    countryId: $this->nullableInteger($request->input('country_id')),
                    stateId: $this->nullableInteger($request->input('state_id')),
                    cityId: $this->nullableInteger($request->input('city_id')),
                    areaLocality: $request->input('area_locality')
                );

                $title = $this->uniqueDynamicPostTitle(
                    baseTitle: $baseTitle,
                    postTypeId: (int) $postType->id,
                    ignoreDynamicPostId: null,
                    uniqueReference: $listingReference
                );

                $slug = $this->uniqueDynamicPostSlug(
                    $title,
                    (int) $postType->id
                );

                $this->putIfColumnExists($payload, 'post_type_id', (int) $postType->id);
                $this->putIfColumnExists($payload, 'author_id', (int) $user->id);
                $this->putIfColumnExists($payload, 'user_id', (int) $user->id);
                $this->putIfColumnExists($payload, 'title', $title);
                $this->putIfColumnExists($payload, 'slug', $slug);

                $this->putIfColumnExists($payload, 'status', 'draft');

                // User-side requests can never directly approve a listing.
                $this->putIfColumnExists($payload, 'live_status', 'under_review');

                foreach (['personal_email', 'business_email', 'personal_phone', 'business_phone'] as $contactColumn) {
                    if ($request->exists($contactColumn)) {
                        $this->putIfColumnExists($payload, $contactColumn, $request->input($contactColumn));
                    }
                }

                $this->putIfColumnExists($payload, 'content', $request->input('content'));
                $this->putIfColumnExists($payload, 'excerpt', $request->input('excerpt'));

                $this->putIfColumnExists($payload, 'country_id', $request->input('country_id'));
                $this->putIfColumnExists($payload, 'state_id', $request->input('state_id'));
                $this->putIfColumnExists($payload, 'city_id', $request->input('city_id'));
                $this->putIfColumnExists($payload, 'area_locality', $request->input('area_locality'));

                /*
                |--------------------------------------------------------------------------
                | Listing Code From SiteSetting
                |--------------------------------------------------------------------------
                | Same logic as DynamicPostController.
                | Uses property_prefix from site_settings for property-listing.
                |--------------------------------------------------------------------------
                */
                if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                    $payload['listing_code'] = $listingCode;
                }

                if (
                    Schema::hasColumn('dynamic_posts', 'published_at')
                    && ($payload['status'] ?? null) === 'published'
                ) {
                    $payload['published_at'] = now();
                }

                $listing = DynamicPost::create($payload);
                $this->applyReviewMetadataToListing($listing, 'create');

                $featuredImageFile = $this->featuredImageFile($request);

                if ($featuredImageFile) {
                    $featuredMedia = $this->storeListingMediaFile(
                        file: $featuredImageFile,
                        user: $user,
                        postType: $postType,
                        fieldSlug: 'featured_image'
                    );

                    if ($featuredMedia && Schema::hasColumn('dynamic_posts', 'featured_image_id')) {
                        $listing->featured_image_id = (int) $featuredMedia->id;
                    }
                } else {
                    $featuredMediaId = $this->numericInputValue($request->input('featured_image_id'));

                    if ($featuredMediaId && Schema::hasColumn('dynamic_posts', 'featured_image_id')) {
                        $listing->featured_image_id = $featuredMediaId;
                    }
                }

                $galleryIds = $this->galleryMediaIdsFromRequest($request);

                foreach ($this->galleryImageFiles($request) as $galleryFile) {
                    $galleryMedia = $this->storeListingMediaFile(
                        file: $galleryFile,
                        user: $user,
                        postType: $postType,
                        fieldSlug: 'gallery_images'
                    );

                    if ($galleryMedia) {
                        $galleryIds[] = (int) $galleryMedia->id;
                    }
                }

                $galleryIds = collect($galleryIds)
                    ->filter()
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->toArray();

                if (Schema::hasColumn('dynamic_posts', 'gallery_image_ids')) {
                    $listing->gallery_image_ids = json_encode($galleryIds);
                }

                $listing->save();

                $this->syncUserListingTaxonomyTerms($listing, $termIds);

                $this->storeCustomFieldsForListing(
                    listing: $listing,
                    customFields: $this->customFieldsFromRequest($request),
                    request: $request,
                    postType: $postType
                );

                $this->consumeMembershipListingCredit($request, $listing, 'frontend_listing');

                $this->propertyWorkflow()->registerInitialSubmission(
                    $listing->fresh(),
                    $user
                );
            });

            $freshListing = DynamicPost::query()
                ->where('id', $listing->id)
                ->with($this->listingRelations())
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Listing submitted for admin review.',
                'data' => $this->formatFullDynamicPost($freshListing),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to create listing.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, int $listing): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            if ($this->isAdminUser($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin token is not allowed for user property listing detail API.',
                ], 403);
            }

            $ownedListing = $this->findOwnedPropertyListing($listing, $user);

            if (!$ownedListing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Listing not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Listing fetched successfully.',
                'data' => $this->formatFullDynamicPost($ownedListing),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch listing.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(
        Request $request,
        int|string $listing
    ): JsonResponse {
        try {
            $listingId = $this->normalizeListingId($listing);

            if (!$listingId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid property listing ID.',
                ], 422);
            }

            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            if ($this->isAdminUser($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin token is not allowed to update a user property listing.',
                ], 403);
            }

            $ownedListing = $this->findOwnedPropertyListing(
                $listingId,
                $user
            );

            if (!$ownedListing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Listing not found.',
                ], 404);
            }

            $this->propertyWorkflow()->assertCanSubmitProperty($user);

            $workflowContext = $this->propertyWorkflow()
                ->prepareUserUpdate($ownedListing, $user);

            // Frontend users cannot choose approval/publication status.
            $request->request->remove('status');
            $request->request->remove('live_status');

            $request->validate([
                'title' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                ],

                'slug' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                ],

                'status' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::in([
                        'draft',
                        'published',
                        'private',
                        'archived',
                    ]),
                ],

                'live_status' => [
                    'sometimes',
                    'nullable',
                    'string',
                ],

                'content' => [
                    'sometimes',
                    'nullable',
                    'string',
                ],

                'excerpt' => [
                    'sometimes',
                    'nullable',
                    'string',
                ],

                'country_id' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    'exists:countries,id',
                ],

                'state_id' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    'exists:states,id',
                ],

                'city_id' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    'exists:cities,id',
                ],

                'area_locality' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                ],

                'personal_email' => [
                    'sometimes',
                    'nullable',
                    'email',
                    'max:255',
                ],

                'business_email' => [
                    'sometimes',
                    'nullable',
                    'email',
                    'max:255',
                ],

                'personal_phone' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:30',
                ],

                'business_phone' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:30',
                ],

                'custom_fields' => [
                    'sometimes',
                    'nullable',
                    'array',
                ],

                'custom_fields.*.custom_field_id' => [
                    'nullable',
                    'integer',
                ],

                'custom_fields.*.slug' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'custom_fields.*.name' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'custom_fields.*.label' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'custom_fields.*.value_string' => ['nullable'],
                'custom_fields.*.value_text' => ['nullable'],
                'custom_fields.*.value_number' => ['nullable'],
                'custom_fields.*.value_date' => ['nullable'],
                'custom_fields.*.value_datetime' => ['nullable'],
                'custom_fields.*.value_json' => ['nullable'],

                'taxonomy_term_ids' => [
                    'sometimes',
                    'nullable',
                ],

                'taxonomy_term_ids.*' => [
                    'nullable',
                    'integer',
                    'exists:taxonomy_terms,id',
                ],

                'taxonomies' => [
                    'sometimes',
                    'nullable',
                    'array',
                ],

                'taxonomies.*' => [
                    'nullable',
                ],

                'taxonomies.*.taxonomy_id' => [
                    'nullable',
                    'integer',
                    'exists:taxonomies,id',
                ],

                'taxonomies.*.taxonomy_term_id' => [
                    'nullable',
                    'integer',
                    'exists:taxonomy_terms,id',
                ],

                'taxonomies.*.taxonomy_term_ids' => [
                    'nullable',
                    'array',
                ],

                'taxonomies.*.taxonomy_term_ids.*' => [
                    'integer',
                    'exists:taxonomy_terms,id',
                ],

                'featured_image' => [
                    'sometimes',
                    'nullable',
                    'file',
                    'mimes:jpg,jpeg,png,webp,gif',
                    'max:10240',
                ],

                'featured_image_id' => [
                    'sometimes',
                    'nullable',
                ],

                'featured_image_id.*' => [
                    'nullable',
                ],

                'gallery_images' => [
                    'sometimes',
                    'nullable',
                ],

                'gallery_images.*' => [
                    'nullable',
                    'file',
                    'mimes:jpg,jpeg,png,webp,gif',
                    'max:10240',
                ],

                'gallery_image_ids' => [
                    'sometimes',
                    'nullable',
                ],

                'gallery_image_ids.*' => [
                    'nullable',
                ],
            ]);

            $postType = $this->propertyListingPostType($user);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Listing post type is not configured for this user role.',
                ], 404);
            }

            $this->validatePersonalBusinessContacts(
                $request,
                $ownedListing
            );

            DB::transaction(function () use (
                $request,
                $user,
                $postType,
                $ownedListing,
                $workflowContext
            ) {
                $payload = [];

                /*
                 * title and slug are system generated.
                 * Frontend title/slug are ignored for user listings.
                 */
                foreach (
                    [
                        'content',
                        'excerpt',
                        'country_id',
                        'state_id',
                        'city_id',
                        'area_locality',
                        'personal_email',
                        'business_email',
                        'personal_phone',
                        'business_phone',
                    ] as $column
                ) {
                    if ($request->exists($column)) {
                        $this->putIfColumnExists(
                            $payload,
                            $column,
                            $request->input($column)
                        );
                    }
                }

                foreach ($payload as $column => $value) {
                    $ownedListing->{$column} = $value;
                }

                /*
                |--------------------------------------------------------------------------
                | Rebuild title/slug from current listing details
                |--------------------------------------------------------------------------
                | This also automatically fixes old "Untitled Listing" records
                | the next time the owner updates them.
                |--------------------------------------------------------------------------
                */
                $hasTaxonomyUpdate =
                    $request->exists('taxonomy_term_ids')
                    || $request->exists('taxonomies');

                $titleTermIds = $hasTaxonomyUpdate
                    ? $this->normalizeUserListingTaxonomyTermIds($request)
                    : $ownedListing->taxonomyTerms
                        ->pluck('id')
                        ->map(fn($id) => (int) $id)
                        ->values()
                        ->toArray();

                $listingCode = trim((string) ($ownedListing->listing_code ?? ''));

                if (
                    $listingCode === ''
                    && Schema::hasColumn('dynamic_posts', 'listing_code')
                ) {
                    $listingCode = $this->generateListingCode($postType);
                    $ownedListing->listing_code = $listingCode;
                }

                $listingReference = $listingCode !== ''
                    ? $listingCode
                    : ('LST-' . (int) $ownedListing->id);

                $baseTitle = $this->buildAutomaticListingTitle(
                    postType: $postType,
                    taxonomyTermIds: $titleTermIds,
                    countryId: $this->nullableInteger($ownedListing->country_id ?? null),
                    stateId: $this->nullableInteger($ownedListing->state_id ?? null),
                    cityId: $this->nullableInteger($ownedListing->city_id ?? null),
                    areaLocality: $ownedListing->area_locality ?? null
                );

                $ownedListing->title = $this->uniqueDynamicPostTitle(
                    baseTitle: $baseTitle,
                    postTypeId: (int) $postType->id,
                    ignoreDynamicPostId: (int) $ownedListing->id,
                    uniqueReference: $listingReference
                );

                $ownedListing->slug = $this->uniqueDynamicPostSlug(
                    $ownedListing->title,
                    (int) $postType->id,
                    (int) $ownedListing->id
                );

                $this->applyReviewMetadataToListing(
                    $ownedListing,
                    'update'
                );


                $featuredImageFile = $this->featuredImageFile(
                    $request
                );

                if ($featuredImageFile) {
                    $featuredMedia = $this->storeListingMediaFile(
                        file: $featuredImageFile,
                        user: $user,
                        postType: $postType,
                        fieldSlug: 'featured_image'
                    );

                    if (
                        $featuredMedia
                        && Schema::hasColumn(
                            'dynamic_posts',
                            'featured_image_id'
                        )
                    ) {
                        $ownedListing->featured_image_id =
                            (int) $featuredMedia->id;
                    }
                } elseif (
                    $request->exists('featured_image_id')
                    && Schema::hasColumn(
                        'dynamic_posts',
                        'featured_image_id'
                    )
                ) {
                    $ownedListing->featured_image_id =
                        $this->numericInputValue(
                            $request->input('featured_image_id')
                        );
                }

                if (
                    $request->exists('gallery_image_ids')
                    || $request->hasFile('gallery_images')
                ) {
                    $galleryIds = $this->galleryMediaIdsFromRequest(
                        $request
                    );

                    foreach (
                        $this->galleryImageFiles($request)
                        as $galleryFile
                    ) {
                        $galleryMedia =
                            $this->storeListingMediaFile(
                                file: $galleryFile,
                                user: $user,
                                postType: $postType,
                                fieldSlug: 'gallery_images'
                            );

                        if ($galleryMedia) {
                            $galleryIds[] = (int) $galleryMedia->id;
                        }
                    }

                    $galleryIds = collect($galleryIds)
                        ->filter()
                        ->map(fn($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->toArray();

                    if (
                        Schema::hasColumn(
                            'dynamic_posts',
                            'gallery_image_ids'
                        )
                    ) {
                        $ownedListing->gallery_image_ids =
                            json_encode($galleryIds);
                    }
                }

                $ownedListing->save();

                if ($hasTaxonomyUpdate) {
                    $this->syncUserListingTaxonomyTerms(
                        $ownedListing,
                        $titleTermIds
                    );
                }

                $customFields = $this->customFieldsFromRequest($request);

                if (!empty($customFields)) {
                    $this->storeCustomFieldsForListing(
                        listing: $ownedListing,
                        customFields: $customFields,
                        request: $request,
                        postType: $postType
                    );
                }

                $this->propertyWorkflow()->registerUserUpdate(
                    $ownedListing->fresh(),
                    $user,
                    $workflowContext
                );
            });

            $freshListing = DynamicPost::query()
                ->where('id', $listingId)
                ->with($this->listingRelations())
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Listing changes submitted for admin review.',
                'data' => $this->formatFullDynamicPost(
                    $freshListing
                ),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update listing.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, int $listing): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            if ($this->isAdminUser($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin token is not allowed to delete user property listings.',
                ], 403);
            }

            $ownedListing = $this->findOwnedPropertyListing($listing, $user);

            if (!$ownedListing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Listing not found.',
                ], 404);
            }

            DB::transaction(function () use ($ownedListing) {
                $mediaIds = [];

                if (!empty($ownedListing->featured_image_id)) {
                    $mediaIds[] = (int) $ownedListing->featured_image_id;
                }

                $galleryIds = $this->normalizeIds(
                    $ownedListing->gallery_image_ids ?? []
                );

                $mediaIds = collect(array_merge($mediaIds, $galleryIds))
                    ->filter()
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->toArray();

                $mediaFiles = MediaFile::query()
                    ->whereIn('id', $mediaIds)
                    ->get();

                /*
             * Pehle listing aur related database data delete karein.
             */
                if (method_exists($ownedListing, 'taxonomyTerms')) {
                    $ownedListing->taxonomyTerms()->detach();
                }

                $metaTable = $this->dynamicPostMetaTable();

                if ($metaTable) {
                    $metaQuery = DB::table($metaTable);

                    if (Schema::hasColumn($metaTable, 'entity_id')) {
                        $metaQuery->where('entity_id', $ownedListing->id);
                    } elseif (Schema::hasColumn($metaTable, 'post_id')) {
                        $metaQuery->where('post_id', $ownedListing->id);
                    } elseif (Schema::hasColumn($metaTable, 'dynamic_post_id')) {
                        $metaQuery->where('dynamic_post_id', $ownedListing->id);
                    }

                    if (Schema::hasColumn($metaTable, 'entity_type')) {
                        $metaQuery->where('entity_type', 'post');
                    }

                    $metaQuery->delete();
                }

                if (
                    Schema::hasTable('custom_field_repeater_values')
                    && Schema::hasColumn('custom_field_repeater_values', 'entity_id')
                ) {
                    DB::table('custom_field_repeater_values')
                        ->where('entity_type', 'post')
                        ->where('entity_id', $ownedListing->id)
                        ->delete();
                }

                if (Schema::hasTable('property_featured_promotions')) {
                    DB::table('property_featured_promotions')
                        ->where('dynamic_post_id', $ownedListing->id)
                        ->delete();
                }

                if (Schema::hasTable('property_verification_revisions')) {
                    DB::table('property_verification_revisions')
                        ->where('dynamic_post_id', $ownedListing->id)
                        ->delete();
                }

                if (method_exists($ownedListing, 'forceDelete')) {
                    $ownedListing->forceDelete();
                } else {
                    $ownedListing->delete();
                }

                /*
             * Storage se actual files aur media_files entries delete karein.
             */
                foreach ($mediaFiles as $media) {
                    $disk = $media->disk ?: 'public';
                    $path = $media->path;

                    if (
                        $path
                        && Storage::disk($disk)->exists($path)
                    ) {
                        Storage::disk($disk)->delete($path);
                    }

                    if (method_exists($media, 'forceDelete')) {
                        $media->forceDelete();
                    } else {
                        $media->delete();
                    }
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'Listing and its images permanently deleted successfully.',
                'data' => [
                    'id' => $listing,
                    'deleted' => true,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete listing.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function propertyListingPostType(?User $user = null): ?PostType
    {
        // Keeps the original function name and old no-argument behaviour.
        if (!$user) {
            return PostType::query()
                ->where('slug', 'property-listing')
                ->first();
        }

        if (
            empty($user->role_id)
            || !Schema::hasTable('roles')
        ) {
            return null;
        }

        $role = DB::table('roles')
            ->where('id', (int) $user->role_id)
            ->first();

        if (!$role) {
            return null;
        }

        $roleValues = collect([
            $role->name ?? null,
            $role->slug ?? null,
            $role->role_name ?? null,
        ])
            ->filter()
            ->map(fn($value) => Str::slug((string) $value))
            ->unique()
            ->values()
            ->toArray();

        $rolePostTypeMap = [
            'agent' => 'property-listing',
            'agents' => 'property-listing',

            'owner' => 'property-listing',
            'owners' => 'property-listing',
            'property-owner' => 'property-listing',

            'consultancy' => 'property-listing',
            'consultant' => 'property-listing',
            'consultants' => 'property-listing',

            'developer' => 'developer-listing',
            'developers' => 'developer-listing',

            'company' => 'property-listing',
            'companies' => 'property-listing',
        ];

        $postTypeSlug = null;

        foreach ($roleValues as $roleValue) {
            if (isset($rolePostTypeMap[$roleValue])) {
                $postTypeSlug = $rolePostTypeMap[$roleValue];
                break;
            }
        }

        if (!$postTypeSlug) {
            return null;
        }

        return PostType::query()
            ->where('slug', $postTypeSlug)
            ->first();
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

    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (!$token && $request->filled('api_token')) {
            $token = $request->api_token;
        }

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function applyListingFilter($query, string $filter): void
    {
        match (strtolower(trim($filter))) {
            'active' => $query
                ->where('status', 'published')
                ->where('live_status', 'approve'),

            'inactive' => $query
                ->where(function ($q) {
                    $q->whereIn('live_status', ['reject', 'disapprove'])
                        ->orWhereIn('status', ['private', 'archived']);
                }),

            'draft' => $query
                ->where('status', 'draft'),

            'publish' => $query
                ->where('status', 'published'),

            'under_review' => $query
                ->whereIn('live_status', ['under_review', 'submit']),

            'rejected' => $query
                ->whereIn('live_status', ['reject', 'disapprove']),

            'delete_pending' => $query
                ->whereIn('live_status', ['under_review', 'submit'])
                ->where(function ($q) {
                    if (Schema::hasColumn('dynamic_posts', 'review_action')) {
                        $q->where('review_action', 'delete');
                    } else {
                        $q->where('status', 'archived');
                    }
                }),

            'featured' => $query
                ->whereHas('currentFeaturedPromotion'),

            'unfeatured', 'not_featured' => $query
                ->whereDoesntHave('currentFeaturedPromotion'),

            default => null,
        };
    }

    private function buildAnalytics($baseQuery): array
    {
        $totalListing = (clone $baseQuery)->count();

        $activeQuery = (clone $baseQuery)
            ->where('status', 'published')
            ->where('live_status', 'approve');

        $this->excludeExpiredListings($activeQuery);

        $activeListing = $activeQuery->count();

        $inactiveListing = (clone $baseQuery)
            ->where(function ($query) {
                $query->whereIn('live_status', ['reject', 'disapprove'])
                    ->orWhereIn('status', ['private', 'archived']);
            })
            ->count();

        $expiredQuery = clone $baseQuery;
        $hasExpiryColumn = $this->applyExpiredListingFilter($expiredQuery);

        $expiredListing = $hasExpiryColumn
            ? $expiredQuery->count()
            : 0;

        $draftListing = (clone $baseQuery)
            ->where('status', 'draft')
            ->count();

        $publishedListing = (clone $baseQuery)
            ->where('status', 'published')
            ->count();

        $underReviewListing = (clone $baseQuery)
            ->whereIn('live_status', ['under_review', 'submit'])
            ->count();

        $rejectedListing = (clone $baseQuery)
            ->whereIn('live_status', ['reject', 'disapprove'])
            ->count();

        $featuredListing = (clone $baseQuery)
            ->whereHas('currentFeaturedPromotion')
            ->count();

        $unfeaturedListing = (clone $baseQuery)
            ->whereDoesntHave('currentFeaturedPromotion')
            ->count();

        return [
            'total_listing' => $totalListing,
            'active_listing' => $activeListing,
            'inactive_listing' => $inactiveListing,
            'expired_listing' => $expiredListing,
            'draft_listing' => $draftListing,
            'published_listing' => $publishedListing,
            'under_review_listing' => $underReviewListing,
            'rejected_listing' => $rejectedListing,
            'featured_listing' => $featuredListing,
            'unfeatured_listing' => $unfeaturedListing,
        ];
    }

    private function formatFullDynamicPost(?DynamicPost $listing): ?array
    {
        if (!$listing) {
            return null;
        }

        $listing->loadMissing($this->listingRelations());

        $data = $listing->toArray();

        $data['listing_code'] = $listing->listing_code ?? null;
        $data['display_id'] = $listing->listing_code ?? null;
        $data['property_listing_id'] = $listing->listing_code ?? null;

        $latestRevision = $listing->latestVerificationRevision;

        $workflowStatus = $latestRevision?->status
            ?: $this->workflowStatusFromLegacy(
                $listing->status ?? null,
                $listing->live_status ?? null
            );

        $data['workflow_status'] = $workflowStatus;
        $data['verification_status'] = $workflowStatus;

        $data['review_status_label'] = $this->reviewStatusLabel(
            $workflowStatus
        );

        $data['latest_verification_revision'] = $latestRevision
            ? [
                'id' => (int) $latestRevision->id,
                'version' => (int) $latestRevision->version,
                'source' => $latestRevision->source,
                'status' => $latestRevision->status,
                'assigned_to' => $latestRevision->assigned_to
                    ? (int) $latestRevision->assigned_to
                    : null,
                'assigned_by' => $latestRevision->assigned_by
                    ? (int) $latestRevision->assigned_by
                    : null,
                'submitted_by' => $latestRevision->submitted_by
                    ? (int) $latestRevision->submitted_by
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

        $data['pending_action'] = $this->pendingReviewAction($listing);

        $data['is_under_review'] = in_array(
            $workflowStatus,
            [
                'under_review',
                'resubmission',
                'assigned',
                'in_verification',
                'submit',
            ],
            true
        );

        $data['is_active'] =
            $listing->status === 'published'
            && $listing->live_status === 'approve';

        $data['is_rejected'] = in_array(
            $listing->live_status,
            ['reject', 'disapprove'],
            true
        );

        $data['post_type'] = [
            'id' => $listing->postType
                ? (int) $listing->postType->id
                : null,
            'name' => $listing->postType?->name,
            'slug' => $listing->postType?->slug,
        ];

        $data['location'] = $this->formatLocationForDynamicPost($listing);

        $data['location_ids'] = [
            'country_id' => $data['location']['country_id'],
            'state_id' => $data['location']['state_id'],
            'city_id' => $data['location']['city_id'],
        ];

        $data['country'] = $data['location']['country_name'];
        $data['state'] = $data['location']['state_name'];
        $data['city'] = $data['location']['city_name'];

        $data['country_name'] = $data['location']['country_name'];
        $data['state_name'] = $data['location']['state_name'];
        $data['city_name'] = $data['location']['city_name'];

        $data['full_address'] = $data['location']['full_address'];

        unset(
            $data['country_id'],
            $data['state_id'],
            $data['city_id']
        );

        $featuredMedia = $this->formatMediaFileById(
            $listing->featured_image_id ?? null
        );

        $galleryMedia = $this->formatMediaFilesByIds(
            $listing->gallery_image_ids ?? []
        );

        $data['featured_image'] = $featuredMedia['url'] ?? null;
        $data['featured_image_media'] = $featuredMedia;

        $data['gallery_images'] = collect($galleryMedia)
            ->pluck('url')
            ->filter()
            ->values()
            ->toArray();

        $data['gallery_image_files'] = $galleryMedia;

        $data['selected_taxonomies'] = $this->formatSelectedTaxonomies(
            $listing
        );

        $data['meta'] = $this->formatMetaForFrontend(
            $data['meta'] ?? []
        );

        $featuredPromotion = $listing->currentFeaturedPromotion;

        $isFeatured = $featuredPromotion !== null
            && (
                !method_exists($featuredPromotion, 'isCurrentlyFeatured')
                || $featuredPromotion->isCurrentlyFeatured()
            );

        $data['is_featured'] = $isFeatured;
        $data['featured_id'] = $featuredPromotion ? (int) $featuredPromotion->id : null;
        $data['featured_promotion_id'] = $featuredPromotion ? (int) $featuredPromotion->id : null;
        $data['featured_via'] = $featuredPromotion?->source;
        $data['promotion_type'] = $featuredPromotion?->promotion_type;
        $data['featured_display_label'] = $featuredPromotion
            ? (($featuredPromotion->promotion_type === PropertyFeaturedPromotion::TYPE_SPONSORED) ? 'Sponsored' : 'Featured')
            : null;

        $data['featured'] = [
            'id' => $featuredPromotion ? (int) $featuredPromotion->id : null,
            'promotion_id' => $featuredPromotion ? (int) $featuredPromotion->id : null,
            'dynamic_post_id' => (int) $listing->id,
            'is_featured' => $isFeatured,
            'source' => $featuredPromotion?->source,
            'featured_via' => $featuredPromotion?->source,
            'promotion_type' => $featuredPromotion?->promotion_type,
            'display_label' => $featuredPromotion
                ? (($featuredPromotion->promotion_type === PropertyFeaturedPromotion::TYPE_SPONSORED) ? 'Sponsored' : 'Featured')
                : null,
            'status' => $featuredPromotion?->status,
            'priority' => $featuredPromotion ? (int) $featuredPromotion->priority : null,
            'placements' => [
                'home' => $featuredPromotion ? (bool) $featuredPromotion->show_on_home : false,
                'search' => $featuredPromotion ? (bool) $featuredPromotion->show_on_search : false,
                'property_detail' => $featuredPromotion ? (bool) $featuredPromotion->show_on_detail : false,
            ],
            'starts_at' => optional($featuredPromotion?->starts_at)->toISOString(),
            'ends_at' => optional($featuredPromotion?->ends_at)->toISOString(),
        ];

        return $data;
    }

    private function formatLocationForDynamicPost(DynamicPost $post): array
    {
        $countryId = !empty($post->country_id)
            ? (int) $post->country_id
            : null;

        $stateId = !empty($post->state_id)
            ? (int) $post->state_id
            : null;

        $cityId = !empty($post->city_id)
            ? (int) $post->city_id
            : null;

        $country = $countryId
            ? Country::query()
            ->select([
                'id',
                'name',
            ])
            ->find($countryId)
            : null;

        $state = $stateId
            ? State::query()
            ->select([
                'id',
                'name',
                'country_id',
            ])
            ->find($stateId)
            : null;

        $city = $cityId
            ? City::query()
            ->select([
                'id',
                'name',
                'state_id',
            ])
            ->find($cityId)
            : null;

        $fullAddress = collect([
            $post->area_locality ?? null,
            $city?->name,
            $state?->name,
            $country?->name,
        ])
            ->filter(function ($value) {
                return $value !== null && $value !== '';
            })
            ->values()
            ->implode(', ');

        return [
            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,

            'country_name' => $country?->name,
            'state_name' => $state?->name,
            'city_name' => $city?->name,

            'country' => $country
                ? [
                    'id' => (int) $country->id,
                    'name' => $country->name,
                ]
                : null,

            'state' => $state
                ? [
                    'id' => (int) $state->id,
                    'name' => $state->name,
                    'country_id' => !empty($state->country_id)
                        ? (int) $state->country_id
                        : null,
                ]
                : null,

            'city' => $city
                ? [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                    'state_id' => !empty($city->state_id)
                        ? (int) $city->state_id
                        : null,
                ]
                : null,

            'area_locality' => $post->area_locality ?? null,
            'full_address' => $fullAddress ?: null,
        ];
    }

    private function formatSelectedTaxonomies(DynamicPost $post): array
    {
        $terms = $post->taxonomyTerms ?? collect();

        return $terms
            ->groupBy('taxonomy_id')
            ->map(function ($taxonomyTerms) {
                $firstTerm = $taxonomyTerms->first();
                $taxonomy = $firstTerm->taxonomy;

                if (!$taxonomy) {
                    return null;
                }

                return [
                    'taxonomy_id' => (int) $taxonomy->id,
                    'taxonomy_name' => $taxonomy->name,
                    'taxonomy_slug' => $taxonomy->slug,
                    'selected_term_ids' => $taxonomyTerms
                        ->pluck('id')
                        ->map(fn($id) => (int) $id)
                        ->values()
                        ->toArray(),
                    'selected_terms' => $taxonomyTerms->map(fn($term) => [
                        'id' => (int) $term->id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ])->values()->toArray(),
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    private function formatMetaForFrontend(array $meta): array
    {
        return collect($meta)
            ->map(function ($item) {
                $fieldType = $item['custom_field']['field_type'] ?? null;

                if ($fieldType === 'repeater') {
                    $item['repeaters'] = $this->getRepeaterValuesForMeta(
                        (int) ($item['entity_id'] ?? $item['dynamic_post_id'] ?? $item['post_id'] ?? 0),
                        (int) ($item['custom_field_id'] ?? 0)
                    );

                    $item['value_json'] = null;

                    return $item;
                }

                $rawValueJson = $item['value_json'] ?? [];

                if (!in_array($fieldType, ['media', 'file'], true)) {
                    return $item;
                }

                $mediaFiles = $this->normalizeMediaMetaValue($rawValueJson);

                $mediaUrls = collect($mediaFiles)
                    ->pluck('url')
                    ->filter()
                    ->values()
                    ->toArray();

                $firstUrl = $mediaUrls[0] ?? null;

                $item['media_files'] = $mediaFiles;
                $item['media_urls'] = $mediaUrls;
                $item['value_string'] = $firstUrl;
                $item['value_text'] = $firstUrl;
                $item['value_json'] = $firstUrl;

                return $item;
            })
            ->values()
            ->toArray();
    }

    private function normalizeMediaMetaValue(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        if (isset($value['media']) && is_array($value['media'])) {
            $value = $value['media'];
        }

        return collect($value)
            ->filter(fn($media) => is_array($media))
            ->map(function ($media) {
                $path = $media['path'] ?? null;
                $url = $media['url'] ?? null;

                if (!$url && $path) {
                    $url = $this->storagePublicUrl($path);
                }

                return array_merge($media, [
                    'url' => $url,
                ]);
            })
            ->filter(fn($media) => !empty($media['url']))
            ->values()
            ->toArray();
    }

    private function getRepeaterValuesForMeta(int $entityId, int $customFieldId): array
    {
        if (!Schema::hasTable('custom_field_repeater_values')) {
            return [];
        }

        $values = DB::table('custom_field_repeater_values')
            ->where('entity_type', 'post')
            ->where('entity_id', $entityId)
            ->where('custom_field_id', $customFieldId)
            ->orderBy('row_index')
            ->orderBy('sort_order')
            ->get();

        if ($values->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($values as $value) {
            $rowIndex = property_exists($value, 'row_index') ? (int) $value->row_index : 0;
            $fieldSlug = $value->field_name_slug ?? null;

            if (!$fieldSlug) {
                continue;
            }

            $fieldValue = $value->field_meta_value
                ?? $value->value_string
                ?? $value->value_text
                ?? $value->value_number
                ?? $value->value_date
                ?? $value->value_datetime
                ?? $value->value_json
                ?? null;

            $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : null;

            $rows[$rowIndex][$fieldSlug] = json_last_error() === JSON_ERROR_NONE && is_array($decoded)
                ? $decoded
                : $fieldValue;
        }

        ksort($rows);

        return collect($rows)->values()->toArray();
    }

    private function formatMediaFileById(null|int|string $mediaId): ?array
    {
        if (empty($mediaId)) {
            return null;
        }

        $media = MediaFile::find((int) $mediaId);

        return $media ? $this->formatMediaFile($media) : null;
    }

    private function formatMediaFilesByIds(array|string|null $mediaIds): array
    {
        $ids = $this->normalizeIds($mediaIds);

        if (empty($ids)) {
            return [];
        }

        $mediaFiles = MediaFile::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn($id) => $mediaFiles->get((int) $id))
            ->filter()
            ->map(fn($media) => $this->formatMediaFile($media))
            ->values()
            ->toArray();
    }

    private function formatMediaFile(MediaFile $media): array
    {
        $path = $media->path ?? null;
        $url = $media->url ?? null;

        if (!$url && $path) {
            $url = $this->storagePublicUrl($path);
        }

        return [
            'id' => (int) $media->id,
            'disk' => $media->disk ?? 'public',
            'context' => $media->context ?? null,
            'post_type_slug' => $media->post_type_slug ?? null,
            'field_slug' => $media->field_slug ?? null,
            'directory' => $media->directory ?? null,
            'path' => $path,
            'url' => $url,
            'file_name' => $media->file_name ?? null,
            'original_name' => $media->original_name ?? null,
            'mime_type' => $media->mime_type ?? null,
            'extension' => $media->extension ?? null,
            'size' => $media->size ?? null,
            'size_kb' => $media->size ? round($media->size / 1024, 2) : null,
            'created_at' => optional($media->created_at)->toISOString(),
            'updated_at' => optional($media->updated_at)->toISOString(),
        ];
    }

    private function normalizeIds(array|string|null $ids): array
    {
        if (is_null($ids) || $ids === '') {
            return [];
        }

        if (is_string($ids)) {
            $ids = trim($ids);

            if ($ids === '') {
                return [];
            }

            $decoded = json_decode($ids, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = str_contains($ids, ',') ? explode(',', $ids) : [$ids];
            }
        }

        return collect($ids)
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
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
    private function workflowStatusFromLegacy(
        ?string $status,
        ?string $liveStatus
    ): string {
        if ($liveStatus === 'approve' && $status === 'published') {
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

    private function applyExpiredListingFilter($query): bool
    {
        $expiryColumn = $this->expiryColumn();

        if ($expiryColumn) {
            $query->whereNotNull($expiryColumn)
                ->where($expiryColumn, '<', now());

            return true;
        }

        if (Schema::hasColumn('dynamic_posts', 'status')) {
            $query->where('status', 'expired');

            return true;
        }

        return false;
    }

    private function excludeExpiredListings($query): void
    {
        $expiryColumn = $this->expiryColumn();

        if ($expiryColumn) {
            $query->where(function ($q) use ($expiryColumn) {
                $q->whereNull($expiryColumn)
                    ->orWhere($expiryColumn, '>=', now());
            });
        }
    }

    private function expiryColumn(): ?string
    {
        foreach (
            [
                'expired_at',
                'expires_at',
                'expiry_date',
                'valid_till',
                'valid_until',
            ] as $column
        ) {
            if (Schema::hasColumn('dynamic_posts', $column)) {
                return $column;
            }
        }

        return null;
    }

    private function userDisplayName(User $user): ?string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $fullName
            ?: ($user->name ?? null)
            ?: ($user->email ?? null);
    }

    private function isAdminUser(User $user): bool
    {
        $blockedRoles = [
            'admin',
            'administrator',
            'super_admin',
            'super admin',
            'super-admin',
        ];

        if (Schema::hasColumn('users', 'role')) {
            $role = strtolower(trim((string) ($user->role ?? '')));

            if (in_array($role, $blockedRoles, true)) {
                return true;
            }
        }

        if (
            Schema::hasColumn('users', 'role_id')
            && !empty($user->role_id)
            && Schema::hasTable('roles')
        ) {
            $role = DB::table('roles')
                ->where('id', $user->role_id)
                ->first();

            if ($role) {
                foreach (['name', 'slug', 'role_name'] as $column) {
                    if (property_exists($role, $column)) {
                        $value = strtolower(trim((string) $role->{$column}));

                        if (in_array($value, $blockedRoles, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function findOwnedPropertyListing(int $listingId, User $user): ?DynamicPost
    {
        $postType = $this->propertyListingPostType($user);

        if (!$postType || !Schema::hasColumn('dynamic_posts', 'author_id')) {
            return null;
        }

        return DynamicPost::query()
            ->with($this->listingRelations())
            ->where('id', $listingId)
            ->where('post_type_id', (int) $postType->id)
            ->where('author_id', (int) $user->id)
            ->first();
    }

    private function applyReviewMetadataToListing(DynamicPost $listing, string $action): void
    {
        /*
     * First update ke time original approved status save karo.
     * Agar listing already under_review hai aur user dobara edit kare,
     * previous status overwrite nahi hona chahiye.
     */
        if ($action !== 'create') {
            if (
                Schema::hasColumn('dynamic_posts', 'review_previous_status')
                && empty($listing->review_previous_status)
            ) {
                $listing->review_previous_status = $listing->status ?? null;
            }

            if (
                Schema::hasColumn('dynamic_posts', 'review_previous_live_status')
                && empty($listing->review_previous_live_status)
            ) {
                $listing->review_previous_live_status = $listing->live_status ?? null;
            }
        }

        if (Schema::hasColumn('dynamic_posts', 'review_action')) {
            $listing->review_action = $action;
        }

        if (Schema::hasColumn('dynamic_posts', 'review_requested_at')) {
            $listing->review_requested_at = now();
        }

        if (Schema::hasColumn('dynamic_posts', 'reviewed_at')) {
            $listing->reviewed_at = null;
        }

        if (Schema::hasColumn('dynamic_posts', 'reviewed_by')) {
            $listing->reviewed_by = null;
        }

        if (Schema::hasColumn('dynamic_posts', 'rejection_reason')) {
            $listing->rejection_reason = null;
        }

        /*
     * User-side create/update ke baad direct published nahi rahega.
     * Admin approve karega tab published + approve hoga.
     */
        if (Schema::hasColumn('dynamic_posts', 'status')) {
            $listing->status = 'draft';
        }

        if (Schema::hasColumn('dynamic_posts', 'live_status')) {
            $listing->live_status = 'under_review';
        }

        if (Schema::hasColumn('dynamic_posts', 'published_at')) {
            $listing->published_at = null;
        }
    }

    private function pendingReviewAction(DynamicPost $listing): ?string
    {
        if (!in_array($listing->live_status ?? null, ['under_review', 'submit'], true)) {
            return null;
        }

        if (!empty($listing->review_action)) {
            return (string) $listing->review_action;
        }

        if (($listing->status ?? null) === 'archived') {
            return 'delete';
        }

        return 'update';
    }

    private function validatePersonalBusinessContacts(
        Request $request,
        ?DynamicPost $existingListing = null
    ): void {
        $values = $existingListing
            ? $this->contactValuesFromListing($existingListing)
            : [];

        foreach (['personal_email', 'business_email', 'personal_phone', 'business_phone'] as $key) {
            if ($request->exists($key)) {
                $values[$key] = $request->input($key);
            }
        }

        foreach (($request->input('custom_fields', []) ?: []) as $customField) {
            if (!is_array($customField)) {
                continue;
            }

            $contactKey = $this->contactKeyFromCustomField($customField);

            if (!$contactKey) {
                continue;
            }

            $values[$contactKey] = $this->customFieldRequestValue($customField);
        }

        $personalEmail = $this->normalizeEmailForComparison($values['personal_email'] ?? null);
        $businessEmail = $this->normalizeEmailForComparison($values['business_email'] ?? null);

        if ($personalEmail && $businessEmail && $personalEmail === $businessEmail) {
            throw ValidationException::withMessages([
                'personal_email' => ['Personal email and business email must be different.'],
                'business_email' => ['Business email and personal email must be different.'],
            ]);
        }

        $personalPhone = $this->normalizePhoneForComparison($values['personal_phone'] ?? null);
        $businessPhone = $this->normalizePhoneForComparison($values['business_phone'] ?? null);

        if ($personalPhone && $businessPhone && $personalPhone === $businessPhone) {
            throw ValidationException::withMessages([
                'personal_phone' => ['Personal phone and business phone must be different.'],
                'business_phone' => ['Business phone and personal phone must be different.'],
            ]);
        }
    }

    private function contactValuesFromListing(DynamicPost $listing): array
    {
        $values = [];

        foreach (['personal_email', 'business_email', 'personal_phone', 'business_phone'] as $column) {
            if (Schema::hasColumn('dynamic_posts', $column)) {
                $values[$column] = $listing->{$column} ?? null;
            }
        }

        $listing->loadMissing('meta.customField');

        foreach (($listing->meta ?? collect()) as $meta) {
            $customField = $meta->customField ?? null;

            if (!$customField) {
                continue;
            }

            $descriptor = [];

            foreach (['slug', 'name', 'label', 'field_name', 'meta_key', 'key'] as $property) {
                if (!empty($customField->{$property})) {
                    $descriptor[] = (string) $customField->{$property};
                }
            }

            $contactKey = $this->contactKeyFromDescriptor(implode(' ', $descriptor));

            if (!$contactKey) {
                continue;
            }

            $values[$contactKey] = $meta->value_string
                ?? $meta->value_text
                ?? $meta->value_number
                ?? $meta->field_meta_value
                ?? null;
        }

        return $values;
    }

    private function contactKeyFromCustomField(array $customField): ?string
    {
        $descriptorParts = [];

        foreach (['slug', 'name', 'label', 'field_name', 'meta_key', 'key'] as $key) {
            if (!empty($customField[$key])) {
                $descriptorParts[] = (string) $customField[$key];
            }
        }

        if (empty($descriptorParts) && !empty($customField['custom_field_id'])) {
            $descriptorParts = $this->customFieldDescriptorById((int) $customField['custom_field_id']);
        }

        return $this->contactKeyFromDescriptor(implode(' ', $descriptorParts));
    }

    private function customFieldDescriptorById(int $customFieldId): array
    {
        if (!$customFieldId || !Schema::hasTable('custom_fields')) {
            return [];
        }

        $columns = collect([
            'slug',
            'name',
            'label',
            'field_name',
            'meta_key',
            'key',
        ])->filter(fn($column) => Schema::hasColumn('custom_fields', $column))
            ->values()
            ->toArray();

        if (empty($columns)) {
            return [];
        }

        $field = DB::table('custom_fields')
            ->select(array_merge(['id'], $columns))
            ->where('id', $customFieldId)
            ->first();

        if (!$field) {
            return [];
        }

        return collect($columns)
            ->map(fn($column) => $field->{$column} ?? null)
            ->filter()
            ->map(fn($value) => (string) $value)
            ->values()
            ->toArray();
    }

    private function contactKeyFromDescriptor(string $descriptor): ?string
    {
        $value = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $descriptor) ?? ''));

        $isEmail = str_contains($value, 'email');
        $isPhone = str_contains($value, 'phone')
            || str_contains($value, 'mobile')
            || str_contains($value, 'telephone')
            || str_contains($value, 'contact_number');

        $isPersonal = str_contains($value, 'personal')
            || str_contains($value, 'private');

        $isBusiness = str_contains($value, 'business')
            || str_contains($value, 'company')
            || str_contains($value, 'office')
            || str_contains($value, 'work');

        return match (true) {
            $isPersonal && $isEmail => 'personal_email',
            $isBusiness && $isEmail => 'business_email',
            $isPersonal && $isPhone => 'personal_phone',
            $isBusiness && $isPhone => 'business_phone',
            default => null,
        };
    }

    private function customFieldRequestValue(array $customField): mixed
    {
        foreach (
            [
                'value_string',
                'value_text',
                'value_number',
                'value_date',
                'value_datetime',
                'value_json',
            ] as $key
        ) {
            if (array_key_exists($key, $customField)) {
                $value = $customField[$key];

                return is_array($value) ? json_encode($value) : $value;
            }
        }

        return null;
    }

    private function normalizeEmailForComparison(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $email = strtolower(trim((string) $value));

        return $email !== '' ? $email : null;
    }

    private function normalizePhoneForComparison(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // +91 98765 43210 and 9876543210 are treated as the same number.
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function putIfColumnExists(array &$payload, string $column, mixed $value): void
    {
        if (Schema::hasColumn('dynamic_posts', $column)) {
            $payload[$column] = $value;
        }
    }

    private function buildAutomaticListingTitle(
        PostType $postType,
        array $taxonomyTermIds,
        ?int $countryId = null,
        ?int $stateId = null,
        ?int $cityId = null,
        ?string $areaLocality = null
    ): string {
        /*
        |--------------------------------------------------------------------------
        | 1. Important taxonomy terms
        |--------------------------------------------------------------------------
        | Example:
        | 2 BHK + Apartment + For Sale
        |--------------------------------------------------------------------------
        */
        $termNames = $this->listingTitleTermNames($taxonomyTermIds);

        /*
        |--------------------------------------------------------------------------
        | 2. Post type fallback
        |--------------------------------------------------------------------------
        | "property-listing" becomes "Property".
        | For property listings, taxonomy terms normally provide a better
        | subject such as Apartment / Plot / Villa / Commercial.
        |--------------------------------------------------------------------------
        */
        $postTypeLabel = $this->listingPostTypeTitleLabel($postType);

        if (empty($termNames)) {
            $termNames[] = $postTypeLabel;
        } elseif (
            Str::slug((string) $postType->slug) !== 'property-listing'
            && !collect($termNames)->contains(
                fn(string $termName) =>
                    Str::lower($termName) === Str::lower($postTypeLabel)
            )
        ) {
            $termNames[] = $postTypeLabel;
        }

        $subject = trim(implode(' ', $termNames));

        if ($subject === '') {
            $subject = 'Listing';
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Location
        |--------------------------------------------------------------------------
        | Example:
        | Mall Road, Shimla, Himachal Pradesh, India
        |--------------------------------------------------------------------------
        */
        $locationParts = $this->listingTitleLocationParts(
            countryId: $countryId,
            stateId: $stateId,
            cityId: $cityId,
            areaLocality: $areaLocality
        );

        $title = $subject;

        if (!empty($locationParts)) {
            $title .= ' in ' . implode(', ', $locationParts);
        }

        $title = preg_replace('/\s+/', ' ', trim($title)) ?: 'Listing';

        /*
         * Leave space for unique listing reference appended later.
         */
        return mb_substr($title, 0, 210);
    }

    private function listingTitleTermNames(array $taxonomyTermIds): array
    {
        $taxonomyTermIds = collect($taxonomyTermIds)
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        if (
            empty($taxonomyTermIds)
            || !Schema::hasTable('taxonomy_terms')
        ) {
            return [];
        }

        $query = DB::table('taxonomy_terms as terms')
            ->whereIn('terms.id', $taxonomyTermIds);

        if (Schema::hasTable('taxonomies')) {
            $query->leftJoin(
                'taxonomies as taxonomies',
                'taxonomies.id',
                '=',
                'terms.taxonomy_id'
            );

            $rows = $query
                ->select([
                    'terms.id',
                    'terms.name',
                    'terms.slug',
                    'terms.taxonomy_id',
                    'taxonomies.name as taxonomy_name',
                    'taxonomies.slug as taxonomy_slug',
                ])
                ->get();
        } else {
            $rows = $query
                ->select([
                    'terms.id',
                    'terms.name',
                    'terms.slug',
                    'terms.taxonomy_id',
                ])
                ->get()
                ->map(function ($row) {
                    $row->taxonomy_name = null;
                    $row->taxonomy_slug = null;

                    return $row;
                });
        }

        /*
         * Make SEO title useful instead of dumping every amenity into it.
         * Priority:
         * BHK/configuration -> property type -> transaction/purpose ->
         * status -> remaining selected terms.
         */
        $rows = $rows
            ->sortBy(function ($row) {
                return sprintf(
                    '%03d-%010d',
                    $this->listingTitleTaxonomyPriority(
                        $row->taxonomy_slug
                            ?? $row->taxonomy_name
                            ?? null
                    ),
                    (int) $row->id
                );
            })
            ->values();

        return $rows
            ->map(function ($row) {
                return $this->formatListingTitleTermName(
                    name: (string) ($row->name ?? ''),
                    taxonomy: $row->taxonomy_slug
                        ?? $row->taxonomy_name
                        ?? null
                );
            })
            ->filter(fn($name) => trim((string) $name) !== '')
            ->unique(fn($name) => Str::lower((string) $name))
            /*
             * Four meaningful terms are enough for a readable SEO title.
             * Location + listing code are added separately.
             */
            ->take(4)
            ->values()
            ->toArray();
    }

    private function formatListingTitleTermName(
        string $name,
        ?string $taxonomy
    ): string {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $taxonomySlug = Str::slug((string) $taxonomy);
        $termSlug = Str::slug($name);

        /*
         * Purpose terms should read naturally in the generated title.
         */
        if (
            str_contains($taxonomySlug, 'purpose')
            || str_contains($taxonomySlug, 'transaction')
            || str_contains($taxonomySlug, 'listing-type')
            || str_contains($taxonomySlug, 'property-for')
            || str_contains($taxonomySlug, 'sale-rent')
        ) {
            return match ($termSlug) {
                'sell', 'sale', 'for-sale' => 'For Sale',
                'rent', 'rental', 'for-rent' => 'For Rent',
                'lease', 'for-lease' => 'For Lease',
                default => $name,
            };
        }

        return $name;
    }

    private function listingTitleTaxonomyPriority(?string $taxonomy): int
    {
        $taxonomy = Str::slug((string) $taxonomy);

        if ($taxonomy === '') {
            return 90;
        }

        if (
            str_contains($taxonomy, 'bhk')
            || str_contains($taxonomy, 'bedroom')
            || str_contains($taxonomy, 'configuration')
        ) {
            return 5;
        }

        /*
         * User's taxonomy "Property" contains values such as
         * Residential / Commercial.
         */
        if ($taxonomy === 'property') {
            return 10;
        }

        if (
            str_contains($taxonomy, 'property-type')
            || str_contains($taxonomy, 'property-category')
            || str_contains($taxonomy, 'category')
            || str_contains($taxonomy, 'unit-type')
        ) {
            return 20;
        }

        if (
            str_contains($taxonomy, 'transaction')
            || str_contains($taxonomy, 'purpose')
            || str_contains($taxonomy, 'listing-type')
            || str_contains($taxonomy, 'property-for')
            || str_contains($taxonomy, 'sale-rent')
        ) {
            return 30;
        }

        if (
            str_contains($taxonomy, 'property-status')
            || str_contains($taxonomy, 'possession')
            || str_contains($taxonomy, 'availability')
        ) {
            return 40;
        }

        if (str_contains($taxonomy, 'furnish')) {
            return 50;
        }

        return 90;
    }

    private function listingPostTypeTitleLabel(PostType $postType): string
    {
        $label = trim((string) (
            $postType->name
                ?: $postType->slug
                ?: 'Listing'
        ));

        $label = preg_replace(
            '/\s+listing\s*$/i',
            '',
            $label
        ) ?: $label;

        $label = trim($label);

        return $label !== ''
            ? Str::title(str_replace(['-', '_'], ' ', $label))
            : 'Listing';
    }

    private function listingTitleLocationParts(
        ?int $countryId = null,
        ?int $stateId = null,
        ?int $cityId = null,
        ?string $areaLocality = null
    ): array {
        $parts = [];

        $areaLocality = trim((string) $areaLocality);

        if ($areaLocality !== '') {
            $parts[] = $areaLocality;
        }

        if ($cityId) {
            $cityName = City::query()
                ->whereKey($cityId)
                ->value('name');

            if ($cityName) {
                $parts[] = trim((string) $cityName);
            }
        }

        if ($stateId) {
            $stateName = State::query()
                ->whereKey($stateId)
                ->value('name');

            if ($stateName) {
                $parts[] = trim((string) $stateName);
            }
        }

        if ($countryId) {
            $countryName = Country::query()
                ->whereKey($countryId)
                ->value('name');

            if ($countryName) {
                $parts[] = trim((string) $countryName);
            }
        }

        return collect($parts)
            ->filter()
            ->unique(fn($value) => Str::lower(trim((string) $value)))
            ->values()
            ->toArray();
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (
            $value === null
            || $value === ''
            || !is_numeric($value)
        ) {
            return null;
        }

        return (int) $value;
    }

    private function uniqueDynamicPostTitle(
        string $baseTitle,
        ?int $postTypeId = null,
        ?int $ignoreDynamicPostId = null,
        ?string $uniqueReference = null
    ): string {
        $baseTitle = preg_replace(
            '/\s+/',
            ' ',
            trim($baseTitle)
        ) ?: 'Listing';

        $uniqueReference = trim((string) $uniqueReference);

        /*
         * listing_code is the preferred uniqueness token.
         * Example:
         * 2 BHK Apartment For Sale in Shimla - PRP-000123
         */
        $referenceSuffix = $uniqueReference !== ''
            ? ' - ' . $uniqueReference
            : '';

        $candidate = $this->fitListingTitle(
            $baseTitle,
            $referenceSuffix
        );

        if (
            !$this->dynamicPostTitleExists(
                $candidate,
                $postTypeId,
                $ignoreDynamicPostId
            )
        ) {
            return $candidate;
        }

        /*
         * Defensive fallback for old duplicate data or environments where
         * listing_code was not available.
         */
        $counter = 2;

        do {
            $collisionSuffix = $referenceSuffix !== ''
                ? $referenceSuffix . '-' . $counter
                : ' - ' . $counter;

            $candidate = $this->fitListingTitle(
                $baseTitle,
                $collisionSuffix
            );

            $counter++;
        } while (
            $this->dynamicPostTitleExists(
                $candidate,
                $postTypeId,
                $ignoreDynamicPostId
            )
        );

        return $candidate;
    }

    private function fitListingTitle(
        string $baseTitle,
        string $suffix = ''
    ): string {
        $maxLength = 255;
        $suffixLength = mb_strlen($suffix);
        $baseLength = max(1, $maxLength - $suffixLength);

        $baseTitle = rtrim(
            mb_substr($baseTitle, 0, $baseLength)
        );

        return $baseTitle . $suffix;
    }

    private function dynamicPostTitleExists(
        string $title,
        ?int $postTypeId = null,
        ?int $ignoreDynamicPostId = null
    ): bool {
        if (!Schema::hasColumn('dynamic_posts', 'title')) {
            return false;
        }

        $query = DynamicPost::query()
            ->where('title', $title);

        if (
            $postTypeId
            && Schema::hasColumn(
                'dynamic_posts',
                'post_type_id'
            )
        ) {
            $query->where(
                'post_type_id',
                $postTypeId
            );
        }

        if ($ignoreDynamicPostId) {
            $query->where(
                'id',
                '!=',
                $ignoreDynamicPostId
            );
        }

        return $query->exists();
    }

    private function uniqueDynamicPostSlug(
        string $title,
        ?int $postTypeId = null,
        ?int $ignoreDynamicPostId = null
    ): string {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'listing';
        }

        /*
         * Keep room for defensive numeric suffix.
         */
        $baseSlug = mb_substr($baseSlug, 0, 235);
        $slug = $baseSlug;

        if (
            !$this->dynamicPostSlugExists(
                $slug,
                $postTypeId,
                $ignoreDynamicPostId
            )
        ) {
            return $slug;
        }

        $counter = 2;

        do {
            $suffix = '-' . $counter;
            $slug = mb_substr(
                $baseSlug,
                0,
                255 - mb_strlen($suffix)
            ) . $suffix;

            $counter++;
        } while (
            $this->dynamicPostSlugExists(
                $slug,
                $postTypeId,
                $ignoreDynamicPostId
            )
        );

        return $slug;
    }

    private function dynamicPostSlugExists(
        string $slug,
        ?int $postTypeId = null,
        ?int $ignoreDynamicPostId = null
    ): bool {
        if (!Schema::hasColumn('dynamic_posts', 'slug')) {
            return false;
        }

        $query = DynamicPost::query()
            ->where('slug', $slug);

        if (
            $postTypeId
            && Schema::hasColumn(
                'dynamic_posts',
                'post_type_id'
            )
        ) {
            $query->where(
                'post_type_id',
                $postTypeId
            );
        }

        if ($ignoreDynamicPostId) {
            $query->where(
                'id',
                '!=',
                $ignoreDynamicPostId
            );
        }

        return $query->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | SiteSetting Based Listing Code
    |--------------------------------------------------------------------------
    | Same style as DynamicPostController:
    | property-listing => site_settings.property_prefix
    | developer       => site_settings.developer_prefix
    | project         => site_settings.project_prefix
    |--------------------------------------------------------------------------
    */
    private function generateListingCode(PostType $postType): string
    {
        $prefix = $this->getDynamicPostPrefix($postType);

        $lastCode = DynamicPost::query()
            ->where('post_type_id', (int) $postType->id)
            ->whereNotNull('listing_code')
            ->where('listing_code', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('listing_code');

        $nextNumber = 1;

        if (!empty($lastCode) && preg_match('/-(\d+)$/', $lastCode, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        do {
            $code = $prefix . '-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);

            $exists = DynamicPost::query()
                ->where('listing_code', $code)
                ->exists();

            $nextNumber++;
        } while ($exists);

        return $code;
    }

    private function getDynamicPostPrefix(PostType $postType): string
    {
        $setting = SiteSetting::query()->first();

        $slug = Str::slug($postType->slug ?? $postType->name ?? '', '-');
        $name = Str::slug($postType->name ?? '', '-');

        if (str_contains($slug, 'property') || str_contains($name, 'property')) {
            return $this->cleanPrefix($setting?->property_prefix ?: 'PRP');
        }

        if (str_contains($slug, 'developer') || str_contains($name, 'developer')) {
            return $this->cleanPrefix($setting?->developer_prefix ?: 'DEV');
        }

        if (str_contains($slug, 'project') || str_contains($name, 'project')) {
            return $this->cleanPrefix($setting?->project_prefix ?: 'PRJ');
        }

        return $this->cleanPrefix(strtoupper(substr(Str::slug($postType->name ?? 'DYN', ''), 0, 4)) ?: 'DYN');
    }

    private function cleanPrefix(?string $prefix): string
    {
        $prefix = strtoupper(trim((string) $prefix));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix);

        return $prefix ?: 'DYN';
    }

    private function featuredImageFile(Request $request): ?UploadedFile
    {
        if ($request->hasFile('featured_image')) {
            $file = $request->file('featured_image');

            return $file instanceof UploadedFile ? $file : null;
        }

        if ($request->hasFile('featured_image_id')) {
            $file = $request->file('featured_image_id');

            if ($file instanceof UploadedFile) {
                return $file;
            }

            if (is_array($file)) {
                $first = collect($file)->flatten()->first();

                return $first instanceof UploadedFile ? $first : null;
            }
        }

        return null;
    }

    private function galleryImageFiles(Request $request): array
    {
        $files = [];

        foreach (['gallery_images', 'gallery_image_ids'] as $key) {
            if (!$request->hasFile($key)) {
                continue;
            }

            $uploaded = $request->file($key);

            if ($uploaded instanceof UploadedFile) {
                $files[] = $uploaded;
                continue;
            }

            if (is_array($uploaded)) {
                foreach (collect($uploaded)->flatten() as $file) {
                    if ($file instanceof UploadedFile) {
                        $files[] = $file;
                    }
                }
            }
        }

        return $files;
    }

    private function galleryMediaIdsFromRequest(Request $request): array
    {
        $ids = [];

        $value = $request->input('gallery_image_ids');

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = str_contains($value, ',') ? explode(',', $value) : [$value];
            }
        }

        if (is_array($value)) {
            $ids = collect($value)
                ->flatten()
                ->filter(fn($id) => is_numeric($id))
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();
        } elseif (is_numeric($value)) {
            $ids[] = (int) $value;
        }

        return $ids;
    }

    private function numericInputValue(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = collect($value)
                ->flatten()
                ->first(fn($item) => is_numeric($item));
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function storeListingMediaFile(
        UploadedFile $file,
        User $user,
        PostType $postType,
        string $fieldSlug
    ): ?MediaFile {
        if (!$file->isValid()) {
            throw new \Exception($fieldSlug . ' upload failed.');
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                $fieldSlug => [
                    'Invalid image format. Allowed formats: ' . implode(', ', $allowedExtensions),
                ],
            ]);
        }

        $maxSizeKb = 10240;

        if (($file->getSize() / 1024) > $maxSizeKb) {
            throw ValidationException::withMessages([
                $fieldSlug => [
                    'Image size must not be greater than 10MB.',
                ],
            ]);
        }

        $postTypeSlug = Str::slug($postType->slug ?? $postType->name ?? 'common', '-');

        $type = $fieldSlug === 'featured_image'
            ? 'featured-image'
            : 'gallery';

        $originalBaseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($originalBaseName ?: $type, '-');

        if (empty($safeBaseName)) {
            $safeBaseName = $type;
        }

        $fileName = $safeBaseName
            . '-'
            . substr((string) Str::uuid(), 0, 8)
            . '.'
            . $extension;

        $directory = implode('/', [
            'uploads',
            'dynamic-posts',
            $postTypeSlug,
            $type,
            now()->format('Y'),
            now()->format('m'),
        ]);

        $path = $file->storeAs($directory, $fileName, 'public');

        if (!$path || !Storage::disk('public')->exists($path)) {
            throw new \Exception($fieldSlug . ' could not be saved in dynamic post upload path.');
        }

        $payload = [];

        $this->putMediaColumnIfExists($payload, 'disk', 'public');
        $this->putMediaColumnIfExists($payload, 'context', 'dynamic-posts');
        $this->putMediaColumnIfExists($payload, 'post_type_slug', $postTypeSlug);
        $this->putMediaColumnIfExists($payload, 'field_slug', $type);
        $this->putMediaColumnIfExists($payload, 'directory', $directory);
        $this->putMediaColumnIfExists($payload, 'path', $path);
        $this->putMediaColumnIfExists($payload, 'url', $this->storagePublicUrl($path));
        $this->putMediaColumnIfExists($payload, 'file_name', $fileName);
        $this->putMediaColumnIfExists($payload, 'original_name', $file->getClientOriginalName());
        $this->putMediaColumnIfExists($payload, 'mime_type', $file->getMimeType());
        $this->putMediaColumnIfExists($payload, 'extension', $extension);
        $this->putMediaColumnIfExists($payload, 'size', $file->getSize());

        if (Schema::hasColumn('media_files', 'uploaded_by')) {
            $payload['uploaded_by'] = (int) $user->id;
        }

        if (Schema::hasColumn('media_files', 'created_by')) {
            $payload['created_by'] = (int) $user->id;
        }

        if (Schema::hasColumn('media_files', 'user_id')) {
            $payload['user_id'] = (int) $user->id;
        }

        return MediaFile::create($payload);
    }

    private function putMediaColumnIfExists(array &$payload, string $column, mixed $value): void
    {
        if (Schema::hasTable('media_files') && Schema::hasColumn('media_files', $column)) {
            $payload[$column] = $value;
        }
    }

    private function storagePublicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/storage/')) {
            return url($path);
        }

        if (str_starts_with($path, 'storage/')) {
            return url($path);
        }

        return url(Storage::disk('public')->url($path));
    }

    private function normalizeUserListingTaxonomyTermIds(Request $request): array
    {
        $termIds = [];

        $taxonomyTermIds = $request->input(
            'taxonomy_term_ids',
            []
        );

        if (is_string($taxonomyTermIds)) {
            $decoded = json_decode(
                $taxonomyTermIds,
                true
            );

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
            ) {
                $taxonomyTermIds = $decoded;
            } else {
                $taxonomyTermIds = str_contains(
                    $taxonomyTermIds,
                    ','
                )
                    ? explode(',', $taxonomyTermIds)
                    : [$taxonomyTermIds];
            }
        }

        if (is_array($taxonomyTermIds)) {
            foreach (
                collect($taxonomyTermIds)->flatten()
                as $id
            ) {
                if (is_numeric($id)) {
                    $termIds[] = (int) $id;
                }
            }
        } elseif (is_numeric($taxonomyTermIds)) {
            $termIds[] = (int) $taxonomyTermIds;
        }

        $taxonomies = $request->input(
            'taxonomies',
            []
        );

        /*
         * Multipart clients may send taxonomies as JSON.
         */
        if (is_string($taxonomies)) {
            $decoded = json_decode(
                $taxonomies,
                true
            );

            $taxonomies = (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
            )
                ? $decoded
                : [];
        }

        if (is_array($taxonomies)) {
            foreach ($taxonomies as $taxonomyData) {
                if (is_numeric($taxonomyData)) {
                    $termIds[] = (int) $taxonomyData;
                    continue;
                }

                if (!is_array($taxonomyData)) {
                    continue;
                }

                if (
                    !empty(
                        $taxonomyData[
                            'taxonomy_term_id'
                        ]
                    )
                    && is_numeric(
                        $taxonomyData[
                            'taxonomy_term_id'
                        ]
                    )
                ) {
                    $termIds[] = (int) $taxonomyData[
                        'taxonomy_term_id'
                    ];
                }

                $multipleIds =
                    $taxonomyData[
                        'taxonomy_term_ids'
                    ] ?? [];

                if (is_string($multipleIds)) {
                    $decoded = json_decode(
                        $multipleIds,
                        true
                    );

                    if (
                        json_last_error()
                            === JSON_ERROR_NONE
                        && is_array($decoded)
                    ) {
                        $multipleIds = $decoded;
                    } else {
                        $multipleIds = str_contains(
                            $multipleIds,
                            ','
                        )
                            ? explode(',', $multipleIds)
                            : [$multipleIds];
                    }
                }

                if (is_array($multipleIds)) {
                    foreach (
                        collect($multipleIds)->flatten()
                        as $id
                    ) {
                        if (is_numeric($id)) {
                            $termIds[] = (int) $id;
                        }
                    }
                }
            }
        }

        return collect($termIds)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function syncUserListingTaxonomyTerms(DynamicPost $listing, array $taxonomyTermIds): void
    {
        if (!method_exists($listing, 'taxonomyTerms')) {
            return;
        }

        $taxonomyTermIds = collect($taxonomyTermIds)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        if (empty($taxonomyTermIds)) {
            $listing->taxonomyTerms()->sync([]);
            return;
        }

        if (!Schema::hasTable('taxonomy_terms')) {
            return;
        }

        $terms = DB::table('taxonomy_terms')
            ->whereIn('id', $taxonomyTermIds)
            ->select('id', 'taxonomy_id')
            ->get();

        $syncData = [];

        foreach ($terms as $term) {
            $syncData[(int) $term->id] = [
                'taxonomy_id' => (int) $term->taxonomy_id,
            ];
        }

        $listing->taxonomyTerms()->sync($syncData);
    }

    private function storeCustomFieldsForListing(
        DynamicPost $listing,
        array $customFields,
        Request $request,
        PostType $postType
    ): void {
        if (empty($customFields)) {
            return;
        }

        $table = $this->dynamicPostMetaTable();

        if (!$table) {
            return;
        }

        foreach ($customFields as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $customFieldId = $item['custom_field_id'] ?? null;

            if (!$customFieldId) {
                continue;
            }

            $customField = CustomField::query()->find((int) $customFieldId);

            if (!$customField) {
                continue;
            }

            /*
         * Handle custom field media/file upload.
         * Supported frontend keys:
         * custom_fields[0][file]
         * custom_fields[0][files][]
         */
            $uploadedMedia = [];

            $singleFileKey = "custom_fields.{$index}.file";
            $multiFileKey = "custom_fields.{$index}.files";

            if ($request->hasFile($singleFileKey)) {
                $file = $request->file($singleFileKey);

                if ($file instanceof UploadedFile) {
                    $media = $this->storeListingMediaFile(
                        file: $file,
                        user: $this->resolveCurrentUser($request),
                        postType: $postType,
                        fieldSlug: $customField->field_name_slug ?: ('custom-field-' . $customField->id)
                    );

                    if ($media) {
                        $uploadedMedia[] = $this->mediaFilePayload($media);
                    }
                }
            }

            if ($request->hasFile($multiFileKey)) {
                $files = $request->file($multiFileKey);

                foreach (collect($files)->flatten() as $file) {
                    if (!$file instanceof UploadedFile) {
                        continue;
                    }

                    $media = $this->storeListingMediaFile(
                        file: $file,
                        user: $this->resolveCurrentUser($request),
                        postType: $postType,
                        fieldSlug: $customField->field_name_slug ?: ('custom-field-' . $customField->id)
                    );

                    if ($media) {
                        $uploadedMedia[] = $this->mediaFilePayload($media);
                    }
                }
            }

            /*
         * Existing media preserve.
         */
            if (
                empty($uploadedMedia)
                && isset($item['value_json'])
                && !empty($item['value_json'])
            ) {
                $existingValue = is_array($item['value_json'])
                    ? $item['value_json']
                    : json_decode((string) $item['value_json'], true);

                if (is_array($existingValue)) {
                    $uploadedMedia = $existingValue['media'] ?? $existingValue;
                }
            }

            if (!empty($uploadedMedia)) {
                $item['value_json'] = [
                    'media' => array_values($uploadedMedia),
                ];

                $item['value_string'] = $uploadedMedia[0]['url'] ?? null;
                $item['value_text'] = $uploadedMedia[0]['url'] ?? null;
            }

            $payload = [];

            $this->putMetaColumnIfExists($table, $payload, 'entity_type', 'post');
            $this->putMetaColumnIfExists($table, $payload, 'entity_id', (int) $listing->id);
            $this->putMetaColumnIfExists($table, $payload, 'post_id', (int) $listing->id);
            $this->putMetaColumnIfExists($table, $payload, 'dynamic_post_id', (int) $listing->id);
            $this->putMetaColumnIfExists($table, $payload, 'custom_field_id', (int) $customFieldId);

            foreach (
                [
                    'value_string',
                    'value_text',
                    'value_number',
                    'value_date',
                    'value_datetime',
                    'value_json',
                ] as $column
            ) {
                if (array_key_exists($column, $item)) {
                    $value = $item[$column];

                    if ($column === 'value_json' && is_array($value)) {
                        $value = json_encode($value);
                    }

                    $this->putMetaColumnIfExists($table, $payload, $column, $value);
                }
            }

            if (Schema::hasColumn($table, 'field_meta_value')) {
                $fieldValue = $item['value_string']
                    ?? $item['value_text']
                    ?? $item['value_number']
                    ?? $item['value_date']
                    ?? $item['value_datetime']
                    ?? null;

                if (!$fieldValue && isset($item['value_json'])) {
                    $fieldValue = is_array($item['value_json'])
                        ? json_encode($item['value_json'])
                        : $item['value_json'];
                }

                $payload['field_meta_value'] = $fieldValue;
            }

            if (Schema::hasColumn($table, 'updated_at')) {
                $payload['updated_at'] = now();
            }

            if (Schema::hasColumn($table, 'created_at')) {
                $payload['created_at'] = now();
            }

            $match = [];

            if (Schema::hasColumn($table, 'entity_id')) {
                $match['entity_id'] = (int) $listing->id;
            } elseif (Schema::hasColumn($table, 'post_id')) {
                $match['post_id'] = (int) $listing->id;
            } elseif (Schema::hasColumn($table, 'dynamic_post_id')) {
                $match['dynamic_post_id'] = (int) $listing->id;
            }

            if (Schema::hasColumn($table, 'entity_type')) {
                $match['entity_type'] = 'post';
            }

            if (Schema::hasColumn($table, 'custom_field_id')) {
                $match['custom_field_id'] = (int) $customFieldId;
            }

            if (!empty($match)) {
                DB::table($table)->updateOrInsert($match, $payload);
            } else {
                DB::table($table)->insert($payload);
            }
        }
    }
    private function customFieldsFromRequest(Request $request): array
    {
        $customFields = $request->input('custom_fields', []);

        if (is_string($customFields)) {
            $decoded = json_decode($customFields, true);
            $customFields = is_array($decoded) ? $decoded : [];
        }

        return is_array($customFields) ? $customFields : [];
    }

    private function mediaFilePayload(MediaFile $media): array
    {
        $path = $media->path ?? null;
        $url = $media->url ?? null;

        if (!$url && $path) {
            $url = $this->storagePublicUrl($path);
        }

        return [
            'id' => (int) $media->id,
            'disk' => $media->disk ?? 'public',
            'path' => $path,
            'url' => $url,
            'file_name' => $media->file_name ?? null,
            'original_name' => $media->original_name ?? null,
            'mime_type' => $media->mime_type ?? null,
            'extension' => $media->extension ?? null,
            'size' => $media->size ?? null,
        ];
    }
    private function dynamicPostMetaTable(): ?string
    {
        foreach (
            [
                'custom_field_values',
                'dynamic_post_meta',
                'dynamic_post_metas',
                'post_meta',
                'post_metas',
                'custom_field_meta',
            ] as $table
        ) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function putMetaColumnIfExists(string $table, array &$payload, string $column, mixed $value): void
    {
        if (Schema::hasColumn($table, $column)) {
            $payload[$column] = $value;
        }
    }
    private function normalizeListingId(int|string $listing): ?int
    {
        $listing = trim((string) $listing);

        if ($listing === '' || !ctype_digit($listing)) {
            return null;
        }

        $listingId = (int) $listing;

        return $listingId > 0 ? $listingId : null;
    }
    private function consumeMembershipListingCredit(Request $request, object|int $listing, string $referenceType): void
    {
        if (!$request->attributes->get('membership_should_consume_listing_credit')) {
            return;
        }

        $subjectUser = $request->attributes->get('membership_subject_user');

        if (!$subjectUser instanceof User) {
            return;
        }

        $listingId = is_object($listing) ? (int) ($listing->id ?? 0) : (int) $listing;

        if ($listingId <= 0) {
            return;
        }

        app(MembershipCreditService::class)->consumeListingCreditOnce(
            user: $subjectUser,
            referenceType: $referenceType,
            referenceId: $listingId,
            performedBy: $subjectUser,
            metadata: [
                'source' => 'user_listing_controller',
            ]
        );
    }

    private function propertyWorkflow(): PropertyWorkflowService
    {
        return app(PropertyWorkflowService::class);
    }
}