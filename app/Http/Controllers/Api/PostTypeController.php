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
     * List all active post types.
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
        try {
            $request->validate([
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
            ], [
                'name.required' => 'Post type name is required.',
                'name.max' => 'Post type name cannot be greater than 150 characters.',
                'supports.array' => 'Supports must be a valid array.',
            ]);

            $slug = Str::slug($request->name);

            $slugExists = PostType::withTrashed()
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type slug already exists.',
                    'errors' => [
                        'slug' => [
                            'The generated slug "' . $slug . '" already exists. Please use a different post type name.',
                        ],
                    ],
                ], 422);
            }

            DB::beginTransaction();

            $postType = PostType::create([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'is_default' => $request->boolean('is_default', false),
                'status' => $request->boolean('status', true),
                'supports' => $request->supports,
                'created_by' => Auth::id(),
                'sort_order' => $request->filled('sort_order')
                    ? (int) $request->sort_order
                    : $this->getNextSortOrderAfterDefault(),
            ]);

            DB::commit();

            $postType->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Post type created successfully.',
                'data' => $this->formatPostType($postType),
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

    public function update(Request $request, $id)
    {
        try {
            $postType = PostType::find($id);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            if ($request->has('slug')) {
                $requestedSlug = Str::slug($request->slug);
                $oldSlug = $postType->slug;
                $nameBasedSlug = $request->filled('name')
                    ? Str::slug($request->name)
                    : $oldSlug;

                if ($requestedSlug !== $oldSlug && $requestedSlug !== $nameBasedSlug) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Validation failed.',
                        'errors' => [
                            'slug' => [
                                'Slug cannot be changed after creation.'
                            ]
                        ],
                    ], 422);
                }
            }

            $request->validate([
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
            ], [
                'name.required' => 'Post type name is required.',
                'name.max' => 'Post type name cannot be greater than 150 characters.',
                'supports.array' => 'Supports must be a valid array.',
            ]);

            DB::beginTransaction();

            $updateData = [];

            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }

            if ($request->has('description')) {
                $updateData['description'] = $request->description;
            }

            if ($request->has('is_default')) {
                $updateData['is_default'] = $request->boolean('is_default');
            }

            if ($request->has('status')) {
                $updateData['status'] = $request->boolean('status');
            }

            if ($request->has('supports')) {
                $updateData['supports'] = $request->supports;
            }

            if ($request->has('sort_order')) {
                $updateData['sort_order'] = (int) $request->sort_order;
            }

            $postType->update($updateData);

            DB::commit();

            $postType->refresh()->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Post type updated successfully.',
                'data' => $this->formatPostType($postType),
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
            $request->validate([
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

            $defaultCount = PostType::whereIn('id', $request->ids)
                ->where('is_default', true)
                ->count();

            if ($defaultCount > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default post types cannot be deleted.',
                ], 403);
            }

            PostType::whereIn('id', $request->ids)->delete();

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
            $request->validate([
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
                ->whereIn('id', $request->ids)
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
            $request->validate([
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
                ->whereIn('id', $request->ids)
                ->where('is_default', true)
                ->count();

            if ($defaultCount > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default post types cannot be permanently deleted.',
                ], 403);
            }

            $deleted = PostType::onlyTrashed()
                ->whereIn('id', $request->ids)
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
     * Custom post types should start after default 5 records.
     * If default records are 1 to 5, next custom sort order will start from 6.
     */
    private function getNextSortOrderAfterDefault(): int
    {
        $maxSortOrder = PostType::withTrashed()->max('sort_order');

        if (!$maxSortOrder || $maxSortOrder < 5) {
            return 6;
        }

        return $maxSortOrder + 1;
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
