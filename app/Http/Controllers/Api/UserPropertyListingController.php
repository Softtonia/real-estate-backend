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
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->toArray(),
                    'selected_terms' => $taxonomyTerms->map(fn ($term) => [
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
            ->filter(fn ($media) => is_array($media))
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
            ->filter(fn ($media) => !empty($media['url']))
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
            ->map(fn ($id) => $mediaFiles->get((int) $id))
            ->filter()
            ->map(fn ($media) => $this->formatMediaFile($media))
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
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
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
        foreach ([
            'expired_at',
            'expires_at',
            'expiry_date',
            'valid_till',
            'valid_until',
        ] as $column) {
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
}