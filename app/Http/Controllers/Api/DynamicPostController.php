<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\CustomFieldValue;
use App\Models\TaxonomyTerm;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DynamicPostController extends Controller
{
    private array $postRelations = [
        'postType',
        'taxonomyTerms.taxonomy',
        'meta.customField',
    ];

    private array $singlePostRelations = [
        'postType',
        'taxonomyTerms.taxonomy',
        'meta.customField.options',
    ];

    private function successResponse(string $message, mixed $data = null, int $statusCode = 200, array $extra = []): JsonResponse
    {
        $response = array_merge([
            'status' => true,
            'message' => $message,
        ], $extra);

        if (!is_null($data)) {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode);
    }

    private function errorResponse(string $message, int $statusCode = 500, mixed $error = null, array $extra = []): JsonResponse
    {
        $response = array_merge([
            'status' => false,
            'message' => $message,
        ], $extra);

        if (!is_null($error)) {
            $response['error'] = $error;
        }

        return response()->json($response, $statusCode);
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return $this->errorResponse('Validation failed.', 422, null, [
            'errors' => $e->errors(),
        ]);
    }

    private function databaseErrorResponse(QueryException $e, string $message): JsonResponse
    {
        return $this->errorResponse($message, 500, $e->getMessage(), [
            'error_type' => 'database_error',
        ]);
    }

    private function findDynamicPost(int|string $id): ?DynamicPost
    {
        return DynamicPost::where('id', $id)->first();
    }

    private function normalizeIds(array|string|null $ids): array
    {
        if (is_null($ids) || $ids === '') {
            return [];
        }

        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        return collect($ids)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string', 'exists:post_types,slug'],
                'status' => ['nullable', Rule::in(['draft', 'published', 'private', 'archived'])],
                'search' => ['nullable', 'string', 'max:255'],
                'taxonomy_term_ids' => ['nullable'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $termIds = $this->normalizeIds($request->taxonomy_term_ids);

            if ($request->filled('taxonomy_term_ids')) {
                if (empty($termIds)) {
                    return $this->errorResponse('Invalid taxonomy term ids.', 422, 'Please provide valid taxonomy term ids.');
                }

                $existingTermIds = TaxonomyTerm::whereIn('id', $termIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                $missingTermIds = array_values(array_diff($termIds, $existingTermIds));

                if (!empty($missingTermIds)) {
                    return $this->errorResponse('Some taxonomy terms were not found.', 404, 'One or more taxonomy term ids do not exist.', [
                        'missing_taxonomy_term_ids' => $missingTermIds,
                    ]);
                }
            }

            $query = DynamicPost::query()
                ->with($this->postRelations)
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
                ->when(!empty($termIds), function ($q) use ($termIds) {
                    $q->whereHas('taxonomyTerms', function ($termQuery) use ($termIds) {
                        $termQuery->whereIn('taxonomy_terms.id', $termIds);
                    });
                })
                ->latest();

            $perPage = (int) $request->get('per_page', 15);

            return $this->successResponse('Dynamic posts fetched successfully.', $query->paginate($perPage));
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching dynamic posts.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic posts.', 500, $e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $this->validatePost($request);

            $slug = Str::slug($validated['title']);

            $slugExists = DynamicPost::where('post_type_id', $validated['post_type_id'])
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                return $this->errorResponse('Dynamic post slug already exists.', 422, null, [
                    'errors' => [
                        'slug' => [
                            'The generated slug "' . $slug . '" already exists for this post type.',
                        ],
                    ],
                ]);
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

            return $this->successResponse(
                'Dynamic post created successfully.',
                $post->load($this->postRelations),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while creating dynamic post.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to create dynamic post.', 500, $e->getMessage());
        }
    }

    public function show(int|string $dynamicPost): JsonResponse
    {
        try {
            $post = $this->findDynamicPost($dynamicPost);

            if (!$post) {
                return $this->errorResponse('Dynamic post not found.', 404, 'No dynamic post exists with this id.', [
                    'id' => $dynamicPost,
                ]);
            }

            $post->load($this->singlePostRelations);

            return $this->successResponse('Dynamic post fetched successfully.', $post);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching dynamic post.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic post.', 500, $e->getMessage());
        }
    }

    public function update(Request $request, int|string $dynamicPost): JsonResponse
    {
        try {
            $post = $this->findDynamicPost($dynamicPost);

            if (!$post) {
                return $this->errorResponse('Dynamic post not found.', 404, 'No dynamic post exists with this id.', [
                    'id' => $dynamicPost,
                ]);
            }

            if ($request->has('slug')) {
                $requestedSlug = Str::slug($request->slug);
                $oldSlug = $post->slug;
                $nameBasedSlug = $request->filled('title')
                    ? Str::slug($request->title)
                    : $oldSlug;

                if ($requestedSlug !== $oldSlug && $requestedSlug !== $nameBasedSlug) {
                    return $this->errorResponse('Validation failed.', 422, null, [
                        'errors' => [
                            'slug' => [
                                'Slug cannot be changed after creation.',
                            ],
                        ],
                    ]);
                }
            }

            $validated = $this->validatePost($request, true);

            if (isset($validated['title'])) {
                $newSlug = Str::slug($validated['title']);
                $postTypeId = $validated['post_type_id'] ?? $post->post_type_id;

                $slugExists = DynamicPost::where('post_type_id', $postTypeId)
                    ->where('slug', $newSlug)
                    ->where('id', '!=', $post->id)
                    ->exists();

                if ($slugExists) {
                    return $this->errorResponse('Dynamic post slug already exists.', 422, null, [
                        'errors' => [
                            'title' => [
                                'The generated slug "' . $newSlug . '" already exists for this post type.',
                            ],
                        ],
                    ]);
                }

                $validated['slug'] = $newSlug;
            }

            DB::transaction(function () use ($post, $validated) {
                $taxonomyTermIds = $validated['taxonomy_term_ids'] ?? null;
                $customFields = $validated['custom_fields'] ?? null;

                unset($validated['taxonomy_term_ids'], $validated['custom_fields']);

                $post->update($validated);

                if (is_array($taxonomyTermIds)) {
                    $this->syncTaxonomyTerms($post, $taxonomyTermIds);
                }

                if (is_array($customFields)) {
                    $this->saveCustomFieldValues($post->id, 'post', $customFields);
                }
            });

            return $this->successResponse(
                'Dynamic post updated successfully.',
                $post->fresh()->load($this->postRelations)
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while updating dynamic post.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to update dynamic post.', 500, $e->getMessage());
        }
    }

    public function destroy(int|string $dynamicPost): JsonResponse
    {
        try {
            $post = $this->findDynamicPost($dynamicPost);

            if (!$post) {
                return $this->errorResponse('Dynamic post not found.', 404, 'No dynamic post exists with this id.', [
                    'id' => $dynamicPost,
                ]);
            }

            $post->delete();

            return $this->successResponse('Dynamic post deleted successfully.');
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting dynamic post.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete dynamic post.', 500, $e->getMessage());
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer'],
            ]);

            $ids = array_values(array_unique($request->ids));

            $existingIds = DynamicPost::whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            $missingIds = array_values(array_diff($ids, $existingIds));

            if (!empty($missingIds)) {
                return $this->errorResponse('Some dynamic posts were not found.', 404, 'One or more ids do not exist.', [
                    'missing_ids' => $missingIds,
                ]);
            }

            $deleted = DB::transaction(function () use ($existingIds) {
                return DynamicPost::whereIn('id', $existingIds)->delete();
            });

            return $this->successResponse('Selected dynamic posts deleted successfully.', null, 200, [
                'deleted_count' => $deleted,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting selected dynamic posts.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete selected dynamic posts.', 500, $e->getMessage());
        }
    }

    public function byPostType(string $slug, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $postType = PostType::where('slug', $slug)->first();

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404, 'No post type exists with this slug.', [
                    'slug' => $slug,
                ]);
            }

            $perPage = (int) $request->get('per_page', 15);

            $posts = DynamicPost::where('post_type_id', $postType->id)
                ->with($this->postRelations)
                ->latest()
                ->paginate($perPage);

            return $this->successResponse('Dynamic posts fetched by post type successfully.', $posts, 200, [
                'post_type' => $postType,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching dynamic posts by post type.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic posts by post type.', 500, $e->getMessage());
        }
    }

    private function validatePost(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'post_type_id' => [$isUpdate ? 'sometimes' : 'required', 'required', 'exists:post_types,id'],
            'title' => [$isUpdate ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'featured_image_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'private', 'archived'])],
            'author_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'exists:dynamic_posts,id'],
            'published_at' => ['nullable', 'date'],
            'taxonomy_term_ids' => ['nullable', 'array'],
            'taxonomy_term_ids.*' => ['required_with:taxonomy_term_ids', 'integer', 'exists:taxonomy_terms,id'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*.custom_field_id' => ['required_with:custom_fields', 'integer', 'exists:custom_fields,id'],
            'custom_fields.*.custom_field_option_id' => ['nullable', 'integer', 'exists:custom_field_options,id'],
            'custom_fields.*.value_text' => ['nullable', 'string'],
            'custom_fields.*.value_string' => ['nullable', 'string'],
            'custom_fields.*.value_number' => ['nullable', 'numeric'],
            'custom_fields.*.value_date' => ['nullable', 'date'],
            'custom_fields.*.value_datetime' => ['nullable', 'date'],
            'custom_fields.*.value_json' => ['nullable'],
        ]);
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