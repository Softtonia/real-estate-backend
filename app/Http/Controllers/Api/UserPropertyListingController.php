<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PostType;
use App\Models\State;
use App\Models\City;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class UserPropertyListingController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'users_property_listings' => [
                    'nullable',
                    'string',
                    Rule::in(['all', 'active', 'inactive', 'draft', 'publish']),
                ],
                'users-Property-listings' => [
                    'nullable',
                    'string',
                    Rule::in(['all', 'active', 'inactive', 'draft', 'publish']),
                ],
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

            $postType = PostType::query()
                ->where('slug', 'property-listing')
                ->first();

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
                ->with([
                    'postType',
                    'parent:id,post_type_id,title,slug,status,live_status',
                    'children:id,post_type_id,parent_id,title,slug,status,live_status',
                    'taxonomyTerms.taxonomy',
                    'meta.customField.options',
                    'meta.customField.repeaters.options',
                ])
                ->where('post_type_id', (int) $postType->id)
                ->where('author_id', (int) $user->id);

            $this->applyListingFilter($query, $filter);

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

                'custom_fields' => ['nullable', 'array'],
                'custom_fields.*.custom_field_id' => ['nullable', 'integer'],
                'custom_fields.*.value_string' => ['nullable'],
                'custom_fields.*.value_text' => ['nullable'],
                'custom_fields.*.value_number' => ['nullable'],
                'custom_fields.*.value_date' => ['nullable'],
                'custom_fields.*.value_datetime' => ['nullable'],
                'custom_fields.*.value_json' => ['nullable'],

                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*' => ['nullable', 'integer'],

                'featured_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
                'gallery_images' => ['nullable'],
                'gallery_images.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            ]);

            $postType = PostType::query()
                ->where('slug', 'property-listing')
                ->first();

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

                $this->putIfColumnExists($payload, 'title', $request->input('title'));
                $this->putIfColumnExists($payload, 'slug', $this->uniqueDynamicPostSlug(
                    $request->input('slug') ?: $request->input('title')
                ));

                $this->putIfColumnExists($payload, 'status', $request->input('status', 'draft'));
                $this->putIfColumnExists($payload, 'live_status', $request->input('live_status', 'under_review'));

                $this->putIfColumnExists($payload, 'content', $request->input('content'));
                $this->putIfColumnExists($payload, 'excerpt', $request->input('excerpt'));

                $this->putIfColumnExists($payload, 'country_id', $request->input('country_id'));
                $this->putIfColumnExists($payload, 'state_id', $request->input('state_id'));
                $this->putIfColumnExists($payload, 'city_id', $request->input('city_id'));
                $this->putIfColumnExists($payload, 'area_locality', $request->input('area_locality'));

                if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                    $payload['listing_code'] = $this->generateListingCode();
                }

                $listing = DynamicPost::create($payload);

                /*
            |--------------------------------------------------------------------------
            | Featured Image
            |--------------------------------------------------------------------------
            */
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
                } elseif ($request->filled('featured_image_id') && is_numeric($request->input('featured_image_id'))) {
                    if (Schema::hasColumn('dynamic_posts', 'featured_image_id')) {
                        $listing->featured_image_id = (int) $request->input('featured_image_id');
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | Gallery Images
            |--------------------------------------------------------------------------
            */
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

                if (!empty($galleryIds) && Schema::hasColumn('dynamic_posts', 'gallery_image_ids')) {
                    $listing->gallery_image_ids = json_encode($galleryIds);
                }

                $listing->save();

                /*
            |--------------------------------------------------------------------------
            | Taxonomies
            |--------------------------------------------------------------------------
            */
                $termIds = collect($request->input('taxonomies', []))
                    ->filter(fn($id) => !empty($id) && is_numeric($id))
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->toArray();

                if (!empty($termIds) && method_exists($listing, 'taxonomyTerms')) {
                    $listing->taxonomyTerms()->sync($termIds);
                }

                /*
            |--------------------------------------------------------------------------
            | Custom Fields
            |--------------------------------------------------------------------------
            */
                $this->storeCustomFieldsForListing($listing, $request->input('custom_fields', []));
            });

            $freshListing = DynamicPost::query()
                ->where('id', $listing->id)
                ->with([
                    'postType',
                    'parent:id,post_type_id,title,slug,status,live_status',
                    'children:id,post_type_id,parent_id,title,slug,status,live_status',
                    'taxonomyTerms.taxonomy',
                    'meta.customField.options',
                    'meta.customField.repeaters.options',
                ])
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Property listing created successfully.',
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
    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (!$token && $request->filled('api_token')) {
            $token = $request->api_token;
        }

        if (!$token) {
            return null;
        }

        if (!Schema::hasColumn('users', 'api_token')) {
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
            ->where('live_status', 'under_review')
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

    private function formatFullDynamicPost(DynamicPost $listing): array
    {
        $listing->loadMissing([
            'postType',
            'parent:id,post_type_id,title,slug,status,live_status',
            'children:id,post_type_id,parent_id,title,slug,status,live_status',
            'taxonomyTerms.taxonomy',
            'meta.customField.options',
            'meta.customField.repeaters.options',
        ]);

        $data = $listing->toArray();

        $data['display_id'] = $listing->listing_code ?? null;

        $data['review_status_label'] = $this->reviewStatusLabel($listing->live_status ?? null);
        $data['is_active'] = $listing->status === 'published' && $listing->live_status === 'approve';
        $data['is_rejected'] = in_array($listing->live_status, ['reject', 'disapprove'], true);

        $data['post_type'] = [
            'id' => $listing->postType ? (int) $listing->postType->id : null,
            'name' => $listing->postType?->name,
            'slug' => $listing->postType?->slug,
        ];

        $data['location'] = $this->formatLocationForDynamicPost($listing);

        /*
        |--------------------------------------------------------------------------
        | Location names on top level
        |--------------------------------------------------------------------------
        | Frontend asked for location names, not only ids.
        |--------------------------------------------------------------------------
        */
        $data['country'] = $data['location']['country_name'];
        $data['state'] = $data['location']['state_name'];
        $data['city'] = $data['location']['city_name'];
        $data['country_name'] = $data['location']['country_name'];
        $data['state_name'] = $data['location']['state_name'];
        $data['city_name'] = $data['location']['city_name'];
        $data['full_address'] = $data['location']['full_address'];

        /*
        |--------------------------------------------------------------------------
        | Remove location ids from main response
        |--------------------------------------------------------------------------
        */
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
                        (int) ($item['entity_id'] ?? 0),
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
                    $url = Storage::disk($media['disk'] ?? 'public')->url($path);
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
        return [
            'id' => (int) $media->id,
            'disk' => $media->disk,
            'context' => $media->context,
            'post_type_slug' => $media->post_type_slug,
            'field_slug' => $media->field_slug,
            'directory' => $media->directory,
            'path' => $media->path,
            'url' => $media->url,
            'file_name' => $media->file_name,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'extension' => $media->extension,
            'size' => $media->size,
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
    private function putIfColumnExists(array &$payload, string $column, mixed $value): void
    {
        if (Schema::hasColumn('dynamic_posts', $column)) {
            $payload[$column] = $value;
        }
    }

    private function uniqueDynamicPostSlug(string $title): string
    {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'listing';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Schema::hasColumn('dynamic_posts', 'slug')
            && DynamicPost::query()->where('slug', $slug)->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function generateListingCode(): string
    {
        do {
            $code = 'HP-' . now()->format('ymd') . '-' . strtoupper(Str::random(6));
        } while (
            Schema::hasColumn('dynamic_posts', 'listing_code')
            && DynamicPost::query()->where('listing_code', $code)->exists()
        );

        return $code;
    }

    private function featuredImageFile(Request $request): ?UploadedFile
    {
        if ($request->hasFile('featured_image')) {
            $file = $request->file('featured_image');

            return $file instanceof UploadedFile ? $file : null;
        }

        /*
    |--------------------------------------------------------------------------
    | Support wrong frontend key: featured_image_id[] = binary
    |--------------------------------------------------------------------------
    */
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
                ->filter(fn($id) => is_numeric($id))
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();
        }

        return $ids;
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

        /*
    |--------------------------------------------------------------------------
    | Same Path As DynamicPostController
    |--------------------------------------------------------------------------
    | uploads/dynamic-posts/property-listing/featured-image/2026/07/file.png
    | uploads/dynamic-posts/property-listing/gallery/2026/07/file.png
    |--------------------------------------------------------------------------
    */
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
        $this->putMediaColumnIfExists($payload, 'url', url('storage/' . $path));
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

    private function ensurePublicDirectory(string $directory): void
    {
        $path = public_path($directory);

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
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
                'dynamic_post_meta',
                'dynamic_post_metas',
                'post_meta',
                'post_metas',
                'custom_field_values',
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
