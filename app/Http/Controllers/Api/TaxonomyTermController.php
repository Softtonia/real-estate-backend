<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxonomyTerm;
use App\Models\CustomFieldValue;
use App\Services\CustomFieldValueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaxonomyTermController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = TaxonomyTerm::query()
                ->with(['taxonomy', 'parent'])

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

        } catch (\Exception $e) {
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

                $term = TaxonomyTerm::create([
                    'taxonomy_id' => $validated['taxonomy_id'],
                    'parent_id' => $validated['parent_id'] ?? null,
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'description' => $validated['description'] ?? null,
                    'sort_order' => $validated['sort_order'] ?? 0,
                    'status' => $validated['status'] ?? true,
                ]);

                if (!empty($customFields)) {
                    $this->saveCustomFieldValues($term->id, 'taxonomy_term', $customFields);
                }

                return $term;
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term created successfully.',
                'data' => $term->fresh()->load(['taxonomy', 'parent', 'meta.customField']),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
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
                'meta.customField',
                'postTaxonomyTerms',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term fetched successfully.',
                'data' => $taxonomyTerm,
            ], 200);

        } catch (\Exception $e) {
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

            DB::transaction(function () use ($taxonomyTerm, $validated) {
                $customFields = $validated['custom_fields'] ?? [];

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

                if (!empty($updateData)) {
                    $taxonomyTerm->update($updateData);
                }

                if (!empty($customFields)) {
                    $this->saveCustomFieldValues($taxonomyTerm->id, 'taxonomy_term', $customFields);
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term updated successfully.',
                'data' => $taxonomyTerm->fresh()->load(['taxonomy', 'parent', 'meta.customField']),
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

                $taxonomyTerm->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
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

            DB::transaction(function () use ($request, &$deleted) {
                CustomFieldValue::where('entity_type', 'taxonomy_term')
                    ->whereIn('entity_id', $request->ids)
                    ->delete();

                $deleted = TaxonomyTerm::whereIn('id', $request->ids)->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomy terms deleted successfully.',
                'deleted_count' => $deleted ?? 0,
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
                'message' => 'Unable to delete selected taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function saveCustomFieldValues(int $entityId, string $entityType, array $fields): void
    {
        $service = app(CustomFieldValueService::class);
        $service->saveValues($entityId, $entityType, $fields);
    }
}