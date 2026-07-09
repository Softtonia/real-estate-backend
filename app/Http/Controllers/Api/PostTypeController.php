<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostTypeController extends Controller
{
    // ---------------- CRUD ----------------

    public function index(Request $request)
    {
        try {
            $query = PostType::query()
                ->with(['creator', 'taxonomies', 'relatedPostTypes'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;
                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('status'), fn($q) => $q->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN)))
                ->when($request->filled('is_default'), fn($q) => $q->where('is_default', filter_var($request->is_default, FILTER_VALIDATE_BOOLEAN)))
                ->when($request->filled('is_relationship'), fn($q) => $q->where('is_relationship', filter_var($request->is_relationship, FILTER_VALIDATE_BOOLEAN)))
                ->ordered();

            $perPage = min((int)$request->get('per_page', 10), 100);
            $postTypes = $query->paginate($perPage);
            $postTypes->getCollection()->transform(fn($pt) => $this->formatPostType($pt));

            return response()->json(['status' => true, 'message' => 'Post types fetched successfully.', 'data' => $postTypes], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to fetch post types.', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9_-]+$/'],
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'is_relationship' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'supports' => ['nullable', 'array'],
                'supports.*' => ['nullable', 'string', 'max:100'],
                'sort_order' => ['nullable', 'integer', 'min:0'],

                // Optional. If not sent, system assigns 6 or next available.
                'menu_order' => ['nullable', 'integer', 'min:6'],

                'post_type_ids' => ['nullable', 'array'],
                'post_type_ids.*' => ['integer', 'exists:post_types,id'],
                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*' => ['integer', 'exists:taxonomies,id'],
            ]);

            $menuOrder = !empty($validated['menu_order'])
                ? (int) $validated['menu_order']
                : $this->getNextAvailableMenuOrder();

            if (PostType::menuOrderExists($menuOrder)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Menu order already exists.',
                    'errors' => [
                        'menu_order' => [
                            "Menu order {$menuOrder} is already assigned to another post type.",
                        ],
                    ],
                ], 422);
            }

            $isDefault = $request->boolean('is_default', false) ? 1 : 0;
            $isRelationship = $request->boolean('is_relationship', false) ? 1 : 0;
            $status = $request->has('status') ? ($request->boolean('status') ? 1 : 0) : 1;

            $postType = DB::transaction(function () use ($validated, $menuOrder, $isDefault, $isRelationship, $status) {
                $slug = !empty($validated['slug']) ? $validated['slug'] : $validated['name'];
                $slug = PostType::generateUniqueSlug($slug);

                $postType = PostType::create([
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'description' => $validated['description'] ?? null,
                    'is_default' => $isDefault,
                    'is_relationship' => $isRelationship,
                    'status' => $status,
                    'supports' => $this->normalizeSupportsForSave(
                        $validated['supports'] ?? null,
                        ['title', 'excerpt', 'featured_image', 'content']
                    ),
                    'created_by' => Auth::id(),
                    'sort_order' => $validated['sort_order'] ?? $this->getNextSortOrder(),
                    'menu_order' => $menuOrder,
                ]);

                if ($isRelationship === 1 && !empty($validated['post_type_ids'])) {
                    $this->syncRelatedPostTypes($postType, $validated['post_type_ids']);
                }

                if (!empty($validated['taxonomies'])) {
                    $this->syncTaxonomies($postType, $validated['taxonomies']);
                }

                return $postType;
            });

            $postType->load(['creator', 'taxonomies', 'relatedPostTypes']);

            return response()->json([
                'status' => true,
                'message' => 'Post type created successfully.',
                'data' => $this->formatPostType($postType),
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
                'message' => 'Unable to create post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    private function getNextAvailableMenuOrder(?int $ignoreId = null): int
    {
        $usedOrders = PostType::withTrashed()
            ->whereNotNull('menu_order')
            ->where('menu_order', '>=', 6)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->orderBy('menu_order')
            ->pluck('menu_order')
            ->map(fn($o) => (int)$o)
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
    public function show($id)
    {
        $postType = PostType::with(['creator', 'taxonomies', 'relatedPostTypes'])->find($id);
        if (!$postType) return response()->json(['status' => false, 'message' => 'Post type not found.'], 404);
        return response()->json(['status' => true, 'message' => 'Post type fetched successfully.', 'data' => $this->formatPostType($postType)], 200);
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
                'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9_-]+$/'],
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'is_relationship' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'supports' => ['nullable', 'array'],
                'supports.*' => ['nullable', 'string', 'max:100'],
                'sort_order' => ['nullable', 'integer', 'min:0'],

                // Optional on update. If sent, must be 6 or above.
                'menu_order' => ['nullable', 'integer', 'min:6'],

                'post_type_ids' => ['nullable', 'array'],
                'post_type_ids.*' => ['integer', 'exists:post_types,id'],
                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*' => ['integer', 'exists:taxonomies,id'],
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
                $updateData['is_default'] = $request->boolean('is_default') ? 1 : 0;
            }

            if (array_key_exists('is_relationship', $validated)) {
                $updateData['is_relationship'] = $request->boolean('is_relationship') ? 1 : 0;
            }

            if (array_key_exists('status', $validated)) {
                $updateData['status'] = $request->boolean('status') ? 1 : 0;
            }

            if (array_key_exists('supports', $validated)) {
                $updateData['supports'] = $this->normalizeSupportsForSave($validated['supports']);
            }

            if (array_key_exists('sort_order', $validated)) {
                $updateData['sort_order'] = $validated['sort_order'];
            }

            if (array_key_exists('menu_order', $validated) && !is_null($validated['menu_order'])) {
                $menuOrder = (int) $validated['menu_order'];

                if (PostType::menuOrderExists($menuOrder, $postType->id)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Menu order already exists.',
                        'errors' => [
                            'menu_order' => [
                                "Menu order {$menuOrder} is already assigned to another post type.",
                            ],
                        ],
                    ], 422);
                }

                $updateData['menu_order'] = $menuOrder;
            }

            $postType->update($updateData);

            if (array_key_exists('is_relationship', $validated)) {
                if ($request->boolean('is_relationship')) {
                    $this->syncRelatedPostTypes($postType, $validated['post_type_ids'] ?? []);
                } else {
                    $postType->relatedPostTypes()->detach();
                }
            } elseif (array_key_exists('post_type_ids', $validated) && !empty($postType->is_relationship)) {
                $this->syncRelatedPostTypes($postType, $validated['post_type_ids'] ?? []);
            }

            if (array_key_exists('taxonomies', $validated)) {
                $this->syncTaxonomies($postType, $validated['taxonomies']);
            }

            DB::commit();

            $postType->refresh()->load(['creator', 'taxonomies', 'relatedPostTypes']);

            return response()->json([
                'status' => true,
                'message' => 'Post type updated successfully.',
                'data' => $this->formatPostType($postType),
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
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
            if (!$postType) return response()->json(['status' => false, 'message' => 'Post type not found.'], 404);
            if ($postType->is_default) return response()->json(['status' => false, 'message' => 'Default post type cannot be deleted.'], 403);
            $postType->delete();
            return response()->json(['status' => true, 'message' => 'Post type deleted successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to delete post type.', 'error' => $e->getMessage()], 500);
        }
    }

    // ---------------- Soft delete / trash / restore ----------------

    public function trash(Request $request)
    {
        $query = PostType::onlyTrashed()->with(['creator', 'taxonomies'])
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', '%' . $request->search . '%')->orWhere('slug', 'like', '%' . $request->search . '%'))
            ->orderBy('deleted_at', 'desc');

        $perPage = min((int)$request->get('per_page', 10), 100);
        $postTypes = $query->paginate($perPage);
        $postTypes->getCollection()->transform(fn($pt) => $this->formatPostType($pt));
        return response()->json(['status' => true, 'message' => 'Trash post types fetched successfully.', 'data' => $postTypes], 200);
    }

    public function restore($id)
    {
        try {
            $postType = PostType::onlyTrashed()->find($id);
            if (!$postType) return response()->json(['status' => false, 'message' => 'Post type not found in trash.'], 404);

            if ($postType->menu_order && PostType::menuOrderExists((int)$postType->menu_order, $postType->id)) {
                $postType->menu_order = $this->getNextAvailableMenuOrder($postType->id);
                $postType->save();
            }

            $postType->restore();
            $postType->load(['creator', 'taxonomies']);
            return response()->json(['status' => true, 'message' => 'Post type restored successfully.', 'data' => $this->formatPostType($postType)], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to restore post type.', 'error' => $e->getMessage()], 500);
        }
    }

    public function forceDelete($id)
    {
        $postType = PostType::onlyTrashed()->find($id);
        if (!$postType) return response()->json(['status' => false, 'message' => 'Post type not found in trash.'], 404);
        if ($postType->is_default) return response()->json(['status' => false, 'message' => 'Default post type cannot be permanently deleted.'], 403);
        $postType->forceDelete();
        return response()->json(['status' => true, 'message' => 'Post type permanently deleted successfully.'], 200);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'exists:post_types,id']]);
        $deleted = PostType::whereIn('id', $validated['ids'])->delete();
        return response()->json(['status' => true, 'message' => 'Selected post types deleted successfully.', 'deleted_count' => $deleted], 200);
    }

    public function bulkForceDelete(Request $request)
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'exists:post_types,id']]);
        $forceDeleted = PostType::onlyTrashed()->whereIn('id', $validated['ids'])->get()->each(function ($pt) {
            if (!$pt->is_default) $pt->forceDelete();
        });
        return response()->json(['status' => true, 'message' => 'Selected post types permanently deleted successfully.', 'deleted_count' => $forceDeleted->count()], 200);
    }

    public function bulkRestore(Request $request)
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'exists:post_types,id']]);
        $restoredCount = PostType::onlyTrashed()->whereIn('id', $validated['ids'])->restore();
        return response()->json(['status' => true, 'message' => 'Selected post types restored successfully.', 'restored_count' => $restoredCount], 200);
    }

    // ---------------- Fields & Taxonomies ----------------

    public function fields($id)
    {
        $postType = PostType::with('activeCustomFields')->find($id);
        if (!$postType) return response()->json(['status' => false, 'message' => 'Post type not found.'], 404);

        return response()->json([
            'status' => true,
            'message' => 'Post type fields fetched successfully.',
            'data' => [
                'id' => $postType->id,
                'name' => $postType->name,
                'slug' => $postType->slug,
                'supports' => $postType->supports ?? [],
                'custom_fields' => $postType->activeCustomFields
            ]
        ], 200);
    }

    public function taxonomies($id)
    {
        $postType = PostType::with('activeTaxonomies')->find($id);
        if (!$postType) return response()->json(['status' => false, 'message' => 'Post type not found.'], 404);

        return response()->json([
            'status' => true,
            'message' => 'Post type taxonomies fetched successfully.',
            'data' => [
                'id' => $postType->id,
                'name' => $postType->name,
                'slug' => $postType->slug,
                'taxonomies' => $postType->activeTaxonomies
            ]
        ], 200);
    }

    public function menu(Request $request)
    {
        $postTypes = PostType::query()->where('status', true)->orderBy('menu_order', 'asc')->get()->map(fn($pt) => [
            'id' => $pt->id,
            'name' => $pt->name,
            'slug' => $pt->slug,
            'description' => $pt->description,
            'menu_order' => $pt->menu_order,
            'supports' => $this->normalizeSupportsForSave($pt->supports ?? []),
        ]);
        return response()->json(['status' => true, 'message' => 'Post type menu fetched successfully.', 'data' => $postTypes], 200);
    }

    // ---------------- Helper ----------------

    private function syncRelatedPostTypes(PostType $postType, array $postTypeIds): void
    {
        $syncData = [];

        foreach (array_values(array_unique($postTypeIds)) as $index => $relatedPostTypeId) {
            // Prevent the post type from relating to itself
            if ((int) $relatedPostTypeId === (int) $postType->id) {
                continue;
            }

            $syncData[$relatedPostTypeId] = [
                'sort_order' => $index + 1,
                'status' => true,
            ];
        }

        $postType->relatedPostTypes()->sync($syncData);
    }

    private function syncTaxonomies(PostType $postType, array $taxonomyIds): void
    {
        $syncData = [];
        foreach (array_values($taxonomyIds) as $index => $taxonomyId) {
            $syncData[$taxonomyId] = ['sort_order' => $index + 1, 'status' => true];
        }
        $postType->taxonomies()->sync($syncData);
    }

    private function getNextSortOrder(): int
    {
        $maxSortOrder = PostType::withTrashed()->max('sort_order');
        return $maxSortOrder ? ((int)$maxSortOrder + 1) : 1;
    }

    private function formatPostType($pt): array
    {
        $creator = $pt->creator;

        return [
            'id' => $pt->id,
            'name' => $pt->name,
            'slug' => $pt->slug,
            'description' => $pt->description,
            'is_default' => (bool) $pt->is_default,
            'is_relationship' => (bool) $pt->is_relationship,
            'status' => (bool) $pt->status,
            'supports' => $pt->supports ?? [],
            'sort_order' => $pt->sort_order,
            'menu_order' => $pt->menu_order,

            // Related post type IDs and full data
            'post_type_ids' => $pt->relationLoaded('relatedPostTypes')
                ? $pt->relatedPostTypes->pluck('id')->map(fn($id) => (int) $id)->values()->toArray()
                : [],
            'related_post_types' => $pt->relationLoaded('relatedPostTypes')
                ? $pt->relatedPostTypes->map(fn($related) => [
                    'id' => (int) $related->id,
                    'name' => $related->name,
                    'slug' => $related->slug,
                    'status' => (bool) $related->status,
                    'sort_order' => $related->pivot->sort_order ?? 0,
                    'pivot_status' => isset($related->pivot->status) ? (bool) $related->pivot->status : true,
                ])->values()->toArray()
                : [],

            // Taxonomy IDs and names
            'taxonomy_ids' => $pt->relationLoaded('taxonomies')
                ? $pt->taxonomies->pluck('id')->map(fn($id) => (int) $id)->values()->toArray()
                : [],
            'taxonomies' => $pt->relationLoaded('taxonomies')
                ? $pt->taxonomies->map(fn($taxonomy) => [
                    'id' => (int) $taxonomy->id,
                    'name' => $taxonomy->name,
                ])->values()->toArray()
                : [],

            'created_by' => $pt->created_by,

            'created_by_user' => $creator ? [
                'id' => $creator->id,
                'name' => $this->getUserFullName($creator),
                'email' => $creator->email ?? null,
                'role' => $this->getUserRoleName($creator),
            ] : null,

            'created_at' => $pt->created_at,
            'updated_at' => $pt->updated_at,
            'deleted_at' => $pt->deleted_at ?? null,
        ];
    }

    private function getUserFullName($user): ?string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return $fullName ?: ($user->name ?? $user->email ?? null);
    }

    private function getUserRoleName($user): ?string
    {
        if (method_exists($user, 'roles')) {
            try {
                $roleName = $user->roles()->pluck('name')->first();
                if (!empty($roleName)) return $roleName;
            } catch (\Exception $e) {
            }
        }
        if (isset($user->role) && is_object($user->role)) return $user->role->name ?? null;
        if (isset($user->role) && is_array($user->role)) return $user->role['name'] ?? null;
        if (isset($user->role) && is_string($user->role)) {
            $decodedRole = json_decode($user->role, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedRole)) return $decodedRole['name'] ?? null;
            return $user->role;
        }
        if (isset($user->role_slug) && is_string($user->role_slug)) return $user->role_slug;
        if (isset($user->role_id)) try {
            $role = DB::table('roles')->where('id', $user->role_id)->first();
            return $role->name ?? $role->role_name ?? null;
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }
    public function supportOptions()
    {
        return response()->json([
            'status' => true,
            'message' => 'Support options fetched successfully.',
            'data' => [
                'select_all_value' => 'select_all',
                'all_values' => $this->getSupportValues(),
                'options' => $this->getSupportOptions(),
            ],
        ], 200);
    }
    private function getSupportOptions(): array
    {
        return [
            [
                'label' => 'Select All',
                'value' => 'select_all',
            ],
            [
                'label' => 'Featured Image',
                'value' => 'featured_image',
            ],
            [
                'label' => 'Title',
                'value' => 'title',
            ],
            [
                'label' => 'Custom Fields',
                'value' => 'custom_fields',
            ],
            [
                'label' => 'Content',
                'value' => 'content',
            ],
            [
                'label' => 'Taxonomies',
                'value' => 'taxonomies',
            ],
            [
                'label' => 'Excerpt',
                'value' => 'excerpt',
            ],
            [
                'label' => 'Gallery',
                'value' => 'gallery',
            ],
            [
                'label' => 'Keywords',
                'value' => 'keywords',
            ],
        ];
    }

    private function getSupportValues(): array
    {
        return [
            'featured_image',
            'title',
            'custom_fields',
            'content',
            'taxonomies',
            'excerpt',
            'gallery',
            'keywords',
        ];
    }

    private function normalizeSupportsForSave(mixed $supports, ?array $default = null): array
    {
        if (is_null($supports) || $supports === '') {
            return $default ?? [];
        }

        if (is_string($supports)) {
            $decoded = json_decode($supports, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $supports = $decoded;
            } else {
                $supports = str_contains($supports, ',')
                    ? explode(',', $supports)
                    : [$supports];
            }
        }

        if (! is_array($supports)) {
            return $default ?? [];
        }

        $supports = collect($supports)
            ->filter(fn($item) => !is_null($item) && $item !== '')
            ->map(function ($item) {
                $item = trim((string) $item);
                $item = Str::slug($item, '_');

                return match ($item) {
                    'featured_image_id', 'featuredimage', 'featured' => 'featured_image',
                    'custom_field', 'customfields' => 'custom_fields',
                    'taxonomy' => 'taxonomies',
                    'editor' => 'content',
                    'keyword' => 'keywords',
                    default => $item,
                };
            })
            ->unique()
            ->values()
            ->toArray();

        if (
            in_array('select_all', $supports, true)
            || in_array('all', $supports, true)
        ) {
            return $this->getSupportValues();
        }

        $allowedValues = $this->getSupportValues();

        return collect($supports)
            ->filter(fn($item) => in_array($item, $allowedValues, true))
            ->unique()
            ->values()
            ->toArray();
    }
}
