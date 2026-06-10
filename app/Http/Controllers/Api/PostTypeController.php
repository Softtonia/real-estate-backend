<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostTypeController extends Controller
{
    /**
     * List all post types.
     */
    public function index(Request $request)
    {
        try {
            $query = PostType::query()
                ->with('creator')
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;

                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('is_default'), function ($q) use ($request) {
                    $q->where('is_default', filter_var($request->is_default, FILTER_VALIDATE_BOOLEAN));
                })
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc');

            $perPage = (int) $request->get('per_page', 10);
            $perPage = $perPage > 100 ? 100 : $perPage;

            $postTypes = $query->paginate($perPage);

            $postTypes->getCollection()->transform(function ($postType) {
                return $this->formatPostType($postType);
            });

            return response()->json([
                'status' => true,
                'message' => 'Post types fetched successfully.',
                'data' => $postTypes,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post types.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store new post type.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:150',
                ],
                'description' => [
                    'nullable',
                    'string',
                ],
                'is_default' => [
                    'nullable',
                    'boolean',
                ],
                'status' => [
                    'nullable',
                    'boolean',
                ],
                'supports' => [
                    'nullable',
                    'array',
                ],
                'sort_order' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'menu_order' => [
                    'nullable',
                    'integer',
                    'min:6',
                ],
            ], [
                'name.required' => 'Post type name is required.',
                'name.max' => 'Post type name cannot be greater than 150 characters.',
                'supports.array' => 'Supports must be a valid array.',
                'menu_order.min' => 'Menu order 1 to 5 is reserved for system administrator.',
            ]);

            $slug = Str::slug($validated['name']);

            $slugExists = PostType::withTrashed()
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Post type slug already exists.',
                    'errors' => [
                        'slug' => [
                            '' . $slug . ' already exists. Please use a different post type name.',
                        ],
                    ],
                ], 422);
            }

            $postType = PostType::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'is_default' => $validated['is_default'] ?? false,
                'status' => $validated['status'] ?? true,
                'supports' => $validated['supports'] ?? null,
                'created_by' => Auth::id(),

                'sort_order' => array_key_exists('sort_order', $validated) && $validated['sort_order'] !== null
                    ? (int) $validated['sort_order']
                    : $this->getNextSortOrder(),

                'menu_order' => array_key_exists('menu_order', $validated) && $validated['menu_order'] !== null
                    ? (int) $validated['menu_order']
                    : $this->getNextAvailableMenuOrder(),
            ]);

            DB::commit();

            $postType->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Post type created successfully.',
                // 'data' => $this->formatPostType($postType),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to create post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show single post type.
     */
    public function show($id)
    {
        try {
            $postType = PostType::with('creator')->find($id);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Post type fetched successfully.',
                'data' => $this->formatPostType($postType),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update post type.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $postType = PostType::find($id);

            if (!$postType) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            if ($request->has('slug')) {
                $requestedSlug = Str::slug($request->input('slug'));
                $oldSlug = $postType->slug;

                $nameBasedSlug = $request->filled('name')
                    ? Str::slug($request->input('name'))
                    : $oldSlug;

                if ($requestedSlug !== $oldSlug && $requestedSlug !== $nameBasedSlug) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Validation failed.',
                        'errors' => [
                            'slug' => [
                                'Slug cannot be changed after creation.',
                            ],
                        ],
                    ], 422);
                }
            }

            $validated = $request->validate([
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:150',
                ],
                'description' => [
                    'nullable',
                    'string',
                ],
                'is_default' => [
                    'nullable',
                    'boolean',
                ],
                'status' => [
                    'nullable',
                    'boolean',
                ],
                'supports' => [
                    'nullable',
                    'array',
                ],
                'sort_order' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'menu_order' => [
                    'nullable',
                    'integer',
                    'min:6',
                ],
            ], [
                'name.required' => 'Post type name is required.',
                'name.max' => 'Post type name cannot be greater than 150 characters.',
                'supports.array' => 'Supports must be a valid array.',
                'menu_order.min' => 'Menu order 1 to 5 is reserved for system administrator.',
            ]);

            $updateData = [];

            if (array_key_exists('name', $validated)) {
                $updateData['name'] = $validated['name'];
            }

            if (array_key_exists('description', $validated)) {
                $updateData['description'] = $validated['description'];
            }

            if (array_key_exists('is_default', $validated)) {
                $updateData['is_default'] = (bool) $validated['is_default'];
            }

            if (array_key_exists('status', $validated)) {
                $updateData['status'] = (bool) $validated['status'];
            }

            if (array_key_exists('supports', $validated)) {
                $updateData['supports'] = $validated['supports'];
            }

            if (array_key_exists('sort_order', $validated)) {
                $updateData['sort_order'] = $validated['sort_order'] !== null
                    ? (int) $validated['sort_order']
                    : $this->getNextSortOrder();
            }

            if (array_key_exists('menu_order', $validated)) {
                $updateData['menu_order'] = $validated['menu_order'] !== null
                    ? (int) $validated['menu_order']
                    : $this->getNextAvailableMenuOrder();
            }

            $postType->update($updateData);

            DB::commit();

            $postType->refresh()->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Post type updated successfully.',
                // 'data' => $this->formatPostType($postType),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to update post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Soft delete post type.
     */
    public function destroy($id)
    {
        try {
            $postType = PostType::find($id);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            if ($postType->is_default) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default post type cannot be deleted.',
                ], 403);
            }

            $postType->delete();

            return response()->json([
                'status' => true,
                'message' => 'Post type deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trash listing.
     */
    public function trash(Request $request)
    {
        try {
            $query = PostType::onlyTrashed()
                ->with('creator')
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;

                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
                })
                ->orderBy('deleted_at', 'desc');

            $perPage = (int) $request->get('per_page', 10);
            $perPage = $perPage > 100 ? 100 : $perPage;

            $postTypes = $query->paginate($perPage);

            $postTypes->getCollection()->transform(function ($postType) {
                return $this->formatPostType($postType);
            });

            return response()->json([
                'status' => true,
                'message' => 'Trash post types fetched successfully.',
                'data' => $postTypes,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch trash post types.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore soft deleted post type.
     */
    public function restore($id)
    {
        try {
            $postType = PostType::onlyTrashed()->find($id);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found in trash.',
                ], 404);
            }

            $postType->restore();
            $postType->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Post type restored successfully.',
                'data' => $this->formatPostType($postType),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to restore post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Force delete post type.
     */
    public function forceDelete($id)
    {
        try {
            $postType = PostType::onlyTrashed()->find($id);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found in trash.',
                ], 404);
            }

            if ($postType->is_default) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default post type cannot be permanently deleted.',
                ], 403);
            }

            $postType->forceDelete();

            return response()->json([
                'status' => true,
                'message' => 'Post type permanently deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk soft delete.
     */
    public function bulkDelete(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'ids.*' => [
                    'required',
                    'integer',
                    'exists:post_types,id',
                ],
            ]);

            $defaultCount = PostType::whereIn('id', $validated['ids'])
                ->where('is_default', true)
                ->count();

            if ($defaultCount > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default post types cannot be deleted.',
                ], 403);
            }

            PostType::whereIn('id', $validated['ids'])->delete();

            return response()->json([
                'status' => true,
                'message' => 'Selected post types deleted successfully.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete selected post types.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk restore.
     */
    public function bulkRestore(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'ids.*' => [
                    'required',
                    'integer',
                ],
            ]);

            $restored = PostType::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->restore();

            return response()->json([
                'status' => true,
                'message' => 'Selected post types restored successfully.',
                'restored_count' => $restored,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to restore selected post types.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk force delete.
     */
    public function bulkForceDelete(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'ids.*' => [
                    'required',
                    'integer',
                ],
            ]);

            $defaultCount = PostType::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->where('is_default', true)
                ->count();

            if ($defaultCount > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default post types cannot be permanently deleted.',
                ], 403);
            }

            $deleted = PostType::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->forceDelete();

            return response()->json([
                'status' => true,
                'message' => 'Selected post types permanently deleted successfully.',
                'deleted_count' => $deleted,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete selected post types.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get fields/supports for a post type.
     */
    public function fields($id)
    {
        try {
            $postType = PostType::find($id);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Post type fields fetched successfully.',
                'data' => [
                    'id' => $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'supports' => $postType->supports ?? [],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type fields.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto assign next sort order.
     * User can manually change sort_order also.
     */
    private function getNextSortOrder(): int
    {
        $maxSortOrder = PostType::withTrashed()->max('sort_order');

        return $maxSortOrder ? ((int) $maxSortOrder + 1) : 1;
    }

    /**
     * Menu order 1 to 5 is reserved for system/admin default post types.
     * Custom menu order starts from 6.
     * If 6, 7, 10 exist, then it assigns 8 first, then 9, then 11.
     */
    private function getNextAvailableMenuOrder(): int
    {
        $usedOrders = PostType::withTrashed()
            ->whereNotNull('menu_order')
            ->where('menu_order', '>=', 6)
            ->orderBy('menu_order', 'asc')
            ->pluck('menu_order')
            ->map(fn ($order) => (int) $order)
            ->toArray();

        $nextOrder = 6;

        foreach ($usedOrders as $order) {
            if ($order === $nextOrder) {
                $nextOrder++;
            } elseif ($order > $nextOrder) {
                break;
            }
        }

        return $nextOrder;
    }

    /**
     * Format post type response.
     */
    private function formatPostType($postType): array
    {
        $creator = $postType->creator;

        return [
            'id' => $postType->id,
            'name' => $postType->name,
            'slug' => $postType->slug,
            'description' => $postType->description,
            'is_default' => (bool) $postType->is_default,
            'status' => (bool) $postType->status,
            'supports' => $postType->supports ?? [],
            'sort_order' => $postType->sort_order,
            'menu_order' => $postType->menu_order,

            'created_by' => $postType->created_by,
            'created_by_user' => $creator ? [
                'id' => $creator->id,
                'name' => $this->getUserFullName($creator),
                'email' => $creator->email ?? null,
                'role' => $this->getUserRoleName($creator),
            ] : null,

            'created_at' => $postType->created_at,
            'updated_at' => $postType->updated_at,
            'deleted_at' => $postType->deleted_at ?? null,
        ];
    }

    /**
     * User full name helper.
     * Your users table has first_name and last_name, not name.
     */
    private function getUserFullName($user): ?string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        if (!empty($fullName)) {
            return $fullName;
        }

        return $user->name ?? $user->email ?? null;
    }

    /**
     * User role helper.
     * Returns only role name, not full JSON/object.
     */
    private function getUserRoleName($user): ?string
    {
        if (method_exists($user, 'roles')) {
            try {
                $roleName = $user->roles()->pluck('name')->first();

                if (!empty($roleName)) {
                    return $roleName;
                }
            } catch (\Exception $e) {
                // Continue fallback
            }
        }

        if (isset($user->role) && is_object($user->role)) {
            return $user->role->name ?? null;
        }

        if (isset($user->role) && is_array($user->role)) {
            return $user->role['name'] ?? null;
        }

        if (isset($user->role) && is_string($user->role)) {
            $decodedRole = json_decode($user->role, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedRole)) {
                return $decodedRole['name'] ?? null;
            }

            return $user->role;
        }

        if (isset($user->role_slug) && is_string($user->role_slug)) {
            return $user->role_slug;
        }

        if (isset($user->role_id)) {
            try {
                $role = DB::table('roles')
                    ->where('id', $user->role_id)
                    ->first();

                return $role->name ?? null;
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}