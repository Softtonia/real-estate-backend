<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaxonomyController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | List Taxonomies
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        try {
            $query = Taxonomy::query()
                ->with([
                    'creator:id,first_name,last_name,email',
                    'parent:id,name,slug,is_relationship,is_parent,parent_id,status',
                ])
                ->withCount([
                    'terms',
                    'children as child_taxonomies_count',
                    'customFieldGroups as custom_fields_count',
                ])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = trim($request->input('search'));

                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('is_relationship'), function ($q) use ($request) {
                    $q->where(
                        'is_relationship',
                        filter_var($request->input('is_relationship'), FILTER_VALIDATE_BOOLEAN)
                    );
                })
                ->when($request->filled('is_parent'), function ($q) use ($request) {
                    $q->where(
                        'is_parent',
                        filter_var($request->input('is_parent'), FILTER_VALIDATE_BOOLEAN)
                    );
                })
                ->when($request->has('parent_id'), function ($q) use ($request) {
                    if ($request->input('parent_id') === null || $request->input('parent_id') === 'null') {
                        $q->whereNull('parent_id');
                    } else {
                        $q->where('parent_id', $request->input('parent_id'));
                    }
                })
                ->when($request->filled('is_default'), function ($q) use ($request) {
                    $q->where(
                        'is_default',
                        filter_var($request->input('is_default'), FILTER_VALIDATE_BOOLEAN)
                    );
                })
                ->when($request->filled('hierarchical'), function ($q) use ($request) {
                    $q->where(
                        'hierarchical',
                        filter_var($request->input('hierarchical'), FILTER_VALIDATE_BOOLEAN)
                    );
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where(
                        'status',
                        filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN)
                    );
                })
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc');

            $perPage = min((int) $request->get('per_page', 15), 100);

            $taxonomies = $query->paginate($perPage);

            $taxonomies->getCollection()->transform(function ($taxonomy) {
                $formatted = $this->formatTaxonomy($taxonomy);
                $formatted['terms_count'] = $taxonomy->terms_count ?? 0;
                $formatted['child_taxonomies_count'] = $taxonomy->child_taxonomies_count ?? 0;
                $formatted['custom_fields_count'] = $taxonomy->custom_fields_count ?? 0;

                return $formatted;
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomies fetched successfully.',
                'data' => $taxonomies,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch taxonomies.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Store Taxonomy
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate($this->storeValidationRules($request));

            if ($response = $this->validateRelationshipData($validated)) {
                DB::rollBack();

                return $response;
            }

            $slug = Str::slug($validated['slug'] ?? $validated['name']);

            if (Taxonomy::where('slug', $slug)->exists()) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Taxonomy slug already exists.',
                    'errors' => [
                        'slug' => ["{$slug} already exists. Please use a different taxonomy name."],
                    ],
                ], 422);
            }

            $isRelationship = (bool) ($validated['is_relationship'] ?? false);
            $isParent = $isRelationship ? (bool) ($validated['is_parent'] ?? false) : false;

            $taxonomy = Taxonomy::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,

                'is_relationship' => $isRelationship,
                'is_parent' => $isParent,
                'parent_id' => ($isRelationship && !$isParent)
                    ? ($validated['parent_id'] ?? null)
                    : null,

                'is_default' => $validated['is_default'] ?? false,
                'hierarchical' => $validated['hierarchical'] ?? false,
                'status' => $validated['status'] ?? true,
                'created_by' => Auth::id(),
                'sort_order' => $validated['sort_order'] ?? $this->getNextSortOrder(),
            ]);

            if (array_key_exists('post_type_ids', $validated)) {
                $this->syncPostTypes($taxonomy, $validated['post_type_ids'] ?? []);
            }

            if ($taxonomy->is_relationship && $taxonomy->is_parent) {
                $this->syncChildTaxonomies(
                    $taxonomy,
                    $validated['child_taxonomy_ids'] ?? []
                );
            }

            DB::commit();

            $taxonomy->load([
                'creator:id,first_name,last_name,email',
                'parent:id,name,slug,is_relationship,is_parent,parent_id,status',
                'children:id,parent_id,is_relationship,is_parent,name,slug,status,sort_order',
                'postTypes',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy created successfully.',
                'data' => $this->formatTaxonomy($taxonomy),
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->serverErrorResponse('Unable to create taxonomy.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Show Taxonomy
    |--------------------------------------------------------------------------
    */

    public function show(Taxonomy $taxonomy): JsonResponse
    {
        try {
            $taxonomy->load([
                'creator:id,first_name,last_name,email',
                'parent:id,name,slug,is_relationship,is_parent,parent_id,status',
                'children:id,parent_id,is_relationship,is_parent,name,slug,status,sort_order',
                'postTypes',
                'terms',
                'customFieldGroups.fields.options',
                'customFieldGroups.fields.repeaters.options',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy fetched successfully.',
                'data' => $this->formatTaxonomy($taxonomy),
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch taxonomy.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Taxonomy
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, Taxonomy $taxonomy): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate($this->updateValidationRules($request));

            $relationshipPayload = [
                'is_relationship' => array_key_exists('is_relationship', $validated)
                    ? (bool) $validated['is_relationship']
                    : (bool) $taxonomy->is_relationship,

                'is_parent' => array_key_exists('is_parent', $validated)
                    ? (bool) $validated['is_parent']
                    : (bool) $taxonomy->is_parent,

                'parent_id' => array_key_exists('parent_id', $validated)
                    ? $validated['parent_id']
                    : $taxonomy->parent_id,

                'child_taxonomy_ids' => $validated['child_taxonomy_ids'] ?? null,
            ];

            if ($response = $this->validateRelationshipData($relationshipPayload, $taxonomy)) {
                DB::rollBack();

                return $response;
            }

            $updateData = [];

            foreach ([
                'name',
                'description',
                'is_default',
                'hierarchical',
                'status',
                'sort_order',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = $validated[$field];
                }
            }

            if (array_key_exists('slug', $validated) && !empty($validated['slug'])) {
                $slug = Str::slug($validated['slug']);

                $slugExists = Taxonomy::where('slug', $slug)
                    ->where('id', '!=', $taxonomy->id)
                    ->exists();

                if ($slugExists) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Taxonomy slug already exists.',
                        'errors' => [
                            'slug' => ["{$slug} already exists. Please use a different slug."],
                        ],
                    ], 422);
                }

                $updateData['slug'] = $slug;
            }

            if (array_key_exists('is_relationship', $relationshipPayload)) {
                $updateData['is_relationship'] = $relationshipPayload['is_relationship'];
            }

            if (array_key_exists('is_parent', $relationshipPayload)) {
                $updateData['is_parent'] = $relationshipPayload['is_relationship']
                    ? $relationshipPayload['is_parent']
                    : false;
            }

            $updateData['parent_id'] = (
                $relationshipPayload['is_relationship'] &&
                !$relationshipPayload['is_parent']
            )
                ? $relationshipPayload['parent_id']
                : null;

            $taxonomy->update($updateData);

            if (array_key_exists('post_type_ids', $validated)) {
                $this->syncPostTypes($taxonomy, $validated['post_type_ids'] ?? []);
            }

            /*
             * If taxonomy is no longer parent, detach its existing children.
             */
            if (!$taxonomy->fresh()->is_parent || !$taxonomy->fresh()->is_relationship) {
                $this->detachChildTaxonomies($taxonomy);
            }

            /*
             * If taxonomy is parent and child_taxonomy_ids is passed, sync children.
             */
            $taxonomy = $taxonomy->fresh();

            if (
                $taxonomy->is_relationship &&
                $taxonomy->is_parent &&
                array_key_exists('child_taxonomy_ids', $validated)
            ) {
                $this->syncChildTaxonomies(
                    $taxonomy,
                    $validated['child_taxonomy_ids'] ?? []
                );
            }

            DB::commit();

            $taxonomy = $taxonomy->fresh()->load([
                'creator:id,first_name,last_name,email',
                'parent:id,name,slug,is_relationship,is_parent,parent_id,status',
                'children:id,parent_id,is_relationship,is_parent,name,slug,status,sort_order',
                'postTypes',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy updated successfully.',
                'data' => $this->formatTaxonomy($taxonomy),
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->serverErrorResponse('Unable to update taxonomy.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Taxonomy
    |--------------------------------------------------------------------------
    */

    public function destroy(Taxonomy $taxonomy): JsonResponse
    {
        DB::beginTransaction();

        try {
            if ($taxonomy->is_default) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Default taxonomy cannot be deleted.',
                ], 403);
            }

            $taxonomy->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy deleted successfully.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->serverErrorResponse('Unable to delete taxonomy.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Taxonomy Tree
    |--------------------------------------------------------------------------
    */

    public function tree(Request $request): JsonResponse
    {
        try {
            $taxonomies = Taxonomy::query()
                ->select([
                    'id',
                    'parent_id',
                    'is_relationship',
                    'is_parent',
                    'name',
                    'slug',
                    'description',
                    'status',
                    'sort_order',
                ])
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where(
                        'status',
                        filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN)
                    );
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy tree fetched successfully.',
                'data' => $this->buildTaxonomyTree($taxonomies),
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch taxonomy tree.', $e);
        }
    }

    private function buildTaxonomyTree($taxonomies, $parentId = null): array
    {
        return $taxonomies
            ->where('parent_id', $parentId)
            ->map(function ($taxonomy) use ($taxonomies) {
                return [
                    'id' => $taxonomy->id,
                    'parent_id' => $taxonomy->parent_id,
                    'is_relationship' => (bool) $taxonomy->is_relationship,
                    'is_parent' => (bool) $taxonomy->is_parent,
                    'name' => $taxonomy->name,
                    'slug' => $taxonomy->slug,
                    'description' => $taxonomy->description,
                    'status' => (bool) $taxonomy->status,
                    'sort_order' => $taxonomy->sort_order,
                    'children' => $this->buildTaxonomyTree($taxonomies, $taxonomy->id),
                ];
            })
            ->values()
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Related Terms
    |--------------------------------------------------------------------------
    */

    public function terms(Taxonomy $taxonomy): JsonResponse
    {
        try {
            $terms = $taxonomy->terms()
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy terms fetched successfully.',
                'data' => $terms,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch taxonomy terms.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Related Fields
    |--------------------------------------------------------------------------
    */

    public function fields(Taxonomy $taxonomy): JsonResponse
    {
        try {
            $fields = $taxonomy->customFieldGroups()
                ->with([
                    'fields.options',
                    'fields.repeaters.options',
                ])
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy fields fetched successfully.',
                'data' => $fields,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch taxonomy fields.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    */

    public function trash(Request $request): JsonResponse
    {
        try {
            $query = Taxonomy::onlyTrashed()
                ->with([
                    'creator:id,first_name,last_name,email',
                    'parent:id,name,slug,is_relationship,is_parent,parent_id,status',
                ])
                ->withCount([
                    'terms',
                    'children as child_taxonomies_count',
                    'customFieldGroups as custom_fields_count',
                ])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = trim($request->search);

                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
                })
                ->orderBy('deleted_at', 'desc');

            $perPage = min((int) $request->get('per_page', 15), 100);

            $taxonomies = $query->paginate($perPage);

            $taxonomies->getCollection()->transform(function ($taxonomy) {
                $formatted = $this->formatTaxonomy($taxonomy);
                $formatted['terms_count'] = $taxonomy->terms_count ?? 0;
                $formatted['child_taxonomies_count'] = $taxonomy->child_taxonomies_count ?? 0;
                $formatted['custom_fields_count'] = $taxonomy->custom_fields_count ?? 0;

                return $formatted;
            });

            return response()->json([
                'status' => true,
                'message' => 'Trash taxonomies fetched successfully.',
                'data' => $taxonomies,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch trash taxonomies.', $e);
        }
    }

    public function restore($id): JsonResponse
    {
        try {
            $taxonomy = Taxonomy::onlyTrashed()->find($id);

            if (!$taxonomy) {
                return response()->json([
                    'status' => false,
                    'message' => 'Taxonomy not found in trash.',
                ], 404);
            }

            $taxonomy->restore();

            $taxonomy->load([
                'creator:id,first_name,last_name,email',
                'parent:id,name,slug,is_relationship,is_parent,parent_id,status',
                'children:id,parent_id,is_relationship,is_parent,name,slug,status,sort_order',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy restored successfully.',
                'data' => $this->formatTaxonomy($taxonomy),
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to restore taxonomy.', $e);
        }
    }

    public function forceDelete($id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $taxonomy = Taxonomy::onlyTrashed()->find($id);

            if (!$taxonomy) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Taxonomy not found in trash.',
                ], 404);
            }

            if ($taxonomy->is_default) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Default taxonomy cannot be permanently deleted.',
                ], 403);
            }

            $this->detachChildTaxonomies($taxonomy);

            $taxonomy->forceDelete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy permanently deleted successfully.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->serverErrorResponse('Unable to permanently delete taxonomy.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk Actions
    |--------------------------------------------------------------------------
    */

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['integer', 'exists:taxonomies,id'],
            ]);

            $deletedCount = Taxonomy::whereIn('id', $validated['ids'])
                ->where('is_default', false)
                ->delete();

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomies deleted successfully.',
                'deleted_count' => $deletedCount,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to delete selected taxonomies.', $e);
        }
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['integer'],
            ]);

            $restoredCount = Taxonomy::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->restore();

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomies restored successfully.',
                'restored_count' => $restoredCount,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to restore selected taxonomies.', $e);
        }
    }

    public function bulkForceDelete(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['integer'],
            ]);

            $taxonomies = Taxonomy::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->where('is_default', false)
                ->get();

            $deletedCount = 0;

            foreach ($taxonomies as $taxonomy) {
                $this->detachChildTaxonomies($taxonomy);
                $taxonomy->forceDelete();
                $deletedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomies permanently deleted successfully.',
                'deleted_count' => $deletedCount,
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->serverErrorResponse('Unable to permanently delete selected taxonomies.', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */

    private function storeValidationRules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],

            'is_relationship' => ['nullable', 'boolean'],
            'is_parent' => [
                Rule::requiredIf(fn () => $request->boolean('is_relationship')),
                'nullable',
                'boolean',
            ],

            'parent_id' => [
                Rule::requiredIf(fn () =>
                    $request->boolean('is_relationship') &&
                    !$request->boolean('is_parent')
                ),
                'nullable',
                'integer',
                'exists:taxonomies,id',
            ],

            'child_taxonomy_ids' => ['nullable', 'array'],
            'child_taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],

            'is_default' => ['nullable', 'boolean'],
            'hierarchical' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'post_type_ids' => ['nullable', 'array'],
            'post_type_ids.*' => ['integer', 'exists:post_types,id'],
        ];
    }

    private function updateValidationRules(Request $request): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],

            'is_relationship' => ['nullable', 'boolean'],
            'is_parent' => ['nullable', 'boolean'],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:taxonomies,id',
            ],

            'child_taxonomy_ids' => ['nullable', 'array'],
            'child_taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],

            'is_default' => ['nullable', 'boolean'],
            'hierarchical' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'post_type_ids' => ['nullable', 'array'],
            'post_type_ids.*' => ['integer', 'exists:post_types,id'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationship Validation
    |--------------------------------------------------------------------------
    */

    private function validateRelationshipData(array $data, ?Taxonomy $currentTaxonomy = null): ?JsonResponse
    {
        $isRelationship = (bool) ($data['is_relationship'] ?? false);
        $isParent = (bool) ($data['is_parent'] ?? false);
        $parentId = $data['parent_id'] ?? null;
        $childTaxonomyIds = $data['child_taxonomy_ids'] ?? [];

        if (!$isRelationship) {
            return null;
        }

        if ($isParent && !empty($parentId)) {
            return response()->json([
                'status' => false,
                'message' => 'Parent taxonomy cannot have another parent taxonomy.',
                'errors' => [
                    'parent_id' => ['Parent taxonomy must not have parent_id.'],
                ],
            ], 422);
        }

        if (!$isParent && empty($parentId)) {
            return response()->json([
                'status' => false,
                'message' => 'Child taxonomy must have a parent taxonomy.',
                'errors' => [
                    'parent_id' => ['parent_id is required when is_parent is false.'],
                ],
            ], 422);
        }

        if (!$isParent) {
            if ($currentTaxonomy && (int) $parentId === (int) $currentTaxonomy->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid parent taxonomy.',
                    'errors' => [
                        'parent_id' => ['A taxonomy cannot be parent of itself.'],
                    ],
                ], 422);
            }

            $parentExists = Taxonomy::where('id', $parentId)
                ->where('is_relationship', true)
                ->where('is_parent', true)
                ->exists();

            if (!$parentExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid parent taxonomy.',
                    'errors' => [
                        'parent_id' => ['Selected taxonomy is not a valid parent taxonomy.'],
                    ],
                ], 422);
            }
        }

        if ($isParent && !empty($childTaxonomyIds)) {
            $childTaxonomyIds = array_values(array_unique($childTaxonomyIds));

            if ($currentTaxonomy && in_array($currentTaxonomy->id, $childTaxonomyIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid child taxonomy selection.',
                    'errors' => [
                        'child_taxonomy_ids' => ['A taxonomy cannot be child of itself.'],
                    ],
                ], 422);
            }

            $parentChildIds = Taxonomy::whereIn('id', $childTaxonomyIds)
                ->where('is_relationship', true)
                ->where('is_parent', true)
                ->pluck('id')
                ->toArray();

            if (!empty($parentChildIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid child taxonomy selection.',
                    'errors' => [
                        'child_taxonomy_ids' => [
                            'Selected child taxonomies cannot already be parent taxonomies.',
                        ],
                    ],
                ], 422);
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationship Sync Helpers
    |--------------------------------------------------------------------------
    */

    private function syncChildTaxonomies(Taxonomy $parentTaxonomy, array $childTaxonomyIds): void
    {
        $childTaxonomyIds = array_values(array_unique(array_filter($childTaxonomyIds)));

        /*
         * Detach old children which are not selected now.
         */
        Taxonomy::where('parent_id', $parentTaxonomy->id)
            ->when(!empty($childTaxonomyIds), function ($query) use ($childTaxonomyIds) {
                $query->whereNotIn('id', $childTaxonomyIds);
            })
            ->update([
                'parent_id' => null,
                'is_relationship' => false,
                'is_parent' => false,
                'updated_at' => now(),
            ]);

        if (empty($childTaxonomyIds)) {
            return;
        }

        /*
         * Attach selected taxonomies as children.
         */
        Taxonomy::whereIn('id', $childTaxonomyIds)
            ->where('id', '!=', $parentTaxonomy->id)
            ->where(function ($query) {
                $query->where('is_parent', false)
                    ->orWhereNull('is_parent');
            })
            ->update([
                'parent_id' => $parentTaxonomy->id,
                'is_relationship' => true,
                'is_parent' => false,
                'updated_at' => now(),
            ]);
    }

    private function detachChildTaxonomies(Taxonomy $parentTaxonomy): void
    {
        Taxonomy::where('parent_id', $parentTaxonomy->id)
            ->update([
                'parent_id' => null,
                'is_relationship' => false,
                'is_parent' => false,
                'updated_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Post Type Sync
    |--------------------------------------------------------------------------
    */

    private function syncPostTypes(Taxonomy $taxonomy, array $postTypeIds): void
    {
        $syncData = [];

        foreach (array_values(array_unique($postTypeIds)) as $index => $postTypeId) {
            $syncData[$postTypeId] = [
                'sort_order' => $index + 1,
                'status' => true,
            ];
        }

        $taxonomy->postTypes()->sync($syncData);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function getNextSortOrder(): int
    {
        return (Taxonomy::max('sort_order') ?? 0) + 1;
    }

    private function formatTaxonomy($taxonomy): array
    {
        $creator = $taxonomy->relationLoaded('creator')
            ? $taxonomy->creator
            : null;

        $parent = $taxonomy->relationLoaded('parent')
            ? $taxonomy->parent
            : null;

        $children = $taxonomy->relationLoaded('children')
            ? $taxonomy->children
            : collect();

        $postTypes = $taxonomy->relationLoaded('postTypes')
            ? $taxonomy->postTypes
            : collect();

        return [
            'id' => $taxonomy->id,

            'parent_id' => $taxonomy->parent_id,
            'is_relationship' => (bool) $taxonomy->is_relationship,
            'is_parent' => (bool) $taxonomy->is_parent,

            'relationship_type' => $this->getRelationshipType($taxonomy),

            'name' => $taxonomy->name,
            'slug' => $taxonomy->slug,
            'description' => $taxonomy->description,

            'is_default' => (bool) $taxonomy->is_default,
            'hierarchical' => (bool) $taxonomy->hierarchical,
            'status' => (bool) $taxonomy->status,
            'sort_order' => $taxonomy->sort_order,

            'parent_taxonomy' => $parent ? [
                'id' => $parent->id,
                'name' => $parent->name,
                'slug' => $parent->slug,
                'is_relationship' => (bool) $parent->is_relationship,
                'is_parent' => (bool) $parent->is_parent,
                'status' => (bool) $parent->status,
            ] : null,

            'child_taxonomy_ids' => $children
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->toArray(),

            'child_taxonomies' => $children
                ->map(fn ($child) => [
                    'id' => $child->id,
                    'parent_id' => $child->parent_id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                    'is_relationship' => (bool) $child->is_relationship,
                    'is_parent' => (bool) $child->is_parent,
                    'status' => (bool) $child->status,
                    'sort_order' => $child->sort_order,
                ])
                ->values()
                ->toArray(),

            'post_type_ids' => $postTypes
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->toArray(),

            'post_types' => $postTypes
                ->map(fn ($postType) => [
                    'id' => $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'status' => (bool) $postType->status,
                    'sort_order' => $postType->pivot->sort_order ?? 0,
                    'pivot_status' => isset($postType->pivot->status)
                        ? (bool) $postType->pivot->status
                        : true,
                ])
                ->values()
                ->toArray(),

            'created_by' => $taxonomy->created_by,

            'created_by_user' => $creator ? [
                'id' => $creator->id,
                'name' => trim(($creator->first_name ?? '') . ' ' . ($creator->last_name ?? '')),
                'email' => $creator->email ?? null,
            ] : null,

            'terms_count' => $taxonomy->terms_count ?? null,
            'child_taxonomies_count' => $taxonomy->child_taxonomies_count ?? null,
            'custom_fields_count' => $taxonomy->custom_fields_count ?? null,

            'created_at' => $taxonomy->created_at,
            'updated_at' => $taxonomy->updated_at,
            'deleted_at' => $taxonomy->deleted_at,
        ];
    }

    private function getRelationshipType(Taxonomy $taxonomy): string
    {
        if (!$taxonomy->is_relationship) {
            return 'standalone';
        }

        return $taxonomy->is_parent ? 'parent' : 'child';
    }

    private function serverErrorResponse(string $message, \Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}