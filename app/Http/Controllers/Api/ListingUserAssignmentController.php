<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListingUserAssignmentController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type' => ['nullable', 'string'],
                'post_type_slug' => ['nullable', 'string'],
                'role_id' => ['nullable', 'integer'],
                'role_ids' => ['nullable', 'array'],
                'role_ids.*' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:255'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            $limit = min((int) $request->get('limit', 100), 500);

            $postType = $this->resolvePostType($request);

            $listings = DynamicPost::query()
                ->select($this->dynamicPostColumns())
                ->when($postType, fn ($q) => $q->where('post_type_id', $postType->id))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $this->applyListingSearch($q, $request->search);
                })
                ->orderBy(Schema::hasColumn('dynamic_posts', 'title') ? 'title' : 'id')
                ->limit($limit)
                ->get()
                ->map(fn ($post) => $this->formatListing($post))
                ->values();

            $roleIds = $this->normalizeRoleIds($request);

            $users = $this->getUsersByRoles(
                roleIds: $roleIds,
                search: $request->search,
                limit: $limit
            );

            $anonymousUser = $this->getAnonymousUser();

            if ($users->isEmpty() && $anonymousUser) {
                $users = collect([
                    $this->formatUser($anonymousUser),
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Listing user assignment options fetched successfully.',
                'data' => [
                    'post_type' => $postType ? [
                        'id' => (int) $postType->id,
                        'name' => $postType->name,
                        'slug' => $postType->slug,
                    ] : null,
                    'listings' => $listings,
                    'roles' => $this->getRoleOptions(),
                    'users' => $users,
                    'anonymous_user' => $anonymousUser ? $this->formatUser($anonymousUser) : null,
                ],
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
                'message' => 'Unable to fetch assignment options.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function save(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'dynamic_post_id' => ['required', 'integer', 'exists:dynamic_posts,id'],

                // Admin selected users
                'user_ids' => ['nullable', 'array'],
                'user_ids.*' => ['nullable', 'integer', 'exists:users,id'],

                // Admin selected roles. Users under these roles will be assigned.
                'role_ids' => ['nullable', 'array'],
                'role_ids.*' => ['nullable', 'integer'],

                // Optional direct anonymous flag
                'anonymous' => ['nullable', 'boolean'],
            ]);

            $dynamicPostId = (int) $validated['dynamic_post_id'];

            $userIds = collect($validated['user_ids'] ?? [])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $roleIds = collect($validated['role_ids'] ?? [])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($roleIds->isNotEmpty()) {
                $this->assertRolesExist($roleIds->toArray());

                $roleUserIds = User::query()
                    ->whereIn('role_id', $roleIds->toArray())
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id);

                $userIds = $userIds
                    ->merge($roleUserIds)
                    ->unique()
                    ->values();
            }

            $anonymousUser = $this->getAnonymousUser();

            if (!empty($validated['anonymous']) && $anonymousUser) {
                $userIds->push((int) $anonymousUser->id);
            }

            // If no user found/selected, assign Anonymous User from users table
            if ($userIds->isEmpty() && $anonymousUser) {
                $userIds->push((int) $anonymousUser->id);
            }

            if ($userIds->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No user found and anonymous user does not exist.',
                ], 422);
            }

            $now = now();

            $rows = $userIds
                ->unique()
                ->map(fn ($userId) => [
                    'dynamic_post_id' => $dynamicPostId,
                    'user_id' => (int) $userId,
                    'assigned_by' => Auth::id(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->toArray();

            DB::transaction(function () use ($dynamicPostId, $rows) {
                DB::table('dynamic_post_user')
                    ->where('dynamic_post_id', $dynamicPostId)
                    ->delete();

                DB::table('dynamic_post_user')->insert($rows);
            });

            return response()->json([
                'status' => true,
                'message' => 'Listing assigned to users successfully.',
                'data' => $this->assignmentData($dynamicPostId),
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
                'message' => 'Unable to save listing user assignment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int|string $dynamicPost): JsonResponse
    {
        $post = DynamicPost::find($dynamicPost);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Listing not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Listing assigned users fetched successfully.',
            'data' => $this->assignmentData((int) $post->id),
        ]);
    }

    public function userListings(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type' => ['nullable', 'string'],
                'post_type_slug' => ['nullable', 'string'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $userId = $request->filled('user_id')
                ? (int) $request->user_id
                : null;

            if (!$userId) {
                $anonymousUser = $this->getAnonymousUser();
                $userId = $anonymousUser ? (int) $anonymousUser->id : null;
            }

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Anonymous user not found.',
                ], 404);
            }

            $postType = $this->resolvePostType($request);
            $perPage = min((int) $request->get('per_page', 15), 100);

            $posts = DynamicPost::query()
                ->select($this->dynamicPostColumns())
                ->join('dynamic_post_user', 'dynamic_posts.id', '=', 'dynamic_post_user.dynamic_post_id')
                ->where('dynamic_post_user.user_id', $userId)
                ->when($postType, fn ($q) => $q->where('dynamic_posts.post_type_id', $postType->id))
                ->orderByDesc('dynamic_posts.id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'User listings fetched successfully.',
                'data' => $posts,
                'viewer' => [
                    'user_id' => $userId,
                ],
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
                'message' => 'Unable to fetch user listings.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function assignmentData(int $dynamicPostId): array
    {
        $post = DynamicPost::select($this->dynamicPostColumns())->find($dynamicPostId);

        $users = User::query()
            ->select($this->userColumns())
            ->join('dynamic_post_user', 'users.id', '=', 'dynamic_post_user.user_id')
            ->where('dynamic_post_user.dynamic_post_id', $dynamicPostId)
            ->orderBy('users.id')
            ->get()
            ->map(fn ($user) => $this->formatUser($user))
            ->values();

        return [
            'listing' => $post ? $this->formatListing($post) : null,
            'selected_user_ids' => $users->pluck('id')->values(),
            'users' => $users,
        ];
    }

    private function getAnonymousUser(): ?User
    {
        return User::query()
            ->where('email', 'anonymous@system.local')
            ->orWhere('name', 'Anonymous User')
            ->orWhere('first_name', 'Anonymous')
            ->first();
    }

    private function getUsersByRoles(array $roleIds = [], ?string $search = null, int $limit = 100)
    {
        $query = User::query()->select($this->userColumns());

        if (!empty($roleIds) && Schema::hasColumn('users', 'role_id')) {
            $query->whereIn('role_id', $roleIds);
        }

        if (Schema::hasColumn('users', 'role_id')) {
            $query->where(function ($q) {
                $q->whereNull('role_id')
                    ->orWhere('role_id', '!=', 1);
            });
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                foreach (['first_name', 'last_name', 'name', 'email'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $q->orWhere($column, 'like', "%{$search}%");
                    }
                }
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn ($user) => $this->formatUser($user))
            ->values();
    }

    private function getRoleOptions()
    {
        if (!Schema::hasTable('roles')) {
            return collect();
        }

        $columns = ['id'];

        foreach (['name', 'role_name', 'slug'] as $column) {
            if (Schema::hasColumn('roles', $column)) {
                $columns[] = $column;
            }
        }

        return DB::table('roles')
            ->select($columns)
            ->where('id', '!=', 1)
            ->orderBy('id')
            ->get()
            ->map(function ($role) {
                $label = $role->name
                    ?? $role->role_name
                    ?? $role->slug
                    ?? ('Role #' . $role->id);

                return [
                    'id' => (int) $role->id,
                    'value' => (int) $role->id,
                    'label' => $label,
                ];
            })
            ->values();
    }

    private function assertRolesExist(array $roleIds): void
    {
        if (empty($roleIds) || !Schema::hasTable('roles')) {
            return;
        }

        $existing = DB::table('roles')
            ->whereIn('id', $roleIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $missing = array_values(array_diff($roleIds, $existing));

        if (!empty($missing)) {
            throw ValidationException::withMessages([
                'role_ids' => [
                    'Invalid role ids: ' . implode(', ', $missing),
                ],
            ]);
        }
    }

    private function resolvePostType(Request $request): ?PostType
    {
        if (
            !$request->filled('post_type_id')
            && !$request->filled('post_type')
            && !$request->filled('post_type_slug')
        ) {
            return null;
        }

        return PostType::query()
            ->where(function ($q) use ($request) {
                if ($request->filled('post_type_id')) {
                    $q->where('id', (int) $request->post_type_id);
                }

                if ($request->filled('post_type')) {
                    $q->orWhere('slug', $request->post_type)
                        ->orWhere('name', $request->post_type);
                }

                if ($request->filled('post_type_slug')) {
                    $q->orWhere('slug', $request->post_type_slug);
                }
            })
            ->first();
    }

    private function normalizeRoleIds(Request $request): array
    {
        $roleIds = [];

        if ($request->filled('role_id')) {
            $roleIds[] = (int) $request->role_id;
        }

        if ($request->filled('role_ids') && is_array($request->role_ids)) {
            foreach ($request->role_ids as $roleId) {
                if ($roleId) {
                    $roleIds[] = (int) $roleId;
                }
            }
        }

        return collect($roleIds)->unique()->values()->toArray();
    }

    private function applyListingSearch($query, string $search): void
    {
        $query->where(function ($sub) use ($search) {
            if (Schema::hasColumn('dynamic_posts', 'title')) {
                $sub->where('title', 'like', "%{$search}%");
            }

            if (Schema::hasColumn('dynamic_posts', 'slug')) {
                $sub->orWhere('slug', 'like', "%{$search}%");
            }

            if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                $sub->orWhere('listing_code', 'like', "%{$search}%");
            }
        });
    }

    private function dynamicPostColumns(): array
    {
        $columns = ['id', 'post_type_id'];

        foreach (['title', 'slug', 'listing_code', 'status', 'live_status'] as $column) {
            if (Schema::hasColumn('dynamic_posts', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function userColumns(): array
    {
        $columns = ['users.id'];

        foreach (['first_name', 'last_name', 'name', 'email', 'role_id'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = 'users.' . $column;
            }
        }

        return $columns;
    }

    private function formatListing($post): array
    {
        $label = $post->title
            ?? $post->slug
            ?? ('Listing #' . $post->id);

        if (!empty($post->listing_code)) {
            $label = $post->listing_code . ' - ' . $label;
        }

        return [
            'id' => (int) $post->id,
            'value' => (int) $post->id,
            'label' => $label,
            'title' => $post->title ?? null,
            'slug' => $post->slug ?? null,
            'listing_code' => $post->listing_code ?? null,
            'display_id' => $post->listing_code ?? null,
            'post_type_id' => (int) $post->post_type_id,
        ];
    }

    private function formatUser($user): array
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        $label = $fullName
            ?: ($user->name ?? null)
            ?: ($user->email ?? null)
            ?: ('User #' . $user->id);

        return [
            'id' => (int) $user->id,
            'value' => (int) $user->id,
            'label' => $label,
            'email' => $user->email ?? null,
            'role_id' => $user->role_id ?? null,
        ];
    }
}