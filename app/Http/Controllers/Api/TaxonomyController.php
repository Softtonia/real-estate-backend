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
use Throwable;

class TaxonomyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Taxonomy::query()
                ->with([
                    'creator:id,first_name,last_name,email',
                    'parents:id,name,slug,is_relationship,is_parent,status',
                    'children:id,name,slug,is_relationship,is_parent,status,sort_order',
                ])
                ->withCount([
                    'terms',
                    'parents as parent_taxonomies_count',
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
                    $q->where('is_relationship', filter_var($request->input('is_relationship'), FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('is_parent'), function ($q) use ($request) {
                    $q->where('is_parent', filter_var($request->input('is_parent'), FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('parent_id'), function ($q) use ($request) {
                    $q->whereHas('parents', function ($parentQuery) use ($request) {
                        $parentQuery->where('taxonomies.id', $request->input('parent_id'));
                    });
                })
                ->when($request->filled('parent_ids'), function ($q) use ($request) {
                    $parentIds = array_filter(explode(',', $request->input('parent_ids')));

                    $q->whereHas('parents', function ($parentQuery) use ($parentIds) {
                        $parentQuery->whereIn('taxonomies.id', $parentIds);
                    });
                })
                ->when($request->filled('child_id'), function ($q) use ($request) {
                    $q->whereHas('children', function ($childQuery) use ($request) {
                        $childQuery->where('taxonomies.id', $request->input('child_id'));
                    });
                })
                ->when($request->filled('is_default'), function ($q) use ($request) {
                    $q->where('is_default', filter_var($request->input('is_default'), FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('hierarchical'), function ($q) use ($request) {
                    $q->where('hierarchical', filter_var($request->input('hierarchical'), FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN));
                })
                ->orderBy('sort_order')
                ->orderBy('id');

            $perPage = min((int) $request->get('per_page', 15), 100);

            $taxonomies = $query->paginate($perPage);

            $taxonomies->getCollection()->transform(function ($taxonomy) {
                $formatted = $this->formatTaxonomy($taxonomy);
                $formatted['terms_count'] = $taxonomy->terms_count ?? 0;
                $formatted['parent_taxonomies_count'] = $taxonomy->parent_taxonomies_count ?? 0;
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

            $isRelationship = $request->boolean('is_relationship', false) ? 1 : 0;
            $isParent = $isRelationship ? ($request->boolean('is_parent', false) ? 1 : 0) : 0;
            $isDefault = $request->boolean('is_default', false) ? 1 : 0;
            $hierarchical = $request->boolean('hierarchical', false) ? 1 : 0;
            $status = $request->has('status') ? ($request->boolean('status') ? 1 : 0) : 1;

            $taxonomy = Taxonomy::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'is_relationship' => $isRelationship,
                'is_parent' => $isParent,
                'is_default' => $isDefault,
                'hierarchical' => $hierarchical,
                'status' => $status,
                'created_by' => Auth::id(),
                'sort_order' => $validated['sort_order'] ?? $this->getNextSortOrder(),
            ]);

            if (!$taxonomy->is_relationship) {
                $this->detachAllTaxonomyRelationships($taxonomy);
            } elseif ($taxonomy->is_parent) {
                $this->syncChildTaxonomies($taxonomy, $validated['child_taxonomy_ids'] ?? []);
            } else {
                $this->syncParentTaxonomies($taxonomy, $validated['parent_ids'] ?? []);
            }

            DB::commit();

            $taxonomy->load([
                'creator:id,first_name,last_name,email',
                'parents:id,name,slug,is_relationship,is_parent,status',
                'children:id,name,slug,is_relationship,is_parent,status,sort_order',
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

    public function show(Taxonomy $taxonomy): JsonResponse
    {
        try {
            $taxonomy->load([
                'creator:id,first_name,last_name,email',
                'parents:id,name,slug,is_relationship,is_parent,status',
                'children:id,name,slug,is_relationship,is_parent,status,sort_order',
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

    public function update(
        Request $request,
        Taxonomy $taxonomy
    ): JsonResponse {
        DB::beginTransaction();

        try {
            $validated = $request->validate(
                $this->updateValidationRules($request)
            );

            /*
         * Resolve the final relationship status.
         *
         * When a field is not submitted, preserve its current value.
         */
            $newIsRelationship = array_key_exists(
                'is_relationship',
                $validated
            )
                ? $request->boolean('is_relationship')
                : (bool) $taxonomy->is_relationship;

            /*
         * A standalone taxonomy can never be a parent.
         */
            $newIsParent = $newIsRelationship
                ? (
                    array_key_exists('is_parent', $validated)
                    ? $request->boolean('is_parent')
                    : (bool) $taxonomy->is_parent
                )
                : false;

            /*
         * Parent taxonomy:
         * - parent_ids must be empty
         * - child_taxonomy_ids may contain selected children
         *
         * Child taxonomy:
         * - child_taxonomy_ids must be empty
         * - parent_ids must contain selected parents
         */
            $relationshipPayload = [
                'is_relationship' => $newIsRelationship,
                'is_parent' => $newIsParent,

                'parent_ids' => $newIsRelationship && !$newIsParent
                    ? (
                        array_key_exists('parent_ids', $validated)
                        ? $this->cleanIds(
                            $validated['parent_ids'] ?? []
                        )
                        : $taxonomy
                        ->parents()
                        ->pluck('taxonomies.id')
                        ->map(fn($id) => (int) $id)
                        ->values()
                        ->toArray()
                    )
                    : [],

                'child_taxonomy_ids' => $newIsRelationship && $newIsParent
                    ? (
                        array_key_exists(
                            'child_taxonomy_ids',
                            $validated
                        )
                        ? $this->cleanIds(
                            $validated['child_taxonomy_ids'] ?? []
                        )
                        : $taxonomy
                        ->children()
                        ->pluck('taxonomies.id')
                        ->map(fn($id) => (int) $id)
                        ->values()
                        ->toArray()
                    )
                    : [],
            ];

            /*
         * Validate final relationship configuration before changing
         * any taxonomy or pivot records.
         */
            if (
                $response = $this->validateRelationshipData(
                    $relationshipPayload,
                    $taxonomy
                )
            ) {
                DB::rollBack();

                return $response;
            }

            $updateData = [];

            /*
         * Standard editable fields.
         */
            foreach (
                [
                    'name',
                    'description',
                    'sort_order',
                ] as $field
            ) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = $validated[$field];
                }
            }

            /*
         * Boolean fields.
         */
            if (array_key_exists('is_default', $validated)) {
                $updateData['is_default'] = $request->boolean(
                    'is_default'
                );
            }

            if (array_key_exists('hierarchical', $validated)) {
                $updateData['hierarchical'] = $request->boolean(
                    'hierarchical'
                );
            }

            if (array_key_exists('status', $validated)) {
                $updateData['status'] = $request->boolean(
                    'status'
                );
            }

            /*
         * Update slug only when it is explicitly submitted.
         */
            if (
                array_key_exists('slug', $validated)
                && filled($validated['slug'])
            ) {
                $slug = Str::slug($validated['slug']);

                $slugExists = Taxonomy::query()
                    ->where('slug', $slug)
                    ->where('id', '!=', $taxonomy->id)
                    ->exists();

                if ($slugExists) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Taxonomy slug already exists.',
                        'errors' => [
                            'slug' => [
                                "{$slug} already exists. Please use a different slug.",
                            ],
                        ],
                    ], 422);
                }

                $updateData['slug'] = $slug;
            }

            /*
         * When name changes and slug is not explicitly submitted,
         * preserve the existing slug.
         *
         * Remove this condition if you want the slug to regenerate
         * automatically whenever the name changes.
         */
            $updateData['is_relationship'] = $newIsRelationship;
            $updateData['is_parent'] = $newIsParent;

            $taxonomy->update($updateData);

            $taxonomy = $taxonomy->fresh();

            /*
         * Synchronize the final relationship role.
         */
            if (!$newIsRelationship) {
                /*
             * Standalone:
             * remove all incoming and outgoing relationships.
             */
                $this->detachAllTaxonomyRelationships(
                    $taxonomy
                );
            } elseif ($newIsParent) {
                /*
             * Parent:
             * it cannot retain any parent relationships.
             */
                $oldParentIds = $taxonomy
                    ->parents()
                    ->pluck('taxonomies.id')
                    ->map(fn($id) => (int) $id)
                    ->toArray();

                $taxonomy->parents()->detach();

                $this->syncChildTaxonomies(
                    $taxonomy,
                    $relationshipPayload['child_taxonomy_ids']
                );

                /*
             * Taxonomies detached as old parents may need their
             * relationship flags recalculated.
             */
                $this->recalculateRelationshipFlags(
                    $oldParentIds
                );
            } else {
                /*
             * Child:
             * it cannot retain outgoing child relationships.
             */
                $oldChildIds = $taxonomy
                    ->children()
                    ->pluck('taxonomies.id')
                    ->map(fn($id) => (int) $id)
                    ->toArray();

                $taxonomy->children()->detach();

                $this->syncParentTaxonomies(
                    $taxonomy,
                    $relationshipPayload['parent_ids']
                );

                /*
             * Taxonomies detached as old children may need their
             * relationship flags recalculated.
             */
                $this->recalculateRelationshipFlags(
                    $oldChildIds
                );
            }

            /*
         * Recalculate current taxonomy flags from actual pivot records.
         */
            $this->recalculateRelationshipFlags([
                (int) $taxonomy->id,
            ]);

            DB::commit();

            $taxonomy = $taxonomy
                ->fresh()
                ->load([
                    'creator:id,first_name,last_name,email',

                    'parents:id,name,slug,is_relationship,is_parent,status,sort_order',

                    'children:id,name,slug,is_relationship,is_parent,status,sort_order',
                ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy updated successfully.',
                'data' => $this->formatTaxonomy(
                    $taxonomy
                ),
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

            return $this->serverErrorResponse(
                'Unable to update taxonomy.',
                $exception
            );
        }
    }

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

            $this->detachAllTaxonomyRelationships($taxonomy);

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

    public function tree(Request $request): JsonResponse
    {
        try {
            $parents = Taxonomy::query()
                ->parents()
                ->with([
                    'children' => function ($q) {
                        $q->select([
                            'taxonomies.id',
                            'taxonomies.name',
                            'taxonomies.slug',
                            'taxonomies.is_relationship',
                            'taxonomies.is_parent',
                            'taxonomies.status',
                            'taxonomies.sort_order',
                        ]);
                    },
                ])
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN));
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy tree fetched successfully.',
                'data' => $parents->map(function ($parent) {
                    return [
                        'id' => $parent->id,
                        'is_relationship' => (bool) $parent->is_relationship,
                        'is_parent' => (bool) $parent->is_parent,
                        'name' => $parent->name,
                        'slug' => $parent->slug,
                        'description' => $parent->description,
                        'status' => (bool) $parent->status,
                        'sort_order' => $parent->sort_order,
                        'children' => $parent->children->map(fn($child) => [
                            'id' => $child->id,
                            'name' => $child->name,
                            'slug' => $child->slug,
                            'is_relationship' => (bool) $child->is_relationship,
                            'is_parent' => (bool) $child->is_parent,
                            'status' => (bool) $child->status,
                            'sort_order' => $child->sort_order,
                            'pivot_sort_order' => $child->pivot->sort_order ?? 0,
                            'pivot_status' => isset($child->pivot->status)
                                ? (bool) $child->pivot->status
                                : true,
                        ])->values(),
                    ];
                })->values(),
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch taxonomy tree.', $e);
        }
    }

    public function terms(Taxonomy $taxonomy): JsonResponse
    {
        try {
            $terms = $taxonomy->terms()
                ->orderBy('sort_order')
                ->orderBy('id')
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

    public function trash(Request $request): JsonResponse
    {
        try {
            $query = Taxonomy::onlyTrashed()
                ->with([
                    'creator:id,first_name,last_name,email',
                    'parents:id,name,slug,is_relationship,is_parent,status',
                    'children:id,name,slug,is_relationship,is_parent,status,sort_order',
                ])
                ->withCount([
                    'terms',
                    'parents as parent_taxonomies_count',
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
                $formatted['parent_taxonomies_count'] = $taxonomy->parent_taxonomies_count ?? 0;
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
                'parents:id,name,slug,is_relationship,is_parent,status',
                'children:id,name,slug,is_relationship,is_parent,status,sort_order',
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

            $this->detachAllTaxonomyRelationships($taxonomy);

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

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['integer', 'exists:taxonomies,id'],
            ]);

            $taxonomies = Taxonomy::whereIn('id', $validated['ids'])
                ->where('is_default', false)
                ->get();

            foreach ($taxonomies as $taxonomy) {
                $this->detachAllTaxonomyRelationships($taxonomy);
                $taxonomy->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomies deleted successfully.',
                'deleted_count' => $taxonomies->count(),
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

            foreach ($taxonomies as $taxonomy) {
                $this->detachAllTaxonomyRelationships($taxonomy);
                $taxonomy->forceDelete();
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomies permanently deleted successfully.',
                'deleted_count' => $taxonomies->count(),
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

    private function storeValidationRules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],

            'is_relationship' => ['nullable', 'boolean'],
            'is_parent' => [
                Rule::requiredIf(fn() => $request->boolean('is_relationship')),
                'nullable',
                'boolean',
            ],

            'parent_ids' => [
                Rule::requiredIf(
                    fn() =>
                    $request->boolean('is_relationship') &&
                        !$request->boolean('is_parent')
                ),
                'nullable',
                'array',
            ],
            'parent_ids.*' => ['integer', 'exists:taxonomies,id'],

            'child_taxonomy_ids' => ['nullable', 'array'],
            'child_taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],

            'is_default' => ['nullable', 'boolean'],
            'hierarchical' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
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

            'parent_ids' => ['nullable', 'array'],
            'parent_ids.*' => ['integer', 'exists:taxonomies,id'],

            'child_taxonomy_ids' => ['nullable', 'array'],
            'child_taxonomy_ids.*' => ['integer', 'exists:taxonomies,id'],

            'is_default' => ['nullable', 'boolean'],
            'hierarchical' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function validateRelationshipData(
        array $data,
        ?Taxonomy $currentTaxonomy = null
    ): ?JsonResponse {
        $isRelationship = filter_var(
            $data['is_relationship'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $isParent = $isRelationship && filter_var(
            $data['is_parent'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $parentIds = $this->cleanIds(
            $data['parent_ids'] ?? []
        );

        $childTaxonomyIds = $this->cleanIds(
            $data['child_taxonomy_ids'] ?? []
        );

        if (!$isRelationship) {
            return null;
        }

        if (!$isParent && empty($parentIds)) {
            return response()->json([
                'status' => false,
                'message' => 'Child taxonomy must have at least one parent taxonomy.',
                'errors' => [
                    'parent_ids' => [
                        'Please select at least one parent taxonomy.',
                    ],
                ],
            ], 422);
        }

        if ($currentTaxonomy) {
            $currentTaxonomyId = (int) $currentTaxonomy->id;

            if (in_array($currentTaxonomyId, $parentIds, true)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid parent taxonomy.',
                    'errors' => [
                        'parent_ids' => [
                            'A taxonomy cannot be its own parent.',
                        ],
                    ],
                ], 422);
            }

            if (
                in_array(
                    $currentTaxonomyId,
                    $childTaxonomyIds,
                    true
                )
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid child taxonomy.',
                    'errors' => [
                        'child_taxonomy_ids' => [
                            'A taxonomy cannot be its own child.',
                        ],
                    ],
                ], 422);
            }
        }

        if ($isParent && !empty($parentIds)) {
            return response()->json([
                'status' => false,
                'message' => 'Parent taxonomy cannot receive parent_ids.',
                'errors' => [
                    'parent_ids' => [
                        'Remove parent_ids when Mark as Parent is enabled.',
                    ],
                ],
            ], 422);
        }

        if (!$isParent && !empty($childTaxonomyIds)) {
            return response()->json([
                'status' => false,
                'message' => 'Child taxonomy cannot receive child_taxonomy_ids.',
                'errors' => [
                    'child_taxonomy_ids' => [
                        'Remove child_taxonomy_ids when Mark as Parent is disabled.',
                    ],
                ],
            ], 422);
        }

        if (!empty($parentIds)) {
            $validParentIds = Taxonomy::query()
                ->whereIn('id', $parentIds)
                ->where('status', true)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $invalidParentIds = array_values(
                array_diff($parentIds, $validParentIds)
            );

            if (!empty($invalidParentIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid parent taxonomy selection.',
                    'errors' => [
                        'parent_ids' => [
                            'One or more selected parent taxonomies are unavailable.',
                        ],
                    ],
                ], 422);
            }

            $invalidRoleParentIds = Taxonomy::query()
                ->whereIn('id', $parentIds)
                ->whereHas('parents')
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            if (!empty($invalidRoleParentIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'A child taxonomy cannot be selected as a parent.',
                    'errors' => [
                        'parent_ids' => [
                            'One or more selected taxonomies are already children of another taxonomy.',
                        ],
                    ],
                ], 422);
            }
        }

        if (!empty($childTaxonomyIds)) {
            $validChildIds = Taxonomy::query()
                ->whereIn('id', $childTaxonomyIds)
                ->where('status', true)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $invalidChildIds = array_values(
                array_diff(
                    $childTaxonomyIds,
                    $validChildIds
                )
            );

            if (!empty($invalidChildIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid child taxonomy selection.',
                    'errors' => [
                        'child_taxonomy_ids' => [
                            'One or more selected child taxonomies are unavailable.',
                        ],
                    ],
                ], 422);
            }

            $parentRoleChildIds = Taxonomy::query()
                ->whereIn('id', $childTaxonomyIds)
                ->whereHas('children')
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            if (!empty($parentRoleChildIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'A parent taxonomy cannot be selected as a child.',
                    'errors' => [
                        'child_taxonomy_ids' => [
                            'One or more selected taxonomies already have child taxonomies.',
                        ],
                    ],
                ], 422);
            }
        }

        return null;
    }

    private function syncChildTaxonomies(
        Taxonomy $parentTaxonomy,
        array $childTaxonomyIds
    ): void {
        $childTaxonomyIds = array_values(
            array_diff(
                $this->cleanIds($childTaxonomyIds),
                [(int) $parentTaxonomy->id]
            )
        );

        $oldChildIds = $parentTaxonomy
            ->children()
            ->pluck('taxonomies.id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $parentTaxonomy->children()->sync(
            $this->makeSyncData($childTaxonomyIds)
        );

        $parentTaxonomy->update([
            'is_relationship' => true,
            'is_parent' => true,
        ]);

        if (!empty($childTaxonomyIds)) {
            Taxonomy::query()
                ->whereIn('id', $childTaxonomyIds)
                ->update([
                    'is_relationship' => true,
                    'is_parent' => false,
                    'updated_at' => now(),
                ]);
        }

        $affectedIds = array_values(
            array_unique(
                array_merge(
                    $oldChildIds,
                    $childTaxonomyIds,
                    [(int) $parentTaxonomy->id]
                )
            )
        );

        $this->recalculateRelationshipFlags($affectedIds);
    }

    private function syncParentTaxonomies(
        Taxonomy $childTaxonomy,
        array $parentIds
    ): void {
        $parentIds = array_values(
            array_diff(
                $this->cleanIds($parentIds),
                [(int) $childTaxonomy->id]
            )
        );

        $oldParentIds = $childTaxonomy
            ->parents()
            ->pluck('taxonomies.id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $childTaxonomy->parents()->sync(
            $this->makeSyncData($parentIds)
        );

        $childTaxonomy->update([
            'is_relationship' => true,
            'is_parent' => false,
        ]);

        if (!empty($parentIds)) {
            Taxonomy::query()
                ->whereIn('id', $parentIds)
                ->update([
                    'is_relationship' => true,
                    'is_parent' => true,
                    'updated_at' => now(),
                ]);
        }

        $affectedIds = array_values(
            array_unique(
                array_merge(
                    $oldParentIds,
                    $parentIds,
                    [(int) $childTaxonomy->id]
                )
            )
        );

        $this->recalculateRelationshipFlags($affectedIds);
    }

    private function detachAllTaxonomyRelationships(
        Taxonomy $taxonomy
    ): void {
        $oldParentIds = $taxonomy
            ->parents()
            ->pluck('taxonomies.id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $oldChildIds = $taxonomy
            ->children()
            ->pluck('taxonomies.id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $taxonomy->parents()->detach();
        $taxonomy->children()->detach();

        $taxonomy->update([
            'is_relationship' => false,
            'is_parent' => false,
        ]);

        $affectedIds = array_values(
            array_unique(
                array_merge(
                    $oldParentIds,
                    $oldChildIds,
                    [(int) $taxonomy->id]
                )
            )
        );

        $this->recalculateRelationshipFlags($affectedIds);
    }

    private function detachChildTaxonomies(
        Taxonomy $taxonomy
    ): void {
        $oldChildIds = $taxonomy
            ->children()
            ->pluck('taxonomies.id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $taxonomy->children()->detach();

        $affectedIds = array_values(
            array_unique(
                array_merge(
                    $oldChildIds,
                    [(int) $taxonomy->id]
                )
            )
        );

        $this->recalculateRelationshipFlags($affectedIds);
    }

    private function recalculateRelationshipFlags(
        array $taxonomyIds
    ): void {
        $taxonomyIds = $this->cleanIds($taxonomyIds);

        if (empty($taxonomyIds)) {
            return;
        }

        $taxonomies = Taxonomy::query()
            ->whereIn('id', $taxonomyIds)
            ->withCount([
                'parents',
                'children',
            ])
            ->get();

        foreach ($taxonomies as $taxonomy) {
            $hasParents = (int) $taxonomy->parents_count > 0;
            $hasChildren = (int) $taxonomy->children_count > 0;

            if ($hasChildren) {
                $taxonomy->update([
                    'is_relationship' => true,
                    'is_parent' => true,
                ]);

                continue;
            }

            if ($hasParents) {
                $taxonomy->update([
                    'is_relationship' => true,
                    'is_parent' => false,
                ]);

                continue;
            }

            $taxonomy->update([
                'is_relationship' => false,
                'is_parent' => false,
            ]);
        }
    }
    private function makeSyncData(array $ids): array
    {
        $syncData = [];

        foreach ($this->cleanIds($ids) as $index => $id) {
            $syncData[$id] = [
                'sort_order' => $index + 1,
                'status' => true,
            ];
        }

        return $syncData;
    }

    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function getNextSortOrder(): int
    {
        return (Taxonomy::max('sort_order') ?? 0) + 1;
    }

    private function formatTaxonomy($taxonomy): array
    {
        $creator = $taxonomy->relationLoaded('creator') ? $taxonomy->creator : null;
        $parents = $taxonomy->relationLoaded('parents') ? $taxonomy->parents : collect();
        $children = $taxonomy->relationLoaded('children') ? $taxonomy->children : collect();

        return [
            'id' => $taxonomy->id,
            'is_relationship' => (bool) $taxonomy->is_relationship,
            'is_parent' => (bool) $taxonomy->is_parent,
            'relationship_type' => $this->getRelationshipType($taxonomy),

            'parent_ids' => $parents->pluck('id')->map(fn($id) => (int) $id)->values()->toArray(),
            'parents' => $parents->map(fn($parent) => [
                'id' => $parent->id,
                'name' => $parent->name,
                'slug' => $parent->slug,
                'is_relationship' => (bool) $parent->is_relationship,
                'is_parent' => (bool) $parent->is_parent,
                'status' => (bool) $parent->status,
                'sort_order' => $parent->pivot->sort_order ?? 0,
                'pivot_status' => isset($parent->pivot->status) ? (bool) $parent->pivot->status : true,
            ])->values()->toArray(),

            'child_taxonomy_ids' => $children->pluck('id')->map(fn($id) => (int) $id)->values()->toArray(),
            'child_taxonomies' => $children->map(fn($child) => [
                'id' => $child->id,
                'name' => $child->name,
                'slug' => $child->slug,
                'is_relationship' => (bool) $child->is_relationship,
                'is_parent' => (bool) $child->is_parent,
                'status' => (bool) $child->status,
                'sort_order' => $child->pivot->sort_order ?? 0,
                'pivot_status' => isset($child->pivot->status) ? (bool) $child->pivot->status : true,
            ])->values()->toArray(),

            'name' => $taxonomy->name,
            'slug' => $taxonomy->slug,
            'description' => $taxonomy->description,
            'is_default' => (bool) $taxonomy->is_default,
            'hierarchical' => (bool) $taxonomy->hierarchical,
            'status' => (bool) $taxonomy->status,
            'sort_order' => $taxonomy->sort_order,

            'created_by' => $taxonomy->created_by,
            'created_by_user' => $creator ? [
                'id' => $creator->id,
                'name' => trim(($creator->first_name ?? '') . ' ' . ($creator->last_name ?? '')),
                'email' => $creator->email ?? null,
            ] : null,

            'terms_count' => $taxonomy->terms_count ?? null,
            'parent_taxonomies_count' => $taxonomy->parent_taxonomies_count ?? null,
            'child_taxonomies_count' => $taxonomy->child_taxonomies_count ?? null,
            'custom_fields_count' => $taxonomy->custom_fields_count ?? null,

            'created_at' => $taxonomy->created_at,
            'updated_at' => $taxonomy->updated_at,
            'deleted_at' => $taxonomy->deleted_at,
        ];
    }

    private function getRelationshipType(
        Taxonomy $taxonomy
    ): string {
        $hasParents = $taxonomy->relationLoaded('parents')
            ? $taxonomy->parents->isNotEmpty()
            : $taxonomy->parents()->exists();

        $hasChildren = $taxonomy->relationLoaded('children')
            ? $taxonomy->children->isNotEmpty()
            : $taxonomy->children()->exists();

        if ($hasChildren && $hasParents) {
            return 'conflict';
        }

        if ($hasChildren) {
            return 'parent';
        }

        if ($hasParents) {
            return 'child';
        }

        return 'standalone';
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
