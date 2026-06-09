<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\CustomFieldValue;
use App\Models\TaxonomyTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DynamicPostController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = DynamicPost::query()
                ->with(['postType', 'taxonomyTerms.taxonomy', 'meta.customField'])
                ->when($request->filled('post_type_id'), function ($q) use ($request) {
                    $q->where('post_type_id', $request->post_type_id);
                })
                ->when($request->filled('post_type_slug'), function ($q) use ($request) {
                    $q->whereHas('postType', function ($postTypeQuery) use ($request) {
                        $postTypeQuery->where('slug', $request->post_type_slug);
                    });
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->status);
                })
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;

                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('title', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('excerpt', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('taxonomy_term_ids'), function ($q) use ($request) {
                    $termIds = is_array($request->taxonomy_term_ids)
                        ? $request->taxonomy_term_ids
                        : explode(',', $request->taxonomy_term_ids);

                    $q->whereHas('taxonomyTerms', function ($termQuery) use ($termIds) {
                        $termQuery->whereIn('taxonomy_terms.id', $termIds);
                    });
                })
                ->latest();

            $perPage = (int) $request->get('per_page', 15);
            $perPage = $perPage > 100 ? 100 : $perPage;

            return response()->json([
                'status' => true,
                'message' => 'Dynamic posts fetched successfully.',
                'data' => $query->paginate($perPage),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch dynamic posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'post_type_id' => ['required', 'exists:post_types,id'],
                'title' => ['required', 'string', 'max:255'],
                'excerpt' => ['nullable', 'string'],
                'content' => ['nullable', 'string'],
                'featured_image_id' => ['nullable', 'integer'],
                'status' => ['nullable', Rule::in(['draft', 'published', 'private', 'archived'])],
                'author_id' => ['nullable', 'integer'],
                'parent_id' => ['nullable', 'exists:dynamic_posts,id'],
                'published_at' => ['nullable', 'date'],
                'taxonomy_term_ids' => ['nullable', 'array'],
                'taxonomy_term_ids.*' => ['exists:taxonomy_terms,id'],
                'custom_fields' => ['nullable', 'array'],
            ]);

            $slug = Str::slug($validated['title']);

            $slugExists = DynamicPost::where('post_type_id', $validated['post_type_id'])
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Dynamic post slug already exists.',
                    'errors' => [
                        'slug' => [
                            'The generated slug "' . $slug . '" already exists for this post type.',
                        ],
                    ],
                ], 422);
            }

            $post = DB::transaction(function () use ($validated, $slug) {
                $taxonomyTermIds = $validated['taxonomy_term_ids'] ?? [];
                $customFields = $validated['custom_fields'] ?? [];

                unset($validated['taxonomy_term_ids'], $validated['custom_fields']);

                $validated['slug'] = $slug;
                $validated['status'] = $validated['status'] ?? 'draft';

                $post = DynamicPost::create($validated);

                $this->syncTaxonomyTerms($post, $taxonomyTermIds);
                $this->saveCustomFieldValues($post->id, 'post', $customFields);

                return $post;
            });

            return response()->json([
                'status' => true,
                'message' => 'Dynamic post created successfully.',
                'data' => $post->load(['postType', 'taxonomyTerms.taxonomy', 'meta.customField']),
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
                'message' => 'Unable to create dynamic post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(DynamicPost $dynamicPost)
    {
        try {
            $dynamicPost->load([
                'postType',
                'taxonomyTerms.taxonomy',
                'meta.customField.options',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Dynamic post fetched successfully.',
                'data' => $dynamicPost,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch dynamic post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, DynamicPost $dynamicPost)
    {
        try {
            if ($request->has('slug')) {
                $requestedSlug = Str::slug($request->slug);
                $oldSlug = $dynamicPost->slug;
                $nameBasedSlug = $request->filled('title')
                    ? Str::slug($request->title)
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
                'post_type_id' => ['sometimes', 'required', 'exists:post_types,id'],
                'title' => ['sometimes', 'required', 'string', 'max:255'],
                'excerpt' => ['nullable', 'string'],
                'content' => ['nullable', 'string'],
                'featured_image_id' => ['nullable', 'integer'],
                'status' => ['nullable', Rule::in(['draft', 'published', 'private', 'archived'])],
                'author_id' => ['nullable', 'integer'],
                'parent_id' => ['nullable', 'exists:dynamic_posts,id'],
                'published_at' => ['nullable', 'date'],
                'taxonomy_term_ids' => ['nullable', 'array'],
                'taxonomy_term_ids.*' => ['exists:taxonomy_terms,id'],
                'custom_fields' => ['nullable', 'array'],
            ]);

            DB::transaction(function () use ($dynamicPost, $validated) {
                $taxonomyTermIds = $validated['taxonomy_term_ids'] ?? null;
                $customFields = $validated['custom_fields'] ?? null;

                unset($validated['taxonomy_term_ids'], $validated['custom_fields']);

                $dynamicPost->update($validated);

                if (is_array($taxonomyTermIds)) {
                    $this->syncTaxonomyTerms($dynamicPost, $taxonomyTermIds);
                }

                if (is_array($customFields)) {
                    $this->saveCustomFieldValues($dynamicPost->id, 'post', $customFields);
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'Dynamic post updated successfully.',
                'data' => $dynamicPost->fresh()->load(['postType', 'taxonomyTerms.taxonomy', 'meta.customField']),
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
                'message' => 'Unable to update dynamic post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(DynamicPost $dynamicPost)
    {
        try {
            $dynamicPost->delete();

            return response()->json([
                'status' => true,
                'message' => 'Dynamic post deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete dynamic post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'exists:dynamic_posts,id'],
            ]);

            DB::beginTransaction();

            $deleted = DynamicPost::whereIn('id', $request->ids)->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected dynamic posts deleted successfully.',
                'deleted_count' => $deleted,
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
                'message' => 'Unable to delete selected dynamic posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function byPostType(string $slug, Request $request)
    {
        try {
            $postType = PostType::where('slug', $slug)->firstOrFail();

            $posts = DynamicPost::where('post_type_id', $postType->id)
                ->with(['taxonomyTerms.taxonomy', 'meta.customField'])
                ->latest()
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => true,
                'message' => 'Dynamic posts fetched by post type successfully.',
                'post_type' => $postType,
                'data' => $posts,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch dynamic posts by post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function syncTaxonomyTerms(DynamicPost $post, array $taxonomyTermIds): void
    {
        $syncData = [];

        if (!empty($taxonomyTermIds)) {
            $terms = TaxonomyTerm::whereIn('id', $taxonomyTermIds)->get();

            foreach ($terms as $term) {
                $syncData[$term->id] = [
                    'taxonomy_id' => $term->taxonomy_id,
                ];
            }
        }

        $post->taxonomyTerms()->sync($syncData);
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