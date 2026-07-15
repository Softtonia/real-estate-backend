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
    // --------------------------------------------------
    // List Post Types
    // --------------------------------------------------

    public function index(Request $request)
    {
        try {
            $query = PostType::query()
                ->with([
                    'creator',
                    'taxonomies',
                    'relatedPostTypes',
                ])
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = $request->search;

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->when(
                    $request->filled('status'),
                    fn ($query) => $query->where(
                        'status',
                        filter_var(
                            $request->status,
                            FILTER_VALIDATE_BOOLEAN,
                            FILTER_NULL_ON_FAILURE
                        )
                    )
                )
                ->when(
                    $request->filled('is_default'),
                    fn ($query) => $query->where(
                        'is_default',
                        filter_var(
                            $request->is_default,
                            FILTER_VALIDATE_BOOLEAN,
                            FILTER_NULL_ON_FAILURE
                        )
                    )
                )
                ->when(
                    $request->filled('is_relationship'),
                    fn ($query) => $query->where(
                        'is_relationship',
                        filter_var(
                            $request->is_relationship,
                            FILTER_VALIDATE_BOOLEAN,
                            FILTER_NULL_ON_FAILURE
                        )
                    )
                )
                ->ordered();

            $perPage = min(
                max((int) $request->get('per_page', 10), 1),
                100
            );

            $postTypes = $query->paginate($perPage);

            $postTypes->getCollection()->transform(
                fn ($postType) => $this->formatPostType($postType)
            );

            return response()->json([
                'status' => true,
                'message' => 'Post types fetched successfully.',
                'data' => $postTypes,
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post types.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Create Post Type
    // --------------------------------------------------

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:150',
                ],
                'slug' => [
                    'nullable',
                    'string',
                    'max:150',
                    'regex:/^[a-z0-9_-]+$/',
                ],
                'description' => [
                    'nullable',
                    'string',
                ],
                'is_default' => [
                    'nullable',
                    'boolean',
                ],
                'is_relationship' => [
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
                'supports.*' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
                'sort_order' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],

                // menu_order is intentionally not accepted.

                'post_type_ids' => [
                    'nullable',
                    'array',
                ],
                'post_type_ids.*' => [
                    'integer',
                    'exists:post_types,id',
                ],
                'taxonomies' => [
                    'nullable',
                    'array',
                ],
                'taxonomies.*' => [
                    'integer',
                    'exists:taxonomies,id',
                ],
            ]);

            $isDefault = $request->boolean('is_default');
            $isRelationship = $request->boolean('is_relationship');

            $status = $request->has('status')
                ? $request->boolean('status')
                : true;

            $postType = DB::transaction(function () use (
                $validated,
                $isDefault,
                $isRelationship,
                $status
            ) {
                $slugSource = !empty($validated['slug'])
                    ? $validated['slug']
                    : $validated['name'];

                $postType = PostType::create([
                    'name' => $validated['name'],
                    'slug' => PostType::generateUniqueSlug($slugSource),
                    'description' => $validated['description'] ?? null,
                    'is_default' => $isDefault,
                    'is_relationship' => $isRelationship,
                    'status' => $status,
                    'supports' => $this->normalizeSupportsForSave(
                        $validated['supports'] ?? null,
                        [
                            'title',
                            'excerpt',
                            'featured_image',
                            'content',
                        ]
                    ),
                    'created_by' => Auth::id(),
                    'sort_order' => $validated['sort_order']
                        ?? $this->getNextSortOrder(),

                    // Automatically generated, never received from request.
                    'menu_order' => $this->getNextAvailableMenuOrder(),
                ]);

                if (
                    $isRelationship
                    && !empty($validated['post_type_ids'])
                ) {
                    $this->syncRelatedPostTypes(
                        $postType,
                        $validated['post_type_ids']
                    );
                }

                if (array_key_exists('taxonomies', $validated)) {
                    $this->syncTaxonomies(
                        $postType,
                        $validated['taxonomies'] ?? []
                    );
                }

                return $postType;
            });

            $postType->load([
                'creator',
                'taxonomies',
                'relatedPostTypes',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Post type created successfully.',
                'data' => $this->formatPostType($postType),
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to create post type.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Show Post Type
    // --------------------------------------------------

    public function show($postType)
    {
        try {
            $postType = $this->resolvePostType($postType);

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $postType->load([
                'creator',
                'taxonomies',
                'relatedPostTypes',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Post type fetched successfully.',
                'data' => $this->formatPostType($postType),
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Update Post Type
    // --------------------------------------------------

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $postType = $this->resolvePostType($id);

            if (!$postType) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $validated = $request->validate([
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:150',
                ],
                'slug' => [
                    'nullable',
                    'string',
                    'max:150',
                    'regex:/^[a-z0-9_-]+$/',
                ],
                'description' => [
                    'nullable',
                    'string',
                ],
                'is_default' => [
                    'nullable',
                    'boolean',
                ],
                'is_relationship' => [
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
                'supports.*' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
                'sort_order' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],

                // menu_order is intentionally not accepted or updated.

                'post_type_ids' => [
                    'nullable',
                    'array',
                ],
                'post_type_ids.*' => [
                    'integer',
                    'exists:post_types,id',
                ],
                'taxonomies' => [
                    'nullable',
                    'array',
                ],
                'taxonomies.*' => [
                    'integer',
                    'exists:taxonomies,id',
                ],
            ]);

            $updateData = [];

            if (array_key_exists('name', $validated)) {
                $updateData['name'] = $validated['name'];

                if (!$request->filled('slug')) {
                    $updateData['slug'] = PostType::generateUniqueSlug(
                        $validated['name'],
                        $postType->id
                    );
                }
            }

            if ($request->filled('slug')) {
                $updateData['slug'] = PostType::generateUniqueSlug(
                    $validated['slug'],
                    $postType->id
                );
            }

            if (array_key_exists('description', $validated)) {
                $updateData['description'] = $validated['description'];
            }

            if (array_key_exists('is_default', $validated)) {
                $updateData['is_default'] = $request->boolean('is_default');
            }

            if (array_key_exists('is_relationship', $validated)) {
                $updateData['is_relationship'] = $request->boolean(
                    'is_relationship'
                );
            }

            if (array_key_exists('status', $validated)) {
                $updateData['status'] = $request->boolean('status');
            }

            if (array_key_exists('supports', $validated)) {
                $updateData['supports'] = $this->normalizeSupportsForSave(
                    $validated['supports']
                );
            }

            if (array_key_exists('sort_order', $validated)) {
                $updateData['sort_order'] = $validated['sort_order'];
            }

            /*
             * menu_order is never included in $updateData.
             * Existing menu_order remains unchanged.
             */

            if (!empty($updateData)) {
                $postType->update($updateData);
            }

            if (array_key_exists('is_relationship', $validated)) {
                if ($request->boolean('is_relationship')) {
                    $this->syncRelatedPostTypes(
                        $postType,
                        $validated['post_type_ids'] ?? []
                    );
                } else {
                    $postType->relatedPostTypes()->detach();
                }
            } elseif (
                array_key_exists('post_type_ids', $validated)
                && $postType->is_relationship
            ) {
                $this->syncRelatedPostTypes(
                    $postType,
                    $validated['post_type_ids'] ?? []
                );
            }

            if (array_key_exists('taxonomies', $validated)) {
                $this->syncTaxonomies(
                    $postType,
                    $validated['taxonomies'] ?? []
                );
            }

            DB::commit();

            $postType
                ->refresh()
                ->load([
                    'creator',
                    'taxonomies',
                    'relatedPostTypes',
                ]);

            return response()->json([
                'status' => true,
                'message' => 'Post type updated successfully.',
                'data' => $this->formatPostType($postType),
            ], 200);
        } catch (ValidationException $exception) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to update post type.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Delete Post Type
    // --------------------------------------------------

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
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete post type.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Trash
    // --------------------------------------------------

    public function trash(Request $request)
    {
        try {
            $query = PostType::onlyTrashed()
                ->with([
                    'creator',
                    'taxonomies',
                    'relatedPostTypes',
                ])
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = $request->search;

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
                })
                ->orderBy('deleted_at', 'desc');

            $perPage = min(
                max((int) $request->get('per_page', 10), 1),
                100
            );

            $postTypes = $query->paginate($perPage);

            $postTypes->getCollection()->transform(
                fn ($postType) => $this->formatPostType($postType)
            );

            return response()->json([
                'status' => true,
                'message' => 'Trash post types fetched successfully.',
                'data' => $postTypes,
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch trash post types.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Restore Post Type
    // --------------------------------------------------

    public function restore($id)
    {
        DB::beginTransaction();

        try {
            $postType = PostType::onlyTrashed()->find($id);

            if (!$postType) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found in trash.',
                ], 404);
            }

            /*
             * Normally this conflict cannot happen because deleted menu
             * orders remain reserved. This is kept as a safety check.
             */
            if (
                $postType->menu_order !== null
                && PostType::menuOrderExists(
                    (int) $postType->menu_order,
                    $postType->id
                )
            ) {
                $postType->menu_order = $this->getNextAvailableMenuOrder(
                    $postType->id
                );

                $postType->save();
            }

            $postType->restore();

            DB::commit();

            $postType->load([
                'creator',
                'taxonomies',
                'relatedPostTypes',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Post type restored successfully.',
                'data' => $this->formatPostType($postType),
            ], 200);
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to restore post type.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Force Delete
    // --------------------------------------------------

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
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete post type.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Bulk Soft Delete
    // --------------------------------------------------

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
                    'integer',
                    'exists:post_types,id',
                ],
            ]);

            $postTypes = PostType::whereIn('id', $validated['ids'])
                ->where('is_default', false)
                ->get();

            $deletedCount = 0;

            foreach ($postTypes as $postType) {
                if ($postType->delete()) {
                    $deletedCount++;
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Selected post types deleted successfully.',
                'deleted_count' => $deletedCount,
            ], 200);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete selected post types.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Bulk Force Delete
    // --------------------------------------------------

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
                    'integer',
                ],
            ]);

            $postTypes = PostType::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->where('is_default', false)
                ->get();

            $deletedCount = 0;

            foreach ($postTypes as $postType) {
                if ($postType->forceDelete()) {
                    $deletedCount++;
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Selected post types permanently deleted successfully.',
                'deleted_count' => $deletedCount,
            ], 200);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete selected post types.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Bulk Restore
    // --------------------------------------------------

    public function bulkRestore(Request $request)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'ids' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'ids.*' => [
                    'integer',
                ],
            ]);

            $postTypes = PostType::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->get();

            $restoredCount = 0;

            foreach ($postTypes as $postType) {
                if (
                    $postType->menu_order !== null
                    && PostType::menuOrderExists(
                        (int) $postType->menu_order,
                        $postType->id
                    )
                ) {
                    $postType->menu_order = $this->getNextAvailableMenuOrder(
                        $postType->id
                    );

                    $postType->save();
                }

                if ($postType->restore()) {
                    $restoredCount++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected post types restored successfully.',
                'restored_count' => $restoredCount,
            ], 200);
        } catch (ValidationException $exception) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to restore selected post types.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Fields
    // --------------------------------------------------

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
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type fields.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Taxonomies
    // --------------------------------------------------

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
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type taxonomies.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Menu
    // --------------------------------------------------

    public function menu(Request $request)
    {
        try {
            $postTypes = PostType::query()
                ->where('status', true)
                ->orderByRaw(
                    'CASE WHEN menu_order IS NULL THEN 1 ELSE 0 END'
                )
                ->orderBy('menu_order', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->map(fn ($postType) => [
                    'id' => $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'description' => $postType->description,
                    'menu_order' => $postType->menu_order,
                    'supports' => $this->normalizeSupportsForSave(
                        $postType->supports ?? []
                    ),
                ])
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Post type menu fetched successfully.',
                'data' => $postTypes,
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post type menu.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    // --------------------------------------------------
    // Support Options
    // --------------------------------------------------

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

    // --------------------------------------------------
    // Menu Order Helpers
    // --------------------------------------------------

    /**
     * Automatically finds the first available menu order from 6 onward.
     *
     * Example:
     * Used orders: 6, 7, 9
     * Returned order: 8
     */
    private function getNextAvailableMenuOrder(
        ?int $ignoreId = null
    ): int {
        $usedOrders = PostType::withTrashed()
            ->whereNotNull('menu_order')
            ->where('menu_order', '>=', 6)
            ->when(
                $ignoreId,
                fn ($query) => $query->where('id', '!=', $ignoreId)
            )
            ->orderBy('menu_order', 'asc')
            ->pluck('menu_order')
            ->map(fn ($order) => (int) $order)
            ->unique()
            ->values()
            ->toArray();

        $nextOrder = 6;

        foreach ($usedOrders as $usedOrder) {
            if ($usedOrder === $nextOrder) {
                $nextOrder++;
                continue;
            }

            if ($usedOrder > $nextOrder) {
                break;
            }
        }

        return $nextOrder;
    }

    private function getNextSortOrder(): int
    {
        $maximumSortOrder = PostType::withTrashed()->max('sort_order');

        return $maximumSortOrder !== null
            ? ((int) $maximumSortOrder + 1)
            : 1;
    }

    // --------------------------------------------------
    // Relationship Helpers
    // --------------------------------------------------

    private function syncRelatedPostTypes(
        PostType $postType,
        array $postTypeIds
    ): void {
        $syncData = [];

        $uniquePostTypeIds = array_values(
            array_unique(
                array_map('intval', $postTypeIds)
            )
        );

        foreach ($uniquePostTypeIds as $index => $relatedPostTypeId) {
            if ($relatedPostTypeId === (int) $postType->id) {
                continue;
            }

            $syncData[$relatedPostTypeId] = [
                'sort_order' => $index + 1,
                'status' => true,
            ];
        }

        $postType->relatedPostTypes()->sync($syncData);
    }

    private function syncTaxonomies(
        PostType $postType,
        array $taxonomyIds
    ): void {
        $syncData = [];

        $uniqueTaxonomyIds = array_values(
            array_unique(
                array_map('intval', $taxonomyIds)
            )
        );

        foreach ($uniqueTaxonomyIds as $index => $taxonomyId) {
            $syncData[$taxonomyId] = [
                'sort_order' => $index + 1,
                'status' => true,
            ];
        }

        $postType->taxonomies()->sync($syncData);
    }

    // --------------------------------------------------
    // Support Helpers
    // --------------------------------------------------

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
            [
                'label' => 'Location',
                'value' => 'location',
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
            'location',
        ];
    }

    private function normalizeSupportsForSave(
        mixed $supports,
        ?array $default = null
    ): array {
        if ($supports === null || $supports === '') {
            return $default ?? [];
        }

        if (is_string($supports)) {
            $decodedSupports = json_decode($supports, true);

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decodedSupports)
            ) {
                $supports = $decodedSupports;
            } else {
                $supports = str_contains($supports, ',')
                    ? explode(',', $supports)
                    : [$supports];
            }
        }

        if (!is_array($supports)) {
            return $default ?? [];
        }

        $supports = collect($supports)
            ->filter(
                fn ($item) => $item !== null && $item !== ''
            )
            ->map(function ($item) {
                $item = trim((string) $item);
                $item = Str::slug($item, '_');

                return match ($item) {
                    'featured_image_id',
                    'featuredimage',
                    'featured' => 'featured_image',

                    'custom_field',
                    'customfields' => 'custom_fields',

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
            ->filter(
                fn ($item) => in_array(
                    $item,
                    $allowedValues,
                    true
                )
            )
            ->unique()
            ->values()
            ->toArray();
    }

    // --------------------------------------------------
    // Resolve Post Type
    // --------------------------------------------------

    private function resolvePostType(
        string|int $value
    ): ?PostType {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return PostType::query()
            ->where(function ($query) use ($value) {
                if (is_numeric($value)) {
                    $query->where('id', (int) $value);
                }

                $query
                    ->orWhere('slug', $value)
                    ->orWhere('name', $value);
            })
            ->first();
    }

    // --------------------------------------------------
    // Response Formatter
    // --------------------------------------------------

    private function formatPostType($postType): array
    {
        $creator = $postType->creator;

        return [
            'id' => $postType->id,
            'name' => $postType->name,
            'slug' => $postType->slug,
            'description' => $postType->description,
            'is_default' => (bool) $postType->is_default,
            'is_relationship' => (bool) $postType->is_relationship,
            'status' => (bool) $postType->status,
            'supports' => $postType->supports ?? [],
            'sort_order' => $postType->sort_order,

            // Read-only value generated by backend.
            'menu_order' => $postType->menu_order,

            'post_type_ids' => $postType->relationLoaded(
                'relatedPostTypes'
            )
                ? $postType->relatedPostTypes
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->toArray()
                : [],

            'related_post_types' => $postType->relationLoaded(
                'relatedPostTypes'
            )
                ? $postType->relatedPostTypes
                    ->map(fn ($relatedPostType) => [
                        'id' => (int) $relatedPostType->id,
                        'name' => $relatedPostType->name,
                        'slug' => $relatedPostType->slug,
                        'status' => (bool) $relatedPostType->status,
                        'sort_order' => (int) (
                            $relatedPostType->pivot->sort_order ?? 0
                        ),
                        'pivot_status' => isset(
                            $relatedPostType->pivot->status
                        )
                            ? (bool) $relatedPostType->pivot->status
                            : true,
                    ])
                    ->values()
                    ->toArray()
                : [],

            'taxonomy_ids' => $postType->relationLoaded('taxonomies')
                ? $postType->taxonomies
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->toArray()
                : [],

            'taxonomies' => $postType->relationLoaded('taxonomies')
                ? $postType->taxonomies
                    ->map(fn ($taxonomy) => [
                        'id' => (int) $taxonomy->id,
                        'name' => $taxonomy->name,
                    ])
                    ->values()
                    ->toArray()
                : [],

            'created_by' => $postType->created_by,

            'created_by_user' => $creator
                ? [
                    'id' => $creator->id,
                    'name' => $this->getUserFullName($creator),
                    'email' => $creator->email ?? null,
                    'role' => $this->getUserRoleName($creator),
                ]
                : null,

            'created_at' => $postType->created_at,
            'updated_at' => $postType->updated_at,
            'deleted_at' => $postType->deleted_at ?? null,
        ];
    }

    // --------------------------------------------------
    // User Helpers
    // --------------------------------------------------

    private function getUserFullName($user): ?string
    {
        $fullName = trim(
            ($user->first_name ?? '')
            . ' '
            . ($user->last_name ?? '')
        );

        return $fullName ?: (
            $user->name
            ?? $user->email
            ?? null
        );
    }

    private function getUserRoleName($user): ?string
    {
        if (method_exists($user, 'roles')) {
            try {
                $roleName = $user
                    ->roles()
                    ->pluck('name')
                    ->first();

                if (!empty($roleName)) {
                    return $roleName;
                }
            } catch (Throwable) {
                // Continue to other role formats.
            }
        }

        if (
            isset($user->role)
            && is_object($user->role)
        ) {
            return $user->role->name ?? null;
        }

        if (
            isset($user->role)
            && is_array($user->role)
        ) {
            return $user->role['name'] ?? null;
        }

        if (
            isset($user->role)
            && is_string($user->role)
        ) {
            $decodedRole = json_decode($user->role, true);

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decodedRole)
            ) {
                return $decodedRole['name'] ?? null;
            }

            return $user->role;
        }

        if (
            isset($user->role_slug)
            && is_string($user->role_slug)
        ) {
            return $user->role_slug;
        }

        if (isset($user->role_id)) {
            try {
                $role = DB::table('roles')
                    ->where('id', $user->role_id)
                    ->first();

                return $role->name
                    ?? $role->role_name
                    ?? null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}