<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxonomyController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Taxonomy::query()
                ->with('creator')
                ->withCount(['terms'])
                ->withCount(['customFieldGroups as custom_fields_count'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->input('search');
                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
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
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc');

            $perPage = (int) $request->get('per_page', 15);
            $perPage = $perPage > 100 ? 100 : $perPage;

            $taxonomies = $query->paginate($perPage);

            $taxonomies->getCollection()->transform(function ($taxonomy) {
                $formatted = $this->formatTaxonomy($taxonomy);
                $formatted['terms_count'] = $taxonomy->terms_count ?? 0;
                $formatted['custom_fields_count'] = $taxonomy->custom_fields_count ?? 0;
                return $formatted;
            });

            return response()->json([
                'status' => true,
                'message' => 'Taxonomies fetched successfully.',
                'data' => $taxonomies,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch taxonomies.',
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
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'hierarchical' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'post_type_ids' => ['nullable', 'array'],
                'post_type_ids.*' => ['integer', 'exists:post_types,id'],
            ]);

            $slug = Str::slug($validated['name']);
            if (Taxonomy::where('slug', $slug)->exists()) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Taxonomy slug already exists.',
                    'errors' => ['slug' => ["{$slug} already exists. Please use a different taxonomy name."]],
                ], 422);
            }

            $taxonomy = Taxonomy::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'is_default' => $validated['is_default'] ?? false,
                'hierarchical' => $validated['hierarchical'] ?? false,
                'status' => $validated['status'] ?? true,
                'created_by' => Auth::id(),
                'sort_order' => $validated['sort_order'] ?? $this->getNextSortOrder(),
            ]);
            if (array_key_exists('post_type_ids', $validated)) {
                $this->syncPostTypes($taxonomy, $validated['post_type_ids'] ?? []);
            }
            DB::commit();
            $taxonomy->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy created successfully.',
                'data' => $this->formatTaxonomy($taxonomy),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Unable to create taxonomy.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Taxonomy $taxonomy)
    {
        try {
            $taxonomy->load([
                'creator',
                'postTypes',
                'terms',
                'customFieldGroups.fields.options',
                'customFieldGroups.fields.repeaters.options'
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy fetched successfully.',
                'data' => $this->formatTaxonomy($taxonomy),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch taxonomy.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, Taxonomy $taxonomy)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'hierarchical' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'post_type_ids' => ['nullable', 'array'],
                'post_type_ids.*' => ['integer', 'exists:post_types,id'],
            ]);

            $updateData = [];
            foreach (['name', 'description', 'is_default', 'hierarchical', 'status', 'sort_order'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = $validated[$field] ?? ($field === 'sort_order' ? $this->getNextSortOrder() : null);
                }
            }

            $taxonomy->update($updateData);
            if (array_key_exists('post_type_ids', $validated)) {
                $this->syncPostTypes($taxonomy, $validated['post_type_ids'] ?? []);
            }
            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy updated successfully.',
                'data' => $this->formatTaxonomy($taxonomy->fresh()->load('creator')),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Unable to update taxonomy.', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Taxonomy $taxonomy)
    {
        try {
            if ($taxonomy->is_default) {
                return response()->json(['status' => false, 'message' => 'Default taxonomy cannot be deleted.'], 403);
            }
            $taxonomy->delete();
            return response()->json(['status' => true, 'message' => 'Taxonomy deleted successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to delete taxonomy.', 'error' => $e->getMessage()], 500);
        }
    }


    public function terms(Taxonomy $taxonomy)
    {
        try {
            $terms = $taxonomy->terms()->orderBy('sort_order', 'asc')->get();
            return response()->json(['status' => true, 'message' => 'Taxonomy terms fetched successfully.', 'data' => $terms], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to fetch taxonomy terms.', 'error' => $e->getMessage()], 500);
        }
    }

    public function fields(Taxonomy $taxonomy)
    {
        try {
            $fields = $taxonomy->customFieldGroups()->with(['fields.options', 'fields.repeaters.options'])->get();
            return response()->json(['status' => true, 'message' => 'Taxonomy fields fetched successfully.', 'data' => $fields], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to fetch taxonomy fields.', 'error' => $e->getMessage()], 500);
        }
    }

    private function getNextSortOrder(): int
    {
        return (Taxonomy::max('sort_order') ?? 0) + 1;
    }

    private function formatTaxonomy($taxonomy): array
    {
        $creator = $taxonomy->creator;

        $postTypes = $taxonomy->relationLoaded('postTypes')
            ? $taxonomy->postTypes
            : collect();

        return [
            'id' => $taxonomy->id,
            'name' => $taxonomy->name,
            'slug' => $taxonomy->slug,
            'description' => $taxonomy->description,
            'is_default' => (bool) $taxonomy->is_default,
            'hierarchical' => (bool) $taxonomy->hierarchical,
            'status' => (bool) $taxonomy->status,
            'sort_order' => $taxonomy->sort_order,

            'post_type_ids' => $taxonomy->relationLoaded('postTypes')
                ? $postTypes->pluck('id')->map(fn($id) => (int) $id)->values()->toArray()
                : [],

            'post_types' => $taxonomy->relationLoaded('postTypes')
                ? $postTypes->map(fn($postType) => [
                    'id' => $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'status' => (bool) $postType->status,
                    'sort_order' => $postType->pivot->sort_order ?? 0,
                    'pivot_status' => isset($postType->pivot->status)
                        ? (bool) $postType->pivot->status
                        : true,
                ])->values()->toArray()
                : [],

            'created_by' => $taxonomy->created_by,

            'created_by_user' => $creator ? [
                'id' => $creator->id,
                'name' => trim(($creator->first_name ?? '') . ' ' . ($creator->last_name ?? '')),
                'email' => $creator->email ?? null,
            ] : null,

            'created_at' => $taxonomy->created_at,
            'updated_at' => $taxonomy->updated_at,
        ];
    }
    // ---------------- Soft delete / trash / restore ----------------

    public function trash(Request $request)
    {
        $query = Taxonomy::onlyTrashed()->with('creator')
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', '%' . $request->search . '%')->orWhere('slug', 'like', '%' . $request->search . '%'))
            ->orderBy('deleted_at', 'desc');

        $perPage = min((int)$request->get('per_page', 15), 100);
        $taxonomies = $query->paginate($perPage);
        $taxonomies->getCollection()->transform(fn($t) => $this->formatTaxonomy($t));

        return response()->json([
            'status' => true,
            'message' => 'Trash taxonomies fetched successfully.',
            'data' => $taxonomies
        ], 200);
    }

    public function restore($id)
    {
        try {
            $taxonomy = Taxonomy::onlyTrashed()->find($id);
            if (!$taxonomy) return response()->json(['status' => false, 'message' => 'Taxonomy not found in trash.'], 404);

            $taxonomy->restore();
            $taxonomy->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy restored successfully.',
                'data' => $this->formatTaxonomy($taxonomy)
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to restore taxonomy.', 'error' => $e->getMessage()], 500);
        }
    }

    public function forceDelete($id)
    {
        try {
            $taxonomy = Taxonomy::onlyTrashed()->find($id);
            if (!$taxonomy) return response()->json(['status' => false, 'message' => 'Taxonomy not found in trash.'], 404);
            if ($taxonomy->is_default) return response()->json(['status' => false, 'message' => 'Default taxonomy cannot be permanently deleted.'], 403);

            $taxonomy->forceDelete();
            return response()->json(['status' => true, 'message' => 'Taxonomy permanently deleted successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Unable to permanently delete taxonomy.', 'error' => $e->getMessage()], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:taxonomies,id']
        ]);

        $deletedCount = Taxonomy::whereIn('id', $validated['ids'])->delete();
        return response()->json([
            'status' => true,
            'message' => 'Selected taxonomies deleted successfully.',
            'deleted_count' => $deletedCount
        ], 200);
    }

    public function bulkForceDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:taxonomies,id']
        ]);

        $forceDeleted = Taxonomy::onlyTrashed()->whereIn('id', $validated['ids'])
            ->get()
            ->each(function ($t) {
                if (!$t->is_default) $t->forceDelete();
            });

        return response()->json([
            'status' => true,
            'message' => 'Selected taxonomies permanently deleted successfully.',
            'deleted_count' => $forceDeleted->count()
        ], 200);
    }

    public function bulkRestore(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:taxonomies,id']
        ]);

        $restoredCount = Taxonomy::onlyTrashed()->whereIn('id', $validated['ids'])->restore();
        return response()->json([
            'status' => true,
            'message' => 'Selected taxonomies restored successfully.',
            'restored_count' => $restoredCount
        ], 200);
    }
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
}
