<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxonomyTerm;
use App\Models\CustomFieldValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
                ->withCount('posts')
                ->when($request->filled('taxonomy_id'), function ($q) use ($request) {
                    $q->where('taxonomy_id', $request->taxonomy_id);
                })
                ->when($request->filled('parent_id'), function ($q) use ($request) {
                    $q->where('parent_id', $request->parent_id);
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
                'taxonomy_id' => ['required', 'exists:taxonomies,id'],
                'parent_id' => ['nullable', 'exists:taxonomy_terms,id'],
                'name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'boolean'],
                'custom_fields' => ['nullable', 'array'],
            ], [
                'taxonomy_id.required' => 'Taxonomy is required.',
                'name.required' => 'Taxonomy term name is required.',
            ]);

            $slug = Str::slug($validated['name']);

            $slugExists = TaxonomyTerm::where('taxonomy_id', $validated['taxonomy_id'])
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Taxonomy term slug already exists.',
                    'errors' => [
                        'slug' => [
                            '' . $slug . ' already exists in this taxonomy.',
                        ],
                    ],
                ], 422);
            }

            $term = DB::transaction(function () use ($validated, $slug) {
                $customFields = $validated['custom_fields'] ?? [];
                unset($validated['custom_fields']);

                $term = TaxonomyTerm::create([
                    'taxonomy_id' => $validated['taxonomy_id'],
                    'parent_id' => $validated['parent_id'] ?? null,
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'description' => $validated['description'] ?? null,
                    'sort_order' => $validated['sort_order'] ?? 0,
                    'status' => $validated['status'] ?? true,
                ]);

                $this->saveCustomFieldValues($term->id, 'taxonomy_term', $customFields);

                return $term;
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term created successfully.',
                'data' => $term->load(['taxonomy', 'parent', 'meta.customField']),
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
                'posts',
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
            if ($request->has('slug')) {
                $requestedSlug = Str::slug($request->slug);
                $oldSlug = $taxonomyTerm->slug;
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

            $validated = $request->validate([
                'taxonomy_id' => ['sometimes', 'required', 'exists:taxonomies,id'],
                'parent_id' => ['nullable', 'exists:taxonomy_terms,id'],
                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'description' => ['nullable', 'string'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'boolean'],
                'custom_fields' => ['nullable', 'array'],
            ]);

            DB::transaction(function () use ($taxonomyTerm, $validated) {
                $customFields = $validated['custom_fields'] ?? [];
                unset($validated['custom_fields']);

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

                if (array_key_exists('description', $validated)) {
                    $updateData['description'] = $validated['description'];
                }

                if (array_key_exists('sort_order', $validated)) {
                    $updateData['sort_order'] = $validated['sort_order'];
                }

                if (array_key_exists('status', $validated)) {
                    $updateData['status'] = $validated['status'];
                }

                $taxonomyTerm->update($updateData);

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
            $taxonomyTerm->delete();

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

            $deleted = TaxonomyTerm::whereIn('id', $request->ids)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomy terms deleted successfully.',
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
                'message' => 'Unable to delete selected taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function saveCustomFieldValues(int $entityId, string $entityType, array $fields): void
    {
        foreach ($fields as $field) {
            if (empty($field['custom_field_id'])) {
                continue;
            }

            CustomFieldValue::updateOrCreate(
                [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'custom_field_id' => $field['custom_field_id'],
                ],
                [
                    'custom_field_option_id' => $field['custom_field_option_id'] ?? null,
                    'value_text' => $field['value_text'] ?? null,
                    'value_string' => $field['value_string'] ?? null,
                    'value_number' => $field['value_number'] ?? null,
                    'value_date' => $field['value_date'] ?? null,
                    'value_datetime' => $field['value_datetime'] ?? null,
                    'value_json' => $field['value_json'] ?? null,
                ]
            );
        }
    }
}