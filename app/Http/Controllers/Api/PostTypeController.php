<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PostTypeController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = PostType::query()
                ->with(['creator', 'taxonomies'])
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
                ->ordered();

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

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'slug' => [
                    'nullable',
                    'string',
                    'max:150',
                    'regex:/^[a-z0-9_-]+$/',
                ],
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'supports' => ['nullable', 'array'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'menu_order' => ['nullable', 'integer', 'min:6'],
                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*' => ['integer', 'exists:taxonomies,id'],
            ], [
                'name.required' => 'Post type name is required.',
                'name.max' => 'Post type name cannot be greater than 150 characters.',
                'slug.regex' => 'Slug may only contain lowercase letters, numbers, underscores and dashes.',
                'supports.array' => 'Supports must be a valid array.',
                'menu_order.min' => 'Menu order 1 to 5 is reserved for system administrator.',
                'taxonomies.array' => 'Taxonomies must be a valid array.',
                'taxonomies.*.exists' => 'Selected taxonomy does not exist.',
            ]);

            $slugSource = !empty($validated['slug'])
                ? $validated['slug']
                : $validated['name'];

            $slug = PostType::generateUniqueSlug($slugSource);

            $menuOrder = array_key_exists('menu_order', $validated) && $validated['menu_order'] !== null
                ? (int) $validated['menu_order']
                : $this->getNextAvailableMenuOrder();

            if (PostType::menuOrderExists($menuOrder)) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Menu order already exists.',
                    'errors' => [
                        'menu_order' => [
                            'Menu order ' . $menuOrder . ' is already assigned to another post type.',
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
                'supports' => $validated['supports'] ?? [
                    'title',
                    'excerpt',
                    'featured_image',
                    'editor',
                ],
                'created_by' => Auth::id(),
                'sort_order' => array_key_exists('sort_order', $validated) && $validated['sort_order'] !== null
                    ? (int) $validated['sort_order']
                    : $this->getNextSortOrder(),
                'menu_order' => $menuOrder,
            ]);

            if (!empty($validated['taxonomies'])) {
                $this->syncTaxonomies($postType, $validated['taxonomies']);
            }

            DB::commit();

            $postType->load(['creator', 'taxonomies']);

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

    public function show($id)
    {
        try {
            $postType = PostType::with(['creator', 'taxonomies'])->find($id);

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

            $validated = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'slug' => [
                    'nullable',
                    'string',
                    'max:150',
                    'regex:/^[a-z0-9_-]+$/',
                ],
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'supports' => ['nullable', 'array'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'menu_order' => ['nullable', 'integer', 'min:6'],
                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*' => ['integer', 'exists:taxonomies,id'],
            ], [
                'name.required' => 'Post type name is required.',
                'name.max' => 'Post type name cannot be greater than 150 characters.',
                'slug.regex' => 'Slug may only contain lowercase letters, numbers, underscores and dashes.',
                'supports.array' => 'Supports must be a valid array.',
                'menu_order.min' => 'Menu order 1 to 5 is reserved for system administrator.',
                'taxonomies.array' => 'Taxonomies must be a valid array.',
                'taxonomies.*.exists' => 'Selected taxonomy does not exist.',
            ]);

            $updateData = [];

            if (array_key_exists('name', $validated)) {
                $updateData['name'] = $validated['name'];

                if (!$request->filled('slug')) {
                    $updateData['slug'] = PostType::generateUniqueSlug($validated['name'], $postType->id);
                }
            }

            if ($request->filled('slug')) {
                $updateData['slug'] = PostType::generateUniqueSlug($validated['slug'], $postType->id);
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
                $menuOrder = $validated['menu_order'] !== null
                    ? (int) $validated['menu_order']
                    : $this->getNextAvailableMenuOrder($postType->id);

                if (PostType::menuOrderExists($menuOrder, $postType->id)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Menu order already exists.',
                        'errors' => [
                            'menu_order' => [
                                'Menu order ' . $menuOrder . ' is already assigned to another post type.',
                            ],
                        ],
                    ], 422);
                }

                $updateData['menu_order'] = $menuOrder;
            }

            if (!empty($updateData)) {
                $postType->update($updateData);
            }

            if (array_key_exists('taxonomies', $validated)) {
                $this->syncTaxonomies($postType, $validated['taxonomies'] ?? []);
            }

            DB::commit();

            $postType->refresh()->load(['creator', 'taxonomies']);

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

    public function trash(Request $request)
    {
        try {
            $query = PostType::onlyTrashed()
                ->with(['creator', 'taxonomies'])
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

            if ($postType->menu_order && PostType::menuOrderExists((int) $postType->menu_order, $postType->id)) {
                $postType->menu_order = $this->getNextAvailableMenuOrder($postType->id);
                $postType->save();
            }

            $postType->restore();
            $postType->load(['creator', 'taxonomies']);

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

    public function fields($id)
    {
        try {
            $postType = PostType::with('activeCustomFields')->find($id);

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
                    'custom_fields' => $postType->activeCustomFields,
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

    public function taxonomies($id)
    {
        try {
            $postType = PostType::with('activeTaxonomies')->find($id);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Post type taxonomies fetched successfully.',
                'data' => [
                    'id' => $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'taxonomies' => $postType->activeTaxonomies,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type taxonomies.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function syncTaxonomies(PostType $postType, array $taxonomyIds): void
    {
        $syncData = [];

        foreach (array_values($taxonomyIds) as $index => $taxonomyId) {
            $syncData[$taxonomyId] = [
                'sort_order' => $index + 1,
                'status' => true,
            ];
        }

        $postType->taxonomies()->sync($syncData);
    }

    private function getNextSortOrder(): int
    {
        $maxSortOrder = PostType::withTrashed()->max('sort_order');

        return $maxSortOrder ? ((int) $maxSortOrder + 1) : 1;
    }

    private function getNextAvailableMenuOrder(?int $ignoreId = null): int
    {
        $usedOrders = PostType::withTrashed()
            ->whereNotNull('menu_order')
            ->where('menu_order', '>=', 6)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->orderBy('menu_order', 'asc')
            ->pluck('menu_order')
            ->map(fn($order) => (int) $order)
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

            'taxonomies' => $postType->relationLoaded('taxonomies')
                ? $postType->taxonomies->map(function ($taxonomy) {
                    return [
                        'id' => $taxonomy->id,
                        'name' => $taxonomy->name,
                        'slug' => $taxonomy->slug,
                        'hierarchical' => (bool) $taxonomy->hierarchical,
                        'status' => (bool) $taxonomy->status,
                        'sort_order' => $taxonomy->pivot->sort_order ?? null,
                    ];
                })->values()
                : [],

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

    private function getUserFullName($user): ?string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        if (!empty($fullName)) {
            return $fullName;
        }

        return $user->name ?? $user->email ?? null;
    }

    private function getUserRoleName($user): ?string
    {
        if (method_exists($user, 'roles')) {
            try {
                $roleName = $user->roles()->pluck('name')->first();

                if (!empty($roleName)) {
                    return $roleName;
                }
            } catch (\Exception $e) {
                //
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
    public function menu(Request $request)
    {
        try {
            $postTypes = PostType::query()
                ->where('status', true)
                ->orderBy('menu_order', 'asc')
                ->get()
                ->map(function ($postType) {
                    return [
                        'id' => $postType->id,
                        'name' => $postType->name,
                        'slug' => $postType->slug,
                        'description' => $postType->description,
                        'menu_order' => $postType->menu_order,
                        'supports' => $postType->supports ?? [],
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Post type menu fetched successfully.',
                'data' => $postTypes,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type menu.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
