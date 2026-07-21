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

class UserPropertyListingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'users_property_listings' => [
                    'nullable',
                    'string',
                    Rule::in(['all', 'active', 'inactive', 'draft', 'publish', 'under_review', 'rejected', 'delete_pending']),
                ],
                'users-Property-listings' => [
                    'nullable',
                    'string',
                    Rule::in(['all', 'active', 'inactive', 'draft', 'publish', 'under_review', 'rejected', 'delete_pending']),
                ],
                'search' => ['nullable', 'string', 'max:255'],
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

            $postType = $this->propertyListingPostType();

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing post type not found.',
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

            if ($request->filled('search')) {
                $search = (string) $request->search;

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
                });
            }

            if (Schema::hasColumn('dynamic_posts', 'sort_order')) {
                $query->orderBy('sort_order', 'asc');
            }

            $query->latest();

            $perPage = (int) $request->get('per_page', 10);

            $listings = $query->paginate($perPage);

            $listings->getCollection()->transform(function ($listing) {
                return $this->formatFullDynamicPost($listing);
            });

            return response()->json([
                'status' => true,
                'message' => 'User property listings fetched successfully.',
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

            $postType = $this->propertyListingPostType();

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing post type not found.',
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

            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'title' => ['required', 'string', 'max:255'],
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

            $postType = $this->propertyListingPostType();

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing post type not found.',
                ], 404);
            }

            if ($request->filled('post_type_id') && (int) $request->post_type_id !== (int) $postType->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid post_type_id. Only property-listing post type is allowed from user side.',
                ], 422);
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

                $this->putIfColumnExists($payload, 'post_type_id', (int) $postType->id);
                $this->putIfColumnExists($payload, 'author_id', (int) $user->id);
                $this->putIfColumnExists($payload, 'user_id', (int) $user->id);

                $this->putIfColumnExists($payload, 'title', $request->input('title'));
                $this->putIfColumnExists($payload, 'slug', $this->uniqueDynamicPostSlug(
                    $request->input('slug') ?: $request->input('title'),
                    (int) $postType->id
                ));

                $this->putIfColumnExists($payload, 'status', $request->input('status', 'draft'));

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
                    $payload['listing_code'] = $this->generateListingCode($postType);
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

                $termIds = $this->normalizeUserListingTaxonomyTermIds($request);

                $this->syncUserListingTaxonomyTerms($listing, $termIds);

                $this->storeCustomFieldsForListing($listing, $request->input('custom_fields', []));
            });

            $freshListing = DynamicPost::query()
                ->where('id', $listing->id)
                ->with($this->listingRelations())
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Property listing submitted for admin review.',
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
                'message' => 'Unable to create property listing.',
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
                    'message' => 'Property listing not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Property listing fetched successfully.',
                'data' => $this->formatFullDynamicPost($ownedListing),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch property listing.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, int $listing): JsonResponse
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
                    'message' => 'Admin token is not allowed to update a user property listing.',
                ], 403);
            }

            $ownedListing = $this->findOwnedPropertyListing($listing, $user);

            if (!$ownedListing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing not found.',
                ], 404);
            }

            $request->validate([
                'post_type_id' => ['sometimes', 'nullable', 'integer', 'exists:post_types,id'],
                'title' => ['sometimes', 'required', 'string', 'max:255'],
                'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
                'status' => ['sometimes', 'nullable', 'string', Rule::in([
                    'draft',
                    'published',
                    'private',
                    'archived',
                ])],

                // Kept for frontend payload compatibility only. It is ignored below.
                'live_status' => ['sometimes', 'nullable', 'string'],

                'content' => ['sometimes', 'nullable', 'string'],
                'excerpt' => ['sometimes', 'nullable', 'string'],
                'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
                'state_id' => ['sometimes', 'nullable', 'exists:states,id'],
                'city_id' => ['sometimes', 'nullable', 'exists:cities,id'],
                'area_locality' => ['sometimes', 'nullable', 'string', 'max:255'],

                'personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'business_email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'personal_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                'business_phone' => ['sometimes', 'nullable', 'string', 'max:30'],

                'custom_fields' => ['sometimes', 'nullable', 'array'],
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

                'taxonomy_term_ids' => ['sometimes', 'nullable'],
                'taxonomy_term_ids.*' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
                'taxonomies' => ['sometimes', 'nullable', 'array'],
                'taxonomies.*' => ['nullable'],
                'taxonomies.*.taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'taxonomies.*.taxonomy_term_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
                'taxonomies.*.taxonomy_term_ids' => ['nullable', 'array'],
                'taxonomies.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

                'featured_image' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
                'featured_image_id' => ['sometimes', 'nullable'],
                'featured_image_id.*' => ['nullable'],
                'gallery_images' => ['sometimes', 'nullable'],
                'gallery_images.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
                'gallery_image_ids' => ['sometimes', 'nullable'],
                'gallery_image_ids.*' => ['nullable'],
            ]);

            $postType = $this->propertyListingPostType();

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing post type not found.',
                ], 404);
            }

            if ($request->filled('post_type_id') && (int) $request->post_type_id !== (int) $postType->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid post_type_id. Only property-listing post type is allowed from user side.',
                ], 422);
            }

            $this->validatePersonalBusinessContacts($request, $ownedListing);

            DB::transaction(function () use ($request, $user, $postType, $ownedListing) {
                $payload = [];

                foreach (
                    [
                        'title',
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
                        $this->putIfColumnExists($payload, $column, $request->input($column));
                    }
                }

                if ($request->exists('status')) {
                    $this->putIfColumnExists(
                        $payload,
                        'status',
                        $request->input('status') ?: ($ownedListing->status ?? 'draft')
                    );
                }

                if ($request->exists('slug') || $request->exists('title')) {
                    $slugSource = $request->input('slug')
                        ?: $request->input('title')
                        ?: $ownedListing->title
                        ?: 'listing';

                    $this->putIfColumnExists($payload, 'slug', $this->uniqueDynamicPostSlug(
                        $slugSource,
                        (int) $postType->id,
                        (int) $ownedListing->id
                    ));
                }

                foreach ($payload as $column => $value) {
                    $ownedListing->{$column} = $value;
                }

                $this->applyReviewMetadataToListing($ownedListing, 'update');

                if (Schema::hasColumn('dynamic_posts', 'published_at') && $request->exists('status')) {
                    $ownedListing->published_at = $request->input('status') === 'published'
                        ? ($ownedListing->published_at ?: now())
                        : null;
                }

                $featuredImageFile = $this->featuredImageFile($request);

                if ($featuredImageFile) {
                    $featuredMedia = $this->storeListingMediaFile(
                        file: $featuredImageFile,
                        user: $user,
                        postType: $postType,
                        fieldSlug: 'featured_image'
                    );

                    if ($featuredMedia && Schema::hasColumn('dynamic_posts', 'featured_image_id')) {
                        $ownedListing->featured_image_id = (int) $featuredMedia->id;
                    }
                } elseif ($request->exists('featured_image_id') && Schema::hasColumn('dynamic_posts', 'featured_image_id')) {
                    $ownedListing->featured_image_id = $this->numericInputValue(
                        $request->input('featured_image_id')
                    );
                }

                if ($request->exists('gallery_image_ids') || $request->hasFile('gallery_images')) {
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
                        $ownedListing->gallery_image_ids = json_encode($galleryIds);
                    }
                }

                $ownedListing->save();

                if ($request->exists('taxonomy_term_ids') || $request->exists('taxonomies')) {
                    $termIds = $this->normalizeUserListingTaxonomyTermIds($request);
                    $this->syncUserListingTaxonomyTerms($ownedListing, $termIds);
                }

                if ($request->exists('custom_fields')) {
                    $this->storeCustomFieldsForListing(
                        $ownedListing,
                        $request->input('custom_fields', []) ?: []
                    );
                }
            });

            $freshListing = DynamicPost::query()
                ->where('id', $ownedListing->id)
                ->with($this->listingRelations())
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Listing changes submitted for admin review.',
                'data' => $this->formatFullDynamicPost($freshListing),
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
                'message' => 'Unable to update property listing.',
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
                    'message' => 'Property listing not found.',
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
                'message' => 'Property listing and its images permanently deleted successfully.',
                'data' => [
                    'id' => $listing,
                    'deleted' => true,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete property listing.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function propertyListingPostType(): ?PostType
    {
        return PostType::query()
            ->where('slug', 'property-listing')
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
        match ($filter) {
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

        return [
            'total_listing' => $totalListing,
            'active_listing' => $activeListing,
            'inactive_listing' => $inactiveListing,
            'expired_listing' => $expiredListing,
            'draft_listing' => $draftListing,
            'published_listing' => $publishedListing,
            'under_review_listing' => $underReviewListing,
            'rejected_listing' => $rejectedListing,
        ];
    }

    private function formatFullDynamicPost(?DynamicPost $listing): ?array
    {
        if (!$listing) {
            return null;
        }

        $listing->loadMissing($this->listingRelations());

        $data = $listing->toArray();

        /*
        |--------------------------------------------------------------------------
        | Listing Code Compatibility
        |--------------------------------------------------------------------------
        | listing_code is the real code.
        | display_id and property_listing_id are frontend compatibility keys.
        |--------------------------------------------------------------------------
        */
        $data['listing_code'] = $listing->listing_code ?? null;
        $data['display_id'] = $listing->listing_code ?? null;
        $data['property_listing_id'] = $listing->listing_code ?? null;

        $data['review_status_label'] = $this->reviewStatusLabel($listing->live_status ?? null);
        $data['pending_action'] = $this->pendingReviewAction($listing);
        $data['is_under_review'] = in_array($listing->live_status, ['under_review', 'submit'], true);
        $data['is_active'] = $listing->status === 'published' && $listing->live_status === 'approve';
        $data['is_rejected'] = in_array($listing->live_status, ['reject', 'disapprove'], true);

        $data['post_type'] = [
            'id' => $listing->postType ? (int) $listing->postType->id : null,
            'name' => $listing->postType?->name,
            'slug' => $listing->postType?->slug,
        ];

        $data['location'] = $this->formatLocationForDynamicPost($listing);

        $data['country'] = $data['location']['country_name'];
        $data['state'] = $data['location']['state_name'];
        $data['city'] = $data['location']['city_name'];
        $data['country_name'] = $data['location']['country_name'];
        $data['state_name'] = $data['location']['state_name'];
        $data['city_name'] = $data['location']['city_name'];
        $data['full_address'] = $data['location']['full_address'];

        unset($data['country_id'], $data['state_id'], $data['city_id']);

        $featuredMedia = $this->formatMediaFileById($listing->featured_image_id ?? null);
        $galleryMedia = $this->formatMediaFilesByIds($listing->gallery_image_ids ?? []);

        $data['featured_image'] = $featuredMedia['url'] ?? null;
        $data['featured_image_media'] = $featuredMedia;

        $data['gallery_images'] = collect($galleryMedia)
            ->pluck('url')
            ->filter()
            ->values()
            ->toArray();

        $data['gallery_image_files'] = $galleryMedia;

        $data['selected_taxonomies'] = $this->formatSelectedTaxonomies($listing);

        $data['meta'] = $this->formatMetaForFrontend($data['meta'] ?? []);

        return $data;
    }

    private function formatLocationForDynamicPost(DynamicPost $post): array
    {
        $countryId = $post->country_id ?? null;
        $stateId = $post->state_id ?? null;
        $cityId = $post->city_id ?? null;

        $country = $countryId
            ? Country::query()->select('id', 'name')->find((int) $countryId)
            : null;

        $state = $stateId
            ? State::query()->select('id', 'name', 'country_id')->find((int) $stateId)
            : null;

        $city = $cityId
            ? City::query()->select('id', 'name', 'state_id')->find((int) $cityId)
            : null;

        $fullAddress = collect([
            $post->area_locality ?? null,
            $city?->name,
            $state?->name,
            $country?->name,
        ])
            ->filter()
            ->values()
            ->implode(', ');

        return [
            'country_name' => $country?->name,
            'state_name' => $state?->name,
            'city_name' => $city?->name,
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

    private function reviewStatusLabel(?string $liveStatus): string
    {
        return match ($liveStatus) {
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'disapprove' => 'Disapproved',
            'under_review' => 'Under Review',
            'modify_review' => 'Modification Required',
            'submit' => 'Submitted',
            default => 'Pending',
        };
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
        $postType = $this->propertyListingPostType();

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

        if (Schema::hasColumn('dynamic_posts', 'live_status')) {
            $listing->live_status = 'under_review';
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

    private function uniqueDynamicPostSlug(
        string $title,
        ?int $postTypeId = null,
        ?int $ignoreDynamicPostId = null
    ): string {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'listing';
        }

        $slug = $baseSlug;
        $counter = 1;

        while ($this->dynamicPostSlugExists($slug, $postTypeId, $ignoreDynamicPostId)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

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

        $query = DynamicPost::query()->where('slug', $slug);

        if ($postTypeId && Schema::hasColumn('dynamic_posts', 'post_type_id')) {
            $query->where('post_type_id', $postTypeId);
        }

        if ($ignoreDynamicPostId) {
            $query->where('id', '!=', $ignoreDynamicPostId);
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

        $taxonomyTermIds = $request->input('taxonomy_term_ids', []);

        if (is_string($taxonomyTermIds)) {
            $decoded = json_decode($taxonomyTermIds, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $taxonomyTermIds = $decoded;
            } else {
                $taxonomyTermIds = str_contains($taxonomyTermIds, ',')
                    ? explode(',', $taxonomyTermIds)
                    : [$taxonomyTermIds];
            }
        }

        if (is_array($taxonomyTermIds)) {
            foreach ($taxonomyTermIds as $id) {
                if (is_numeric($id)) {
                    $termIds[] = (int) $id;
                }
            }
        } elseif (is_numeric($taxonomyTermIds)) {
            $termIds[] = (int) $taxonomyTermIds;
        }

        $taxonomies = $request->input('taxonomies', []);

        if (is_array($taxonomies)) {
            foreach ($taxonomies as $taxonomyData) {
                if (is_numeric($taxonomyData)) {
                    $termIds[] = (int) $taxonomyData;
                    continue;
                }

                if (is_array($taxonomyData)) {
                    if (!empty($taxonomyData['taxonomy_term_id']) && is_numeric($taxonomyData['taxonomy_term_id'])) {
                        $termIds[] = (int) $taxonomyData['taxonomy_term_id'];
                    }

                    if (!empty($taxonomyData['taxonomy_term_ids']) && is_array($taxonomyData['taxonomy_term_ids'])) {
                        foreach ($taxonomyData['taxonomy_term_ids'] as $id) {
                            if (is_numeric($id)) {
                                $termIds[] = (int) $id;
                            }
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

    private function storeCustomFieldsForListing(DynamicPost $listing, array $customFields): void
    {
        if (empty($customFields)) {
            return;
        }

        $table = $this->dynamicPostMetaTable();

        if (!$table) {
            return;
        }

        foreach ($customFields as $item) {
            $customFieldId = $item['custom_field_id'] ?? null;

            if (!$customFieldId) {
                continue;
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
                $payload['field_meta_value'] =
                    $item['value_string']
                    ?? $item['value_text']
                    ?? $item['value_number']
                    ?? $item['value_date']
                    ?? $item['value_datetime']
                    ?? (
                        isset($item['value_json'])
                        ? (is_array($item['value_json']) ? json_encode($item['value_json']) : $item['value_json'])
                        : null
                    );
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
}
