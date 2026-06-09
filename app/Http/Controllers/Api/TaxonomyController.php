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
                ->withCount(['terms', 'customFields'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;

                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('is_default'), function ($q) use ($request) {
                    $q->where('is_default', filter_var($request->is_default, FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('hierarchical'), function ($q) use ($request) {
                    $q->where('hierarchical', filter_var($request->hierarchical, FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
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
        try {
            $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'hierarchical' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ], [
                'name.required' => 'Taxonomy name is required.',
                'name.max' => 'Taxonomy name cannot be greater than 150 characters.',
            ]);

            $slug = Str::slug($request->name);

            $slugExists = Taxonomy::where('slug', $slug)->exists();

            if ($slugExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Taxonomy slug already exists.',
                    'errors' => [
                        'slug' => [
                            '' . $slug . ' already exists. Please use a different taxonomy name.',
                        ],
                    ],
                ], 422);
            }

            DB::beginTransaction();

            $taxonomy = Taxonomy::create([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'is_default' => $request->boolean('is_default', false),
                'hierarchical' => $request->boolean('hierarchical', false),
                'status' => $request->boolean('status', true),
                'created_by' => Auth::id(),
                'sort_order' => $request->filled('sort_order')
                    ? (int) $request->sort_order
                    : $this->getNextSortOrder(),
            ]);

            DB::commit();

            $taxonomy->load('creator');
            $taxonomy->load('creator');

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy created successfully.',
                'data' => $this->formatTaxonomy($taxonomy),
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
                'message' => 'Unable to create taxonomy.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Taxonomy $taxonomy)
    {
        try {
            $taxonomy->load([
                'creator',
                'activeTerms',
                'activeCustomFields.options',
                'activeCustomFields.repeaters.options',
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
        try {
            if ($request->has('slug')) {
                $requestedSlug = Str::slug($request->slug);
                $oldSlug = $taxonomy->slug;
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
                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'description' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
                'hierarchical' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ], [
                'name.required' => 'Taxonomy name is required.',
                'name.max' => 'Taxonomy name cannot be greater than 150 characters.',
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

            if ($request->has('hierarchical')) {
                $updateData['hierarchical'] = $request->boolean('hierarchical');
            }

            if ($request->has('status')) {
                $updateData['status'] = $request->boolean('status');
            }

            if ($request->has('sort_order')) {
                $updateData['sort_order'] = (int) $request->sort_order;
            }

            $taxonomy->update($updateData);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy updated successfully.',
                'data' => $this->formatTaxonomy($taxonomy->fresh()->load('creator')),
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
                'message' => 'Unable to update taxonomy.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Taxonomy $taxonomy)
    {
        try {
            if ($taxonomy->is_default) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default taxonomy cannot be deleted.',
                ], 403);
            }

            $taxonomy->delete();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete taxonomy.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'exists:taxonomies,id'],
            ]);

            $defaultCount = Taxonomy::whereIn('id', $request->ids)
                ->where('is_default', true)
                ->count();

            if ($defaultCount > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Default taxonomies cannot be deleted.',
                ], 403);
            }

            $deleted = Taxonomy::whereIn('id', $request->ids)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Selected taxonomies deleted successfully.',
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
                'message' => 'Unable to delete selected taxonomies.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function terms(Taxonomy $taxonomy)
    {
        try {
            $terms = $taxonomy->activeTerms()
                ->with('children')
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy terms fetched successfully.',
                'data' => $terms,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function fields(Taxonomy $taxonomy)
    {
        try {
            $fields = $taxonomy->activeCustomFields()
                ->with(['options', 'repeaters.options'])
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy fields fetched successfully.',
                'data' => $fields,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch taxonomy fields.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getNextSortOrder(): int
    {
        $maxSortOrder = Taxonomy::max('sort_order');

        if (!$maxSortOrder || $maxSortOrder < 5) {
            return 6;
        }

        return $maxSortOrder + 1;
    }
    private function formatTaxonomy($taxonomy): array
    {
        $creator = $taxonomy->creator;

        return [
            'id' => $taxonomy->id,
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
                'name' => $this->getUserFullName($creator),
                'email' => $creator->email ?? null,
                'role' => $this->getUserRoleName($creator),
            ] : null,

            'created_at' => $taxonomy->created_at,
            'updated_at' => $taxonomy->updated_at,
            'deleted_at' => $taxonomy->deleted_at ?? null,
        ];
    }

    private function getUserFullName($user): ?string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        if (!empty($fullName)) {
            return $fullName;
        }

        return $user->name ?? $user->user_name ?? $user->email ?? null;
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

        if (isset($user->role_id)) {
            try {
                $role = \Illuminate\Support\Facades\DB::table('roles')
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
