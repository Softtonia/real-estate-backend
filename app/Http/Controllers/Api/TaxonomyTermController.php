<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use App\Models\CustomFieldValue;
use App\Services\CustomFieldValueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TaxonomyTermController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = TaxonomyTerm::query()
                ->with([
                    'taxonomy',
                    'parent',
                    'relationWithTaxonomy:id,name,slug,is_relationship,status',
                    'relationValues:id,taxonomy_id,parent_id,name,slug,status,sort_order',
                ])

                // Safer than withCount('posts') if DynamicPost soft delete issue exists
                ->withCount('postTaxonomyTerms as posts_count')

                ->when($request->filled('taxonomy_id'), function ($q) use ($request) {
                    $q->where('taxonomy_id', $request->taxonomy_id);
                })

                ->when($request->has('parent_id'), function ($q) use ($request) {
                    if ($request->parent_id === null || $request->parent_id === 'null') {
                        $q->whereNull('parent_id');
                    } else {
                        $q->where('parent_id', $request->parent_id);
                    }
                })

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

                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc');

            $perPage = (int) $request->get('per_page', 15);
            $perPage = $perPage > 100 ? 100 : $perPage;

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy terms fetched successfully.',
                'data' => $query->paginate($perPage),
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'taxonomy_id' => ['required', 'integer', 'exists:taxonomies,id'],
                'parent_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],

                'relation_with_taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'relation_value_term_ids' => ['nullable', 'array'],
                'relation_value_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

                'name' => ['required', 'string', 'max:150'],
                'slug' => ['nullable', 'string', 'max:150'],
                'description' => ['nullable', 'string'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'boolean'],

                'custom_fields' => ['nullable', 'array'],
                'custom_fields.*.custom_field_id' => ['required_with:custom_fields', 'integer', 'exists:custom_fields,id'],
                'custom_fields.*.custom_field_option_id' => ['nullable', 'integer', 'exists:custom_field_options,id'],
                'custom_fields.*.value_text' => ['nullable'],
                'custom_fields.*.value_string' => ['nullable'],
                'custom_fields.*.value_number' => ['nullable', 'numeric'],
                'custom_fields.*.value_date' => ['nullable', 'date'],
                'custom_fields.*.value_datetime' => ['nullable', 'date'],
                'custom_fields.*.value_json' => ['nullable'],
            ], [
                'taxonomy_id.required' => 'Taxonomy is required.',
                'taxonomy_id.exists' => 'Selected taxonomy does not exist.',
                'name.required' => 'Taxonomy term name is required.',
            ]);

            $slug = !empty($validated['slug'])
                ? Str::slug($validated['slug'])
                : Str::slug($validated['name']);

            $slugExists = TaxonomyTerm::where('taxonomy_id', $validated['taxonomy_id'])
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Taxonomy term slug already exists.',
                    'errors' => [
                        'slug' => [
                            $slug . ' already exists in this taxonomy.',
                        ],
                    ],
                ], 422);
            }

            $term = DB::transaction(function () use ($validated, $slug) {
                $customFields = $validated['custom_fields'] ?? [];

                $relationData = $this->validateRelationFields($validated);

                $term = TaxonomyTerm::create([
                    'taxonomy_id' => $validated['taxonomy_id'],
                    'parent_id' => $validated['parent_id'] ?? null,
                    'relation_with_taxonomy_id' => $relationData['relation_with_taxonomy_id'],
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'description' => $validated['description'] ?? null,
                    'sort_order' => $validated['sort_order'] ?? 0,
                    'status' => $validated['status'] ?? true,
                ]);

                $this->syncRelationValues(
                    $term,
                    $relationData['relation_with_taxonomy_id'],
                    $relationData['relation_value_term_ids']
                );

                if (!empty($customFields)) {
                    $this->saveCustomFieldValues($term->id, 'taxonomy_term', $customFields);
                }

                return $term;
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term created successfully.',
                'data' => $term->fresh()->load([
                    'taxonomy',
                    'parent',
                    'relationWithTaxonomy',
                    'relationValues',
                    'meta.customField',
                ]),
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
                'message' => 'Unable to create taxonomy term.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(TaxonomyTerm $taxonomyTerm)
    {
        try {
            $taxonomyTerm->load([
                'taxonomy',
                'parent',
                'children',
                'relationWithTaxonomy',
                'relationValues',
                'meta.customField',
                'postTaxonomyTerms',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term fetched successfully.',
                'data' => $taxonomyTerm,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch taxonomy term.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, TaxonomyTerm $taxonomyTerm)
    {
        try {
            $validated = $request->validate([
                'taxonomy_id' => ['sometimes', 'required', 'integer', 'exists:taxonomies,id'],
                'parent_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],

                'relation_with_taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'relation_value_term_ids' => ['nullable', 'array'],
                'relation_value_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'slug' => ['nullable', 'string', 'max:150'],
                'description' => ['nullable', 'string'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'boolean'],

                'custom_fields' => ['nullable', 'array'],
                'custom_fields.*.custom_field_id' => ['required_with:custom_fields', 'integer', 'exists:custom_fields,id'],
                'custom_fields.*.custom_field_option_id' => ['nullable', 'integer', 'exists:custom_field_options,id'],
                'custom_fields.*.value_text' => ['nullable'],
                'custom_fields.*.value_string' => ['nullable'],
                'custom_fields.*.value_number' => ['nullable', 'numeric'],
                'custom_fields.*.value_date' => ['nullable', 'date'],
                'custom_fields.*.value_datetime' => ['nullable', 'date'],
                'custom_fields.*.value_json' => ['nullable'],
            ]);

            if (array_key_exists('slug', $validated) && !empty($validated['slug'])) {
                $newSlug = Str::slug($validated['slug']);
                $taxonomyId = $validated['taxonomy_id'] ?? $taxonomyTerm->taxonomy_id;

                $slugExists = TaxonomyTerm::where('taxonomy_id', $taxonomyId)
                    ->where('slug', $newSlug)
                    ->where('id', '!=', $taxonomyTerm->id)
                    ->exists();

                if ($slugExists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Taxonomy term slug already exists.',
                        'errors' => [
                            'slug' => [
                                $newSlug . ' already exists in this taxonomy.',
                            ],
                        ],
                    ], 422);
                }
            }

            DB::transaction(function () use ($taxonomyTerm, $validated) {
                $customFields = $validated['custom_fields'] ?? [];

                $relationData = $this->validateRelationFields($validated, $taxonomyTerm);

                $updateData = [];

                if (array_key_exists('taxonomy_id', $validated)) {
                    $updateData['taxonomy_id'] = $validated['taxonomy_id'];
                }

                if (array_key_exists('parent_id', $validated)) {
                    $updateData['parent_id'] = $validated['parent_id'];
                }

                if (array_key_exists('name', $validated)) {
                    $updateData['name'] = $validated['name'];
                }

                if (array_key_exists('slug', $validated) && !empty($validated['slug'])) {
                    $updateData['slug'] = Str::slug($validated['slug']);
                }

                if (array_key_exists('description', $validated)) {
                    $updateData['description'] = $validated['description'];
                }

                if (array_key_exists('sort_order', $validated)) {
                    $updateData['sort_order'] = $validated['sort_order'];
                }

                if (array_key_exists('status', $validated)) {
                    $updateData['status'] = $validated['status'];
                }

                $updateData['relation_with_taxonomy_id'] = $relationData['relation_with_taxonomy_id'];

                if (!empty($updateData)) {
                    $taxonomyTerm->update($updateData);
                }

                $this->syncRelationValues(
                    $taxonomyTerm,
                    $relationData['relation_with_taxonomy_id'],
                    $relationData['relation_value_term_ids']
                );

                if (!empty($customFields)) {
                    $this->saveCustomFieldValues($taxonomyTerm->id, 'taxonomy_term', $customFields);
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term updated successfully.',
                'data' => $taxonomyTerm->fresh()->load([
                    'taxonomy',
                    'parent',
                    'relationWithTaxonomy',
                    'relationValues',
                    'meta.customField',
                ]),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update taxonomy term.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(TaxonomyTerm $taxonomyTerm)
    {
        try {
            DB::transaction(function () use ($taxonomyTerm) {
                CustomFieldValue::where('entity_type', 'taxonomy_term')
                    ->where('entity_id', $taxonomyTerm->id)
                    ->delete();

                DB::table('taxonomy_term_relations')
                    ->where('taxonomy_term_id', $taxonomyTerm->id)
                    ->orWhere('relation_value_term_id', $taxonomyTerm->id)
                    ->delete();

                $taxonomyTerm->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term deleted successfully.',
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete taxonomy term.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'exists:taxonomy_terms,id'],
            ]);

            $ids = array_values(array_unique(array_map('intval', $request->ids)));
            $deleted = 0;

            DB::transaction(function () use ($ids, &$deleted) {
                CustomFieldValue::where('entity_type', 'taxonomy_term')
                    ->whereIn('entity_id', $ids)
                    ->delete();

                DB::table('taxonomy_term_relations')
                    ->whereIn('taxonomy_term_id', $ids)
                    ->orWhereIn('relation_value_term_id', $ids)
                    ->delete();

                $deleted = TaxonomyTerm::whereIn('id', $ids)->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomy terms deleted successfully.',
                'deleted_count' => $deleted,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete selected taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relation With Taxonomies Dropdown
    |--------------------------------------------------------------------------
    | Ye API relation_with dropdown ke liye enabled taxonomies return karegi.
    */

    public function relationTaxonomies(Request $request)
    {
        try {
            $taxonomies = Taxonomy::query()
                ->select('id', 'name', 'slug')
                ->where('is_relationship', true)
                ->where('status', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Relation taxonomies fetched successfully.',
                'data' => $taxonomies,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch relation taxonomies.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relation Value Terms Dropdown
    |--------------------------------------------------------------------------
    | relation_with taxonomy select hone ke baad us taxonomy ke terms yahan se aayenge.
    */

    public function relationValues(int|string $taxonomy)
    {
        try {
            $taxonomyData = Taxonomy::query()
                ->where(function ($q) use ($taxonomy) {
                    if (is_numeric($taxonomy)) {
                        $q->where('id', (int) $taxonomy);
                    }

                    $q->orWhere('slug', $taxonomy);
                })
                ->where('is_relationship', true)
                ->where('status', true)
                ->first();

            if (!$taxonomyData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Relation taxonomy not found or not enabled.',
                ], 404);
            }

            $terms = TaxonomyTerm::query()
                ->select('id', 'taxonomy_id', 'parent_id', 'name', 'slug')
                ->where('taxonomy_id', $taxonomyData->id)
                ->where('status', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Relation values fetched successfully.',
                'taxonomy' => [
                    'id' => $taxonomyData->id,
                    'name' => $taxonomyData->name,
                    'slug' => $taxonomyData->slug,
                ],
                'data' => $terms,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch relation values.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateRelationFields(array $validated, ?TaxonomyTerm $taxonomyTerm = null): array
    {
        $taxonomyId = $validated['taxonomy_id'] ?? $taxonomyTerm?->taxonomy_id;

        $taxonomy = Taxonomy::find($taxonomyId);

        if (!$taxonomy) {
            throw ValidationException::withMessages([
                'taxonomy_id' => ['Selected taxonomy does not exist.'],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Agar selected taxonomy me is_relationship false hai
        |--------------------------------------------------------------------------
        | To relation fields null rahengi aur pivot empty hoga.
        */

        if (!$taxonomy->is_relationship) {
            return [
                'relation_with_taxonomy_id' => null,
                'relation_value_term_ids' => [],
            ];
        }

        $relationWithTaxonomyId = array_key_exists('relation_with_taxonomy_id', $validated)
            ? ($validated['relation_with_taxonomy_id'] ? (int) $validated['relation_with_taxonomy_id'] : null)
            : ($taxonomyTerm?->relation_with_taxonomy_id ? (int) $taxonomyTerm->relation_with_taxonomy_id : null);

        $relationValueTermIds = array_key_exists('relation_value_term_ids', $validated)
            ? $this->cleanRelationValueIds($validated['relation_value_term_ids'] ?? [])
            : (
                $taxonomyTerm
                ? $taxonomyTerm->relationValues()->pluck('taxonomy_terms.id')->map(fn($id) => (int) $id)->toArray()
                : []
            );

        if (!empty($relationValueTermIds) && empty($relationWithTaxonomyId)) {
            throw ValidationException::withMessages([
                'relation_with_taxonomy_id' => [
                    'Relation with taxonomy is required when relation values are selected.',
                ],
            ]);
        }

        if (!empty($relationWithTaxonomyId) && (int) $relationWithTaxonomyId === (int) $taxonomyId) {
            throw ValidationException::withMessages([
                'relation_with_taxonomy_id' => [
                    'Relation taxonomy cannot be same as selected taxonomy.',
                ],
            ]);
        }

        if (!empty($relationWithTaxonomyId)) {
            $relationTaxonomy = Taxonomy::where('id', $relationWithTaxonomyId)
                ->where('is_relationship', true)
                ->where('status', true)
                ->first();

            if (!$relationTaxonomy) {
                throw ValidationException::withMessages([
                    'relation_with_taxonomy_id' => [
                        'Selected relation taxonomy is not enabled.',
                    ],
                ]);
            }
        }

        if (!empty($relationValueTermIds)) {
            $validTermIds = TaxonomyTerm::where('taxonomy_id', $relationWithTaxonomyId)
                ->where('status', true)
                ->whereIn('id', $relationValueTermIds)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $missingIds = array_values(array_diff($relationValueTermIds, $validTermIds));

            if (!empty($missingIds)) {
                throw ValidationException::withMessages([
                    'relation_value_term_ids' => [
                        'Some selected relation values do not belong to selected relation taxonomy.',
                    ],
                ]);
            }
        }

        return [
            'relation_with_taxonomy_id' => $relationWithTaxonomyId,
            'relation_value_term_ids' => $relationValueTermIds,
        ];
    }

    private function syncRelationValues(
        TaxonomyTerm $taxonomyTerm,
        ?int $relationWithTaxonomyId,
        array $relationValueTermIds
    ): void {
        if (empty($relationWithTaxonomyId) || empty($relationValueTermIds)) {
            $taxonomyTerm->relationValues()->detach();
            return;
        }

        $syncData = [];

        foreach ($relationValueTermIds as $index => $termId) {
            $syncData[$termId] = [
                'relation_with_taxonomy_id' => $relationWithTaxonomyId,
                'sort_order' => $index + 1,
                'status' => true,
            ];
        }

        $taxonomyTerm->relationValues()->sync($syncData);
    }

    private function cleanRelationValueIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function saveCustomFieldValues(int $entityId, string $entityType, array $fields): void
    {
        $service = app(CustomFieldValueService::class);
        $service->saveValues($entityId, $entityType, $fields);
    }
}
