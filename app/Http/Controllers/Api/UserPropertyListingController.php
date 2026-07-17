<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

            $query = DynamicPost::query()
                ->with([
                    'postType',
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
                return $this->formatListing($listing);
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
        /*
        |--------------------------------------------------------------------------
        | Very important
        |--------------------------------------------------------------------------
        | For this API, always resolve user from Bearer token first.
        | Do not trust $request->user() first because middleware can resolve admin.
        |--------------------------------------------------------------------------
        */

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

    private function formatListing(DynamicPost $listing): array
    {
        $data = [
            'id' => (int) $listing->id,
            'post_type_id' => (int) $listing->post_type_id,

            'post_type' => [
                'id' => $listing->postType ? (int) $listing->postType->id : null,
                'name' => $listing->postType?->name,
                'slug' => $listing->postType?->slug,
            ],

            'author_id' => $listing->author_id ? (int) $listing->author_id : null,

            'listing_code' => $listing->listing_code ?? null,
            'display_id' => $listing->listing_code ?? null,

            'title' => $listing->title ?? null,
            'slug' => $listing->slug ?? null,
            'excerpt' => $listing->excerpt ?? null,
            'content' => $listing->content ?? null,

            'status' => $listing->status ?? null,
            'live_status' => $listing->live_status ?? null,
            'review_status_label' => $this->reviewStatusLabel($listing->live_status ?? null),

            'is_active' => $listing->status === 'published' && $listing->live_status === 'approve',
            'is_rejected' => in_array($listing->live_status, ['reject', 'disapprove'], true),

            'country_id' => $listing->country_id ? (int) $listing->country_id : null,
            'state_id' => $listing->state_id ? (int) $listing->state_id : null,
            'city_id' => $listing->city_id ? (int) $listing->city_id : null,
            'area_locality' => $listing->area_locality ?? null,

            'created_at' => $listing->created_at,
            'updated_at' => $listing->updated_at,
        ];

        if (Schema::hasColumn('dynamic_posts', 'rejection_reason')) {
            $data['rejection_reason'] = $listing->rejection_reason ?? null;
        }

        if (Schema::hasColumn('dynamic_posts', 'rejected_at')) {
            $data['rejected_at'] = $listing->rejected_at ?? null;
        }

        if (Schema::hasColumn('dynamic_posts', 'rejected_by')) {
            $data['rejected_by'] = $listing->rejected_by ? (int) $listing->rejected_by : null;
        }

        return $data;
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
                    'message' => 'author_id column is required in dynamic_posts table to fetch user listing analytics.',
                ], 500);
            }

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

            $totalListings = (clone $baseQuery)->count();

            $activeListingsQuery = (clone $baseQuery)
                ->where('status', 'published')
                ->where('live_status', 'approve');

            $this->excludeExpiredListings($activeListingsQuery);

            $activeListings = $activeListingsQuery->count();

            $inactiveListings = (clone $baseQuery)
                ->where(function ($query) {
                    $query->whereIn('live_status', ['reject', 'disapprove'])
                        ->orWhereIn('status', ['private', 'archived']);
                })
                ->count();

            $expiredListingsQuery = clone $baseQuery;
            $hasExpiryColumn = $this->applyExpiredListingFilter($expiredListingsQuery);

            $expiredListings = $hasExpiryColumn
                ? $expiredListingsQuery->count()
                : 0;

            $draftListings = (clone $baseQuery)
                ->where('status', 'draft')
                ->count();

            $publishedListings = (clone $baseQuery)
                ->where('status', 'published')
                ->count();

            $underReviewListings = (clone $baseQuery)
                ->where('live_status', 'under_review')
                ->count();

            $rejectedListings = (clone $baseQuery)
                ->whereIn('live_status', ['reject', 'disapprove'])
                ->count();

            return response()->json([
                'status' => true,
                'message' => 'User listing analytics fetched successfully.',
                'current_user' => [
                    'id' => (int) $user->id,
                    'name' => $this->userDisplayName($user),
                    'email' => $user->email ?? null,
                ],
                'data' => [
                    'total_listing' => $totalListings,
                    'active_listing' => $activeListings,
                    'inactive_listing' => $inactiveListings,
                    'expired_listing' => $expiredListings,

                    'draft_listing' => $draftListings,
                    'published_listing' => $publishedListings,
                    'under_review_listing' => $underReviewListings,
                    'rejected_listing' => $rejectedListings,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user listing analytics.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
}
