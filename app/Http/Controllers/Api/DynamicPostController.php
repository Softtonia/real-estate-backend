<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PostType;
use App\Models\TaxonomyTerm;
use App\Services\CustomFieldValueService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Models\SiteSetting;

class DynamicPostController extends Controller
{
    private array $postRelations = [
        'postType',
        'parent:id,post_type_id,title,slug,status,live_status',
        'taxonomyTerms.taxonomy',
        'meta.customField.options',
        'meta.customField.repeaters.options',
    ];

    private array $singlePostRelations = [
        'postType',
        'parent:id,post_type_id,title,slug,status,live_status',
        'children:id,post_type_id,parent_id,title,slug,status,live_status',
        'taxonomyTerms.taxonomy',
        'meta.customField.options',
        'meta.customField.repeaters.options',
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
            $ids = trim($ids);

            if ($ids === '') {
                return [];
            }

            $decoded = json_decode($ids, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = str_contains($ids, ',') ? explode(',', $ids) : [$ids];
            }
        }

        return collect($ids)
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
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
                'post_type' => ['nullable', 'string'],
                'status' => ['nullable', 'string'],
                'live_status' => ['nullable', 'string'],
                'parent_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
                'search' => ['nullable', 'string', 'max:255'],
                'taxonomy_term_ids' => ['nullable'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $termIds = $this->normalizeIds($request->taxonomy_term_ids);

            if ($request->filled('taxonomy_term_ids')) {
                $this->assertTaxonomyTermsExist($termIds);
            }

            $query = DynamicPost::query()
                ->with($this->postRelations)
                ->when($request->filled('post_type_id'), function ($q) use ($request) {
                    $q->where('post_type_id', $request->post_type_id);
                })
                ->when($request->filled('post_type_slug') || $request->filled('post_type'), function ($q) use ($request) {
                    $postTypeValue = $request->post_type_slug ?: $request->post_type;

                    $q->whereHas('postType', function ($postTypeQuery) use ($postTypeValue) {
                        $postTypeQuery->where('slug', $postTypeValue)
                            ->orWhere('name', $postTypeValue);
                    });
                })
                ->when($request->filled('status') && !in_array($request->status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('status', $request->status);
                })
                ->when($request->filled('live_status') && !in_array($request->live_status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('live_status', $request->live_status);
                })
                ->when($request->filled('parent_id'), function ($q) use ($request) {
                    $q->where('parent_id', $request->parent_id);
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
                ->orderBy('sort_order', 'asc')
                ->latest();

            $perPage = (int) $request->get('per_page', 15);
            $posts = $query->paginate($perPage);

            $posts->getCollection()->transform(fn($post) => $this->formatDynamicPostResponse($post));

            return $this->successResponse('Dynamic posts fetched successfully.', $posts);
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

            $postType = PostType::with('taxonomies')->find($validated['post_type_id']);

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $validated = $this->prepareBaseMediaForSave($request, $validated, $postType);

            $submittedTaxonomies = $validated['taxonomies'] ?? [];
            $taxonomyTermIds = $this->normalizeSubmittedTaxonomyTermIds($validated);
            $customFields = $this->prepareCustomFieldsForSave($request, $validated, $postType);

            $this->validateSubmittedTaxonomyGroups($postType, $submittedTaxonomies);
            $this->validateTaxonomyTermsForPostType($postType, $taxonomyTermIds);
            $this->validateDependentTaxonomySelections($taxonomyTermIds);
            $this->validateSubmittedCustomFieldsForPostType($postType, $taxonomyTermIds, $customFields);

            $slug = !empty($validated['slug'])
                ? Str::slug($validated['slug'])
                : Str::slug($validated['title']);

            $slugExists = DynamicPost::where('post_type_id', $validated['post_type_id'])
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                return $this->errorResponse('Dynamic post slug already exists.', 422, null, [
                    'errors' => [
                        'slug' => [
                            $slug . ' already exists for this post type.',
                        ],
                    ],
                ]);
            }

            $post = DB::transaction(function () use ($validated, $slug, $taxonomyTermIds, $customFields, $postType) {
                $postData = $this->dynamicPostPayloadForDatabase($validated);

                $postData['slug'] = $slug;
                $postData['listing_code'] = $this->generateDynamicPostListingCode($postType);
                $postData['status'] = $postData['status'] ?? 'draft';
                $postData['author_id'] = $postData['author_id'] ?? Auth::id();

                if ($postData['status'] === 'published' && empty($postData['published_at'])) {
                    $postData['published_at'] = now();
                }

                $post = DynamicPost::create($postData);

                $this->syncTaxonomyTerms($post, $taxonomyTermIds);
                $this->saveCustomFieldValues($post->id, 'post', $customFields);

                return $post;
            });

            return $this->successResponse(
                'Dynamic post created successfully.',
                $this->formatDynamicPostResponse($post->fresh()->load($this->postRelations)),
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

            return $this->successResponse('Dynamic post fetched successfully.', $this->formatDynamicPostResponse($post));
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

            $validated = $this->validatePost($request, true);

            $postTypeId = $validated['post_type_id'] ?? $post->post_type_id;
            $postType = PostType::with('taxonomies')->find($postTypeId);

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $validated = $this->prepareBaseMediaForSave($request, $validated, $postType, $post);

            $hasTaxonomyPayload = array_key_exists('taxonomies', $validated) || array_key_exists('taxonomy_term_ids', $validated);
            $submittedTaxonomies = $validated['taxonomies'] ?? [];

            $taxonomyTermIds = $hasTaxonomyPayload
                ? $this->normalizeSubmittedTaxonomyTermIds($validated)
                : null;

            $customFields = array_key_exists('custom_fields', $validated)
                ? $this->prepareCustomFieldsForSave($request, $validated, $postType, $post)
                : null;

            if (is_array($customFields)) {
                $customFields = $this->appendMissingMediaCustomFieldDeletes($post, $customFields);
            }

            if ($hasTaxonomyPayload) {
                $this->validateSubmittedTaxonomyGroups($postType, $submittedTaxonomies);
                $this->validateTaxonomyTermsForPostType($postType, $taxonomyTermIds);
                $this->validateDependentTaxonomySelections($taxonomyTermIds);
            }

            if (is_array($customFields)) {
                $effectiveTermIds = is_array($taxonomyTermIds)
                    ? $taxonomyTermIds
                    : $post->taxonomyTerms()->pluck('taxonomy_terms.id')->map(fn($id) => (int) $id)->toArray();

                $this->validateSubmittedCustomFieldsForPostType($postType, $effectiveTermIds, $customFields);
            }

            $newSlug = $post->slug;

            if (array_key_exists('slug', $validated) && !empty($validated['slug'])) {
                $newSlug = Str::slug($validated['slug']);
            } elseif (array_key_exists('title', $validated)) {
                $newSlug = Str::slug($validated['title']);
            }

            $slugExists = DynamicPost::where('post_type_id', $postTypeId)
                ->where('slug', $newSlug)
                ->where('id', '!=', $post->id)
                ->exists();

            if ($slugExists) {
                return $this->errorResponse('Dynamic post slug already exists.', 422, null, [
                    'errors' => [
                        'slug' => [
                            $newSlug . ' already exists for this post type.',
                        ],
                    ],
                ]);
            }

            DB::transaction(function () use ($post, $validated, $newSlug, $taxonomyTermIds, $customFields) {
                $postData = $this->dynamicPostPayloadForDatabase($validated);

                $postData['slug'] = $newSlug;

                if (($postData['status'] ?? null) === 'published' && empty($postData['published_at']) && empty($post->published_at)) {
                    $postData['published_at'] = now();
                }

                $post->update($postData);

                if (is_array($taxonomyTermIds)) {
                    $this->syncTaxonomyTerms($post, $taxonomyTermIds);
                }

                if (is_array($customFields)) {
                    $this->saveCustomFieldValues($post->id, 'post', $customFields);
                }
            });

            return $this->successResponse(
                'Dynamic post updated successfully.',
                $this->formatDynamicPostResponse($post->fresh()->load($this->postRelations))
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

            DB::transaction(function () use ($post) {
                CustomFieldValue::where('entity_type', 'post')
                    ->where('entity_id', $post->id)
                    ->delete();

                $post->taxonomyTerms()->detach();
                $post->delete();
            });

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

            $ids = array_values(array_unique(array_map('intval', $request->ids)));

            $existingIds = DynamicPost::whereIn('id', $ids)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $missingIds = array_values(array_diff($ids, $existingIds));

            if (!empty($missingIds)) {
                return $this->errorResponse('Some dynamic posts were not found.', 404, 'One or more ids do not exist.', [
                    'missing_ids' => $missingIds,
                ]);
            }

            $deleted = DB::transaction(function () use ($existingIds) {
                CustomFieldValue::where('entity_type', 'post')
                    ->whereIn('entity_id', $existingIds)
                    ->delete();

                DB::table('post_taxonomy_terms')
                    ->whereIn('dynamic_post_id', $existingIds)
                    ->delete();

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
                'status' => ['nullable', 'string'],
                'live_status' => ['nullable', 'string'],
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
                ->when($request->filled('status') && !in_array($request->status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('status', $request->status);
                })
                ->when($request->filled('live_status') && !in_array($request->live_status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('live_status', $request->live_status);
                })
                ->orderBy('sort_order', 'asc')
                ->latest()
                ->paginate($perPage);

            $posts->getCollection()->transform(fn($post) => $this->formatDynamicPostResponse($post));

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

    public function formOptions(Request $request, int|string $postType): JsonResponse
    {
        try {
            $request->validate([
                'taxonomy_term_ids' => ['nullable'],
            ]);

            $selectedTermIds = $this->normalizeIds($request->taxonomy_term_ids);

            if ($request->filled('taxonomy_term_ids')) {
                $this->assertTaxonomyTermsExist($selectedTermIds);
            }

            $postTypeData = PostType::with([
                'taxonomies' => function ($taxonomyQuery) {
                    $taxonomyQuery
                        ->select(
                            'taxonomies.id',
                            'taxonomies.name',
                            'taxonomies.slug',
                            'taxonomies.status',
                            'taxonomies.sort_order'
                        )
                        ->wherePivot('status', true)
                        ->where('taxonomies.status', true)
                        ->orderBy('post_type_taxonomies.sort_order', 'asc')
                        ->orderBy('taxonomies.id', 'asc');
                },
            ])
                ->where(function ($q) use ($postType) {
                    if (is_numeric($postType)) {
                        $q->where('id', (int) $postType);
                    }

                    $q->orWhere('slug', $postType)
                        ->orWhere('name', $postType);
                })
                ->first();

            if (!$postTypeData) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $supports = $this->normalizePostTypeSupports($postTypeData->supports ?? []);

            $taxonomyFields = $supports['taxonomies']
                ? $this->buildTaxonomyFields($postTypeData, $selectedTermIds)
                : collect();

            $customFields = $supports['custom_fields']
                ? $this->resolveVisibleCustomFields($postTypeData->id, $selectedTermIds)->map(fn($field) => $this->formatCustomField($field))->values()
                : collect();

            $relationshipPostTypes = $this->buildRelationshipPostTypeFields($postTypeData);

            return $this->successResponse(
                'Dynamic post form options fetched successfully.',
                [
                    'post_type' => [
                        'id' => $postTypeData->id,
                        'name' => $postTypeData->name,
                        'slug' => $postTypeData->slug,
                    ],
                    'supports' => $supports,
                    'base_fields' => $this->baseFields($supports),
                    'relationship_post_types' => $relationshipPostTypes,
                    'taxonomy_fields' => $taxonomyFields,
                    'custom_fields_count' => $customFields->count(),
                    'custom_fields' => $customFields,
                    'status_options' => $this->statusOptions(),
                    'live_status_options' => $this->liveStatusOptions(),
                ]
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic post form options.', 500, $e->getMessage());
        }
    }

    private function buildRelationshipPostTypeFields(PostType $postType)
    {
        $relatedPostTypes = $postType->activeRelatedPostTypes()->get();

        if ($relatedPostTypes->isEmpty()) {
            return collect();
        }

        return $relatedPostTypes->map(function ($relatedPostType) {
            $options = DynamicPost::query()
                ->select('id', 'post_type_id', 'title', 'slug', 'status', 'live_status')
                ->where('post_type_id', $relatedPostType->id)
                ->whereIn('status', ['draft', 'published', 'private'])
                ->orderBy('title', 'asc')
                ->get();

            return [
                'post_type_id' => $relatedPostType->id,
                'name' => $relatedPostType->name,
                'slug' => $relatedPostType->slug,
                'field_label' => $relatedPostType->name,
                'field_name' => 'parent_id',
                'selection_type' => 'single',
                'multiple' => false,
                'options' => $options->map(fn($post) => [
                    'id' => $post->id,
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'status' => $post->status,
                    'live_status' => $post->live_status,
                ])->values(),
            ];
        })->values();
    }

    public function customFieldsByPostType(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['required', 'integer', 'exists:post_types,id'],
                'taxonomy_term_ids' => ['nullable'],
            ]);

            $postType = PostType::find($request->post_type_id);

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

            if (!$supports['custom_fields']) {
                return $this->successResponse('Custom fields fetched successfully.', [
                    'post_type_id' => (int) $request->post_type_id,
                    'taxonomy_term_ids' => [],
                    'custom_fields_count' => 0,
                    'custom_fields' => [],
                ]);
            }

            $termIds = $this->normalizeIds($request->taxonomy_term_ids);

            if ($request->filled('taxonomy_term_ids')) {
                $this->assertTaxonomyTermsExist($termIds);
            }

            $fields = $this->resolveVisibleCustomFields((int) $request->post_type_id, $termIds)
                ->map(fn($field) => $this->formatCustomField($field))
                ->values();

            return $this->successResponse('Custom fields fetched successfully.', [
                'post_type_id' => (int) $request->post_type_id,
                'taxonomy_term_ids' => $termIds,
                'custom_fields_count' => $fields->count(),
                'custom_fields' => $fields,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom fields.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom fields.', 500, $e->getMessage());
        }
    }

    public function resolveCustomFieldsForCreate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'post_type_id' => ['required', 'integer', 'exists:post_types,id'],
                'taxonomy_term_ids' => ['nullable'],
                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*.taxonomy_id' => ['required_with:taxonomies', 'integer', 'exists:taxonomies,id'],
                'taxonomies.*.taxonomy_term_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
                'taxonomies.*.taxonomy_term_ids' => ['nullable', 'array'],
                'taxonomies.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
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

            $postType = PostType::with('taxonomies')->find($validated['post_type_id']);

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $termIds = $this->normalizeSubmittedTaxonomyTermIds($validated);
            $submittedTaxonomies = $validated['taxonomies'] ?? [];

            $this->validateSubmittedTaxonomyGroups($postType, $submittedTaxonomies);
            $this->validateTaxonomyTermsForPostType($postType, $termIds);
            $this->validateDependentTaxonomySelections($termIds);

            $fields = $this->resolveVisibleCustomFields((int) $validated['post_type_id'], $termIds);

            $selectedValues = [];

            foreach ($validated['custom_fields'] ?? [] as $submittedField) {
                $fieldId = (int) $submittedField['custom_field_id'];
                $field = $fields->firstWhere('id', $fieldId);

                if (!$field) {
                    continue;
                }

                $value = $submittedField['value_string']
                    ?? $submittedField['value_text']
                    ?? $submittedField['value_number']
                    ?? $submittedField['value_date']
                    ?? $submittedField['value_datetime']
                    ?? $submittedField['value_json']
                    ?? null;

                if (!empty($submittedField['custom_field_option_id'])) {
                    $option = $field->options->firstWhere('id', (int) $submittedField['custom_field_option_id']);

                    if ($option) {
                        $value = $option->value;
                    }
                }

                $selectedValues[$field->field_name_slug] = $value;
                $selectedValues[(string) $field->id] = $value;
            }

            $visibleFields = [];
            $hiddenFields = [];

            foreach ($fields as $field) {
                $isVisible = $this->isConditionalFieldVisible($field->conditional_rules, $selectedValues);
                $fieldData = $this->formatCustomField($field);

                if ($isVisible) {
                    $visibleFields[] = $fieldData;
                } else {
                    $fieldData['hidden_reason'] = 'Conditional rule not matched.';
                    $hiddenFields[] = $fieldData;
                }
            }

            $allowedFieldIds = collect($visibleFields)->pluck('id')->values()->toArray();

            $submittedFieldIds = collect($validated['custom_fields'] ?? [])
                ->pluck('custom_field_id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            $unsupportedFieldIds = array_values(array_diff($submittedFieldIds, $allowedFieldIds));

            return $this->successResponse('Custom fields resolved successfully.', [
                'post_type_id' => (int) $validated['post_type_id'],
                'taxonomy_term_ids' => $termIds,
                'selected_values' => $selectedValues,
                'visible_custom_fields_count' => count($visibleFields),
                'hidden_custom_fields_count' => count($hiddenFields),
                'allowed_custom_field_ids' => $allowedFieldIds,
                'submitted_custom_field_ids' => $submittedFieldIds,
                'unsupported_custom_field_ids' => $unsupportedFieldIds,
                'visible_custom_fields' => $visibleFields,
                'hidden_custom_fields' => $hiddenFields,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while resolving custom fields.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to resolve custom fields.', 500, $e->getMessage());
        }
    }
    private function generateDynamicPostListingCode(PostType $postType): string
    {
        $prefix = $this->getDynamicPostPrefix($postType);

        $lastCode = DynamicPost::where('post_type_id', $postType->id)
            ->whereNotNull('listing_code')
            ->where('listing_code', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('listing_code');

        $nextNumber = 1;

        if (!empty($lastCode) && preg_match('/-(\d+)$/', $lastCode, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        return $prefix . '-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    private function getDynamicPostPrefix(PostType $postType): string
    {
        $setting = SiteSetting::first();

        $slug = Str::slug($postType->slug ?? $postType->name ?? '', '-');
        $name = Str::slug($postType->name ?? '', '-');

        if (str_contains($slug, 'property') || str_contains($name, 'property')) {
            return $this->cleanPrefix($setting?->property_prefix ?: 'PRP');
        }

        if (str_contains($slug, 'developer') || str_contains($name, 'developer')) {
            return $this->cleanPrefix($setting?->developer_prefix ?: 'DEV');
        }

        if (str_contains($slug, 'project') || str_contains($name, 'project')) {
            return $this->cleanPrefix($setting?->project_prefix ?: 'PRJ');
        }

        return $this->cleanPrefix(strtoupper(substr(Str::slug($postType->name ?? 'DYN', ''), 0, 4)) ?: 'DYN');
    }

    private function cleanPrefix(?string $prefix): string
    {
        $prefix = strtoupper(trim((string) $prefix));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix);

        return $prefix ?: 'DYN';
    }
    private function validatePost(Request $request, bool $isUpdate = false): array
    {
        $this->cleanEmptyUploadInputs($request);

        return $request->validate([
            'post_type_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:post_types,id'],
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'featured_image_id' => ['nullable', 'integer'],
            'featured_image' => ['nullable'],
            'gallery_image_ids' => ['nullable', 'array'],
            'gallery_image_ids.*' => ['integer'],
            'gallery_images' => ['nullable'],
            'gallery_images.*' => ['nullable'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'private', 'archived'])],
            'live_status' => ['nullable', Rule::in(['approve', 'reject', 'under_review', 'disapprove', 'modify_review', 'submit'])],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'parent_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'published_at' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'taxonomy_term_ids' => ['nullable'],
            'taxonomies' => ['nullable', 'array'],
            'taxonomies.*.taxonomy_id' => ['required_with:taxonomies', 'integer', 'exists:taxonomies,id'],
            'taxonomies.*.taxonomy_term_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'taxonomies.*.taxonomy_term_ids' => ['nullable', 'array'],
            'taxonomies.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*.custom_field_id' => ['required_with:custom_fields', 'integer', 'exists:custom_fields,id'],
            'custom_fields.*.custom_field_option_id' => ['nullable', 'integer', 'exists:custom_field_options,id'],
            'custom_fields.*.value_text' => ['nullable'],
            'custom_fields.*.value_string' => ['nullable'],
            'custom_fields.*.value_number' => ['nullable', 'numeric'],
            'custom_fields.*.value_date' => ['nullable', 'date'],
            'custom_fields.*.value_datetime' => ['nullable', 'date'],
            'custom_fields.*.value_json' => ['nullable'],
            'custom_fields.*.file' => ['nullable'],
            'custom_fields.*.files' => ['nullable'],
            'custom_fields.*.files.*' => ['nullable'],
        ]);
    }

    private function cleanEmptyUploadInputs(Request $request): void
    {
        if ($request->has('featured_image_id') && $request->input('featured_image_id') === '') {
            $request->merge(['featured_image_id' => null]);
        }

        if ($request->has('gallery_images') && $request->input('gallery_images') === '') {
            $request->merge(['gallery_images' => []]);
        }

        if ($request->has('gallery_image_ids') && $request->input('gallery_image_ids') === '') {
            $request->merge(['gallery_image_ids' => []]);
        }

        if ($request->has('custom_fields')) {
            $customFields = $request->input('custom_fields', []);

            if (is_array($customFields)) {
                foreach ($customFields as $index => $fieldData) {
                    if (array_key_exists('file', $fieldData) && $fieldData['file'] === '') {
                        unset($customFields[$index]['file']);
                    }

                    if (array_key_exists('files', $fieldData) && $fieldData['files'] === '') {
                        $customFields[$index]['files'] = [];
                    }

                    if (array_key_exists('value_json', $fieldData) && $fieldData['value_json'] === '') {
                        $customFields[$index]['value_json'] = [];
                    }

                    if (array_key_exists('value_string', $fieldData) && $fieldData['value_string'] === null) {
                        $customFields[$index]['value_string'] = '';
                    }

                    if (array_key_exists('value_text', $fieldData) && $fieldData['value_text'] === null) {
                        $customFields[$index]['value_text'] = '';
                    }
                }

                $request->merge(['custom_fields' => $customFields]);
            }
        }
    }

    private function normalizeSubmittedTaxonomyTermIds(array $validated): array
    {
        if (array_key_exists('taxonomies', $validated) && is_array($validated['taxonomies'])) {
            $termIds = [];

            foreach ($validated['taxonomies'] as $taxonomyData) {
                if (!empty($taxonomyData['taxonomy_term_id'])) {
                    $termIds[] = (int) $taxonomyData['taxonomy_term_id'];
                }

                if (!empty($taxonomyData['taxonomy_term_ids'])) {
                    foreach ($this->normalizeIds($taxonomyData['taxonomy_term_ids']) as $termId) {
                        $termIds[] = $termId;
                    }
                }
            }

            return collect($termIds)
                ->unique()
                ->values()
                ->toArray();
        }

        return $this->normalizeIds($validated['taxonomy_term_ids'] ?? []);
    }

    private function validateSubmittedTaxonomyGroups(PostType $postType, array $submittedTaxonomies): void
    {
        if (empty($submittedTaxonomies)) {
            return;
        }

        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!$supports['taxonomies']) {
            throw ValidationException::withMessages([
                'taxonomies' => ['This post type does not support taxonomies.'],
            ]);
        }

        $attachedTaxonomies = $postType->activeTaxonomies()->get();
        $attachedTaxonomiesById = $attachedTaxonomies->keyBy('id');

        foreach ($submittedTaxonomies as $index => $taxonomyData) {
            $taxonomyId = (int) ($taxonomyData['taxonomy_id'] ?? 0);

            if (!$attachedTaxonomiesById->has($taxonomyId)) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}.taxonomy_id" => ['This taxonomy is not attached with selected post type.'],
                ]);
            }

            $taxonomy = $attachedTaxonomiesById->get($taxonomyId);
            $selectionType = $this->getTaxonomySelectionType($taxonomy);

            $termIds = [];

            if (!empty($taxonomyData['taxonomy_term_id'])) {
                $termIds[] = (int) $taxonomyData['taxonomy_term_id'];
            }

            if (!empty($taxonomyData['taxonomy_term_ids'])) {
                foreach ($this->normalizeIds($taxonomyData['taxonomy_term_ids']) as $termId) {
                    $termIds[] = $termId;
                }
            }

            $termIds = collect($termIds)->unique()->values()->toArray();

            if ($selectionType === 'single' && count($termIds) > 1) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}" => [$taxonomy->name . ' allows only one term.'],
                ]);
            }

            if (!empty($termIds)) {
                $invalidTermExists = TaxonomyTerm::whereIn('id', $termIds)
                    ->where('taxonomy_id', '!=', $taxonomyId)
                    ->exists();

                if ($invalidTermExists) {
                    throw ValidationException::withMessages([
                        "taxonomies.{$index}.taxonomy_term_ids" => ['Some selected terms do not belong to this taxonomy.'],
                    ]);
                }
            }
        }
    }

    private function validateDependentTaxonomySelections(array $selectedTermIds): void
    {
        if (empty($selectedTermIds)) {
            return;
        }

        $terms = TaxonomyTerm::query()
            ->with([
                'relationValues' => function ($query) {
                    $query
                        ->select(
                            'taxonomy_terms.id',
                            'taxonomy_terms.taxonomy_id',
                            'taxonomy_terms.name',
                            'taxonomy_terms.slug'
                        )
                        ->where('taxonomy_terms.status', true)
                        ->wherePivot('status', true);
                },
            ])
            ->whereIn('id', $selectedTermIds)
            ->get();

        foreach ($terms as $term) {
            $relationValueIds = $term->relationValues
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            if (empty($relationValueIds)) {
                continue;
            }

            $hasMatchedParent = count(array_intersect($relationValueIds, $selectedTermIds)) > 0;

            if (!$hasMatchedParent) {
                $requiredValues = $term->relationValues
                    ->pluck('name')
                    ->values()
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'taxonomies' => [
                        $term->name . ' depends on selected parent term: ' . $requiredValues,
                    ],
                ]);
            }
        }
    }

    private function syncTaxonomyTerms(DynamicPost $post, array $taxonomyTermIds): void
    {
        if (empty($taxonomyTermIds)) {
            $post->taxonomyTerms()->sync([]);
            return;
        }

        $terms = TaxonomyTerm::whereIn('id', $taxonomyTermIds)->get();

        $syncData = [];

        foreach ($terms as $term) {
            $syncData[$term->id] = [
                'taxonomy_id' => $term->taxonomy_id,
            ];
        }

        $post->taxonomyTerms()->sync($syncData);
    }

    private function dynamicPostPayloadForDatabase(array $validated): array
    {
        unset(
            $validated['taxonomy_term_ids'],
            $validated['taxonomies'],
            $validated['custom_fields'],
            $validated['featured_image'],
            $validated['gallery_images'],
            $validated['post_type']
        );

        if (array_key_exists('gallery_image_ids', $validated)) {
            $validated['gallery_image_ids'] = $this->normalizeIds($validated['gallery_image_ids']);

            if (!$this->dynamicPostHasArrayCast('gallery_image_ids')) {
                $validated['gallery_image_ids'] = json_encode($validated['gallery_image_ids']);
            }
        }

        return $validated;
    }

    private function dynamicPostHasArrayCast(string $field): bool
    {
        $casts = (new DynamicPost())->getCasts();

        return isset($casts[$field]) && in_array($casts[$field], ['array', 'json', 'collection', 'object'], true);
    }

    private function saveCustomFieldValues(int $entityId, string $entityType, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $service = app(CustomFieldValueService::class);
        $service->saveValues($entityId, $entityType, $fields);
    }

    private function prepareBaseMediaForSave(Request $request, array $validated, PostType $postType, ?DynamicPost $existingPost = null): array
    {
        $featuredProvided = $request->request->has('featured_image')
            || $request->files->has('featured_image')
            || array_key_exists('featured_image_id', $validated);

        $featuredFile = $request->file('featured_image');
        $featuredInput = $request->input('featured_image');

        if ($existingPost && !$featuredProvided) {
            $this->deleteMediaFileById($existingPost->featured_image_id);
            $validated['featured_image_id'] = null;
        } elseif ($featuredFile) {
            if ($existingPost && !empty($existingPost->featured_image_id)) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
            }

            $featuredMedia = $this->storeDynamicPostMediaFile($featuredFile, $postType, 'featured-image');
            $validated['featured_image_id'] = $featuredMedia->id;
        } elseif ($existingPost && $request->request->has('featured_image')) {
            if (empty($featuredInput)) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
                $validated['featured_image_id'] = null;
            } else {
                $submittedFeaturedId = $this->resolveMediaFileIdFromUrl((string) $featuredInput);

                if ($submittedFeaturedId) {
                    if ((int) $submittedFeaturedId !== (int) $existingPost->featured_image_id) {
                        $this->deleteMediaFileById($existingPost->featured_image_id);
                    }

                    $validated['featured_image_id'] = $submittedFeaturedId;
                }
            }
        } elseif ($existingPost && array_key_exists('featured_image_id', $validated)) {
            if (empty($validated['featured_image_id'])) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
                $validated['featured_image_id'] = null;
            } elseif ((int) $validated['featured_image_id'] !== (int) $existingPost->featured_image_id) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
                $validated['featured_image_id'] = (int) $validated['featured_image_id'];
            }
        }

        $currentGalleryIds = $existingPost
            ? $this->normalizeIds($existingPost->gallery_image_ids ?? [])
            : [];

        $galleryProvided = $request->request->has('gallery_images')
            || $request->files->has('gallery_images')
            || array_key_exists('gallery_image_ids', $validated);

        if ($existingPost && !$galleryProvided) {
            $this->deleteMediaFilesByIds($currentGalleryIds);
            $validated['gallery_image_ids'] = [];
            return $validated;
        }

        $galleryInputExists = $request->request->has('gallery_images') || $request->files->has('gallery_images');

        if ($galleryInputExists) {
            $submittedGalleryIds = [];
            $galleryInputs = $request->input('gallery_images', []);
            $galleryFiles = $request->file('gallery_images', []);

            if (!is_array($galleryInputs)) {
                $galleryInputs = [$galleryInputs];
            }

            if (!is_array($galleryFiles)) {
                $galleryFiles = $galleryFiles ? [$galleryFiles] : [];
            }

            $keys = collect(array_keys($galleryInputs))
                ->merge(array_keys($galleryFiles))
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            foreach ($keys as $key) {
                $file = $galleryFiles[$key] ?? null;

                if ($file) {
                    $galleryMedia = $this->storeDynamicPostMediaFile($file, $postType, 'gallery');
                    $submittedGalleryIds[] = (int) $galleryMedia->id;
                    continue;
                }

                $url = $galleryInputs[$key] ?? null;

                if (!empty($url) && is_string($url)) {
                    $mediaId = $this->resolveMediaFileIdFromUrl($url);

                    if ($mediaId) {
                        $submittedGalleryIds[] = (int) $mediaId;
                    }
                }
            }

            if ($existingPost) {
                $removedGalleryIds = array_values(array_diff($currentGalleryIds, $submittedGalleryIds));

                if (!empty($removedGalleryIds)) {
                    $this->deleteMediaFilesByIds($removedGalleryIds);
                }
            }

            $validated['gallery_image_ids'] = collect($submittedGalleryIds)
                ->unique()
                ->values()
                ->toArray();
        } elseif ($existingPost && array_key_exists('gallery_image_ids', $validated)) {
            $submittedGalleryIds = $this->normalizeIds($validated['gallery_image_ids']);
            $removedGalleryIds = array_values(array_diff($currentGalleryIds, $submittedGalleryIds));

            if (!empty($removedGalleryIds)) {
                $this->deleteMediaFilesByIds($removedGalleryIds);
            }

            $validated['gallery_image_ids'] = $submittedGalleryIds;
        }

        return $validated;
    }

    private function resolveMediaFileIdFromUrl(string $url): ?int
    {
        $path = $this->storagePathFromUrl($url);

        if (!$path) {
            return null;
        }

        return MediaFile::where('path', $path)->value('id');
    }

    private function storagePathFromUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        $storagePosition = strpos($path, '/storage/');

        if ($storagePosition !== false) {
            return ltrim(substr($path, $storagePosition + strlen('/storage/')), '/');
        }

        if (str_starts_with($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        if (str_starts_with($path, '/uploads/')) {
            return ltrim($path, '/');
        }

        if (str_starts_with($path, 'uploads/')) {
            return $path;
        }

        return null;
    }

    private function storeDynamicPostMediaFile($file, PostType $postType, string $type): MediaFile
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                $type === 'featured-image' ? 'featured_image' : 'gallery_images' => [
                    'Invalid image format. Allowed formats: ' . implode(', ', $allowedExtensions)
                ],
            ]);
        }

        $maxSizeKb = 5120;

        if (($file->getSize() / 1024) > $maxSizeKb) {
            throw ValidationException::withMessages([
                $type === 'featured-image' ? 'featured_image' : 'gallery_images' => [
                    'Image size must not be greater than 5MB.'
                ],
            ]);
        }

        $postTypeSlug = Str::slug($postType->slug ?? $postType->name ?? 'common', '-');
        $originalBaseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($originalBaseName ?: $type, '-');

        if (empty($safeBaseName)) {
            $safeBaseName = $type;
        }

        $shortUuid = substr((string) Str::uuid(), 0, 8);
        $fileName = $safeBaseName . '-' . $shortUuid . '.' . $extension;

        $directory = implode('/', [
            'uploads',
            'dynamic-posts',
            $postTypeSlug,
            $type,
            now()->format('Y'),
            now()->format('m'),
        ]);

        $path = $file->storeAs($directory, $fileName, 'public');

        return MediaFile::create([
            'disk' => 'public',
            'context' => 'dynamic-posts',
            'post_type_slug' => $postTypeSlug,
            'field_slug' => $type,
            'directory' => $directory,
            'path' => $path,
            'file_name' => $fileName,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size' => $file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);
    }

    private function deleteMediaFileById(null|int|string $mediaId): void
    {
        if (empty($mediaId)) {
            return;
        }

        $media = MediaFile::find((int) $mediaId);

        if (!$media) {
            return;
        }

        $disk = $media->disk ?? 'public';

        if (!empty($media->path) && Storage::disk($disk)->exists($media->path)) {
            Storage::disk($disk)->delete($media->path);
        }

        $media->delete();
    }

    private function deleteMediaFilesByIds(array $mediaIds): void
    {
        $mediaIds = collect($mediaIds)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        foreach ($mediaIds as $mediaId) {
            $this->deleteMediaFileById($mediaId);
        }
    }

    private function prepareCustomFieldsForSave(Request $request, array $validated, PostType $postType, ?DynamicPost $existingPost = null): array
    {
        $customFields = $validated['custom_fields'] ?? [];

        foreach ($customFields as $index => $fieldData) {
            $fieldId = (int) ($fieldData['custom_field_id'] ?? 0);

            if (!$fieldId) {
                continue;
            }

            $customField = CustomField::find($fieldId);

            if (!$customField) {
                continue;
            }

            if (!in_array($customField->field_type, ['media', 'file'], true)) {
                continue;
            }

            $oldValueJson = $existingPost
                ? $this->getExistingCustomFieldValueJson($existingPost->id, $fieldId)
                : [];

            $uploadedFiles = $this->extractCustomFieldUploadedFiles($request, $index);
            $mediaStateSubmitted = $this->customFieldMediaStateWasSubmitted($fieldData);

            if (!empty($uploadedFiles) || $mediaStateSubmitted) {
                $retainedFiles = $mediaStateSubmitted
                    ? $this->submittedCustomFieldMediaItems($fieldData, $oldValueJson)
                    : [];

                $uploaded = [];

                if (!empty($uploadedFiles)) {
                    $uploaded = $this->storeCustomFieldUploadedFiles(
                        $uploadedFiles,
                        $customField,
                        $postType
                    );
                }

                $newValueJson = collect($retainedFiles)
                    ->merge($uploaded)
                    ->filter(fn($item) => is_array($item) && !empty($item['path']))
                    ->unique('path')
                    ->values()
                    ->toArray();

                if ($this->containsTemporaryFilePath($newValueJson)) {
                    throw ValidationException::withMessages([
                        "custom_fields.{$index}.value_json" => [
                            'Temporary file path is not allowed. Please upload the file again.'
                        ],
                    ]);
                }

                $this->deleteRemovedCustomFieldFiles($oldValueJson, $newValueJson);
                $customFields[$index]['value_json'] = $newValueJson;

                unset(
                    $customFields[$index]['value_string'],
                    $customFields[$index]['value_text'],
                    $customFields[$index]['value_number'],
                    $customFields[$index]['value_date'],
                    $customFields[$index]['value_datetime']
                );
            } else {
                $valueJson = $fieldData['value_json'] ?? [];

                if ($this->containsTemporaryFilePath($valueJson)) {
                    throw ValidationException::withMessages([
                        "custom_fields.{$index}.value_json" => [
                            'Temporary file path is not allowed. Please upload the file again.'
                        ],
                    ]);
                }
            }

            unset(
                $customFields[$index]['file'],
                $customFields[$index]['files']
            );
        }

        return $customFields;
    }

    private function appendMissingMediaCustomFieldDeletes(DynamicPost $post, array $customFields): array
    {
        $submittedFieldIds = collect($customFields)
            ->pluck('custom_field_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $mediaFieldIds = CustomField::whereIn('field_type', ['media', 'file'])
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        if (empty($mediaFieldIds)) {
            return $customFields;
        }

        $existingMediaValues = CustomFieldValue::where('entity_type', 'post')
            ->where('entity_id', $post->id)
            ->whereIn('custom_field_id', $mediaFieldIds)
            ->get();

        foreach ($existingMediaValues as $existingValue) {
            $fieldId = (int) $existingValue->custom_field_id;

            if (in_array($fieldId, $submittedFieldIds, true)) {
                continue;
            }

            $oldValueJson = $this->normalizeCustomFieldValueJson($existingValue->value_json ?? []);

            if (!empty($oldValueJson)) {
                $this->deleteStoredCustomFieldFiles($oldValueJson);
            }

            $customFields[] = [
                'custom_field_id' => $fieldId,
                'value_json' => [],
            ];
        }

        return $customFields;
    }

    private function getExistingCustomFieldValueJson(int $entityId, int $customFieldId): array
    {
        $value = CustomFieldValue::where('entity_type', 'post')
            ->where('entity_id', $entityId)
            ->where('custom_field_id', $customFieldId)
            ->first();

        if (!$value) {
            return [];
        }

        return $this->normalizeCustomFieldValueJson($value->value_json ?? []);
    }

    private function customFieldMediaStateWasSubmitted(array $fieldData): bool
    {
        return array_key_exists('value_json', $fieldData)
            || array_key_exists('value_string', $fieldData)
            || array_key_exists('value_text', $fieldData)
            || array_key_exists('file', $fieldData)
            || array_key_exists('files', $fieldData);
    }

    private function submittedCustomFieldMediaItems(array $fieldData, array $oldValueJson): array
    {
        $references = [];

        if (array_key_exists('value_json', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['value_json']));
        }

        if (array_key_exists('value_string', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['value_string']));
        }

        if (array_key_exists('value_text', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['value_text']));
        }

        if (array_key_exists('file', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['file']));
        }

        if (array_key_exists('files', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['files']));
        }

        if (empty($references)) {
            return [];
        }

        $oldFilesByPath = collect($oldValueJson)
            ->filter(fn($item) => is_array($item) && !empty($item['path']))
            ->keyBy('path');

        $items = [];

        foreach ($references as $reference) {
            $path = null;
            $url = null;

            if (is_array($reference)) {
                $path = $reference['path'] ?? null;
                $url = $reference['url'] ?? null;

                if (!$path && $url) {
                    $path = $this->storagePathFromUrl((string) $url);
                }
            } elseif (is_string($reference)) {
                $url = $reference;
                $path = $this->storagePathFromUrl($reference);
            }

            if (!$path) {
                continue;
            }

            if ($oldFilesByPath->has($path)) {
                $items[] = $oldFilesByPath->get($path);
                continue;
            }

            $items[] = [
                'disk' => 'public',
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'file_name' => basename($path),
                'original_name' => basename($path),
                'mime_type' => null,
                'extension' => pathinfo($path, PATHINFO_EXTENSION),
                'size' => null,
                'size_kb' => null,
            ];
        }

        return collect($items)
            ->unique('path')
            ->values()
            ->toArray();
    }
    private function normalizeSubmittedMediaReferences(mixed $value): array
    {
        if (is_null($value)) {
            return [];
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return [];
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeSubmittedMediaReferences($decoded);
            }

            return [$value];
        }

        if (is_array($value)) {
            if (array_key_exists('path', $value) || array_key_exists('url', $value)) {
                return [$value];
            }

            $items = [];

            foreach ($value as $item) {
                $items = array_merge($items, $this->normalizeSubmittedMediaReferences($item));
            }

            return $items;
        }

        return [];
    }

    private function normalizeCustomFieldValueJson(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [];
    }

    private function deleteRemovedCustomFieldFiles(array $oldFiles, array $newFiles): void
    {
        $newPaths = collect($newFiles)
            ->pluck('path')
            ->filter()
            ->values()
            ->toArray();

        foreach ($oldFiles as $oldFile) {
            $oldPath = $oldFile['path'] ?? null;

            if (!$oldPath || in_array($oldPath, $newPaths, true)) {
                continue;
            }

            $this->deleteStoredCustomFieldFileItem($oldFile);
        }
    }

    private function deleteStoredCustomFieldFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->deleteStoredCustomFieldFileItem($file);
        }
    }

    private function deleteStoredCustomFieldFileItem(array $file): void
    {
        $path = $file['path'] ?? null;
        $disk = $file['disk'] ?? 'public';

        if (!$path) {
            return;
        }

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function extractCustomFieldUploadedFiles(Request $request, int $index): array
    {
        $files = [];

        $singleFile = $request->file("custom_fields.{$index}.file");

        if ($singleFile) {
            $files[] = $singleFile;
        }

        $multipleFiles = $request->file("custom_fields.{$index}.files");

        if ($multipleFiles) {
            if (is_array($multipleFiles)) {
                foreach ($multipleFiles as $file) {
                    if ($file) {
                        $files[] = $file;
                    }
                }
            } else {
                $files[] = $multipleFiles;
            }
        }

        return $files;
    }

    private function storeCustomFieldUploadedFiles(array $files, CustomField $field, PostType $postType): array
    {
        $mediaLimit = (int) ($field->media_limit ?? 1);

        if ($mediaLimit < 1) {
            $mediaLimit = 1;
        }

        if (count($files) > $mediaLimit) {
            throw ValidationException::withMessages([
                'custom_fields' => [
                    'You can upload maximum ' . $mediaLimit . ' file(s) for ' . $field->field_label . '.'
                ],
            ]);
        }

        $allowedExtensions = $this->allowedCustomFieldFileExtensions($field);
        $maxSizeKb = $this->parseCustomFieldMediaSizeToKb($field->media_size);

        $postTypeSlug = Str::slug($postType->slug ?? $postType->name ?? 'common', '-');
        $fieldSlug = Str::slug($field->field_name_slug ?? $field->field_label, '-');

        $directory = implode('/', [
            'uploads',
            'custom-fields',
            $postTypeSlug,
            $fieldSlug,
            now()->format('Y'),
            now()->format('m'),
        ]);

        $uploaded = [];

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension());

            if (!in_array($extension, $allowedExtensions, true)) {
                throw ValidationException::withMessages([
                    'custom_fields' => [
                        'Invalid file format for ' . $field->field_label . '. Allowed formats: ' . implode(', ', $allowedExtensions)
                    ],
                ]);
            }

            if (($file->getSize() / 1024) > $maxSizeKb) {
                throw ValidationException::withMessages([
                    'custom_fields' => [
                        'File size is too large for ' . $field->field_label . '. Maximum allowed size is ' . round($maxSizeKb / 1024, 2) . ' MB.'
                    ],
                ]);
            }

            $fileName = Str::uuid()->toString() . '.' . $extension;
            $path = $file->storeAs($directory, $fileName, 'public');

            $uploaded[] = [
                'disk' => 'public',
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'file_name' => $fileName,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'extension' => $extension,
                'size' => $file->getSize(),
                'size_kb' => round($file->getSize() / 1024, 2),
            ];
        }

        return $uploaded;
    }

    private function allowedCustomFieldFileExtensions(CustomField $field): array
    {
        if (!empty($field->media_format)) {
            return collect(explode(',', $field->media_format))
                ->map(fn($format) => strtolower(trim($format)))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        if ($field->field_type === 'media') {
            return ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        }

        return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
    }

    private function parseCustomFieldMediaSizeToKb(?string $size): int
    {
        if (empty($size)) {
            return 10240;
        }

        $size = strtolower(trim($size));
        $size = str_replace(' ', '', $size);

        if (is_numeric($size)) {
            return (int) $size * 1024;
        }

        if (preg_match('/^(\d+)(kb|mb|gb)$/', $size, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'gb' => $value * 1024 * 1024,
                'mb' => $value * 1024,
                'kb' => $value,
                default => 10240,
            };
        }

        return 10240;
    }

    private function containsTemporaryFilePath(mixed $value): bool
    {
        if (empty($value)) {
            return false;
        }

        if (is_string($value)) {
            return str_contains($value, '/tmp/')
                || str_contains($value, '\\tmp\\')
                || str_starts_with($value, 'tmp/')
                || str_starts_with($value, '/private/var/tmp/');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsTemporaryFilePath($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertTaxonomyTermsExist(array $termIds): void
    {
        if (empty($termIds)) {
            return;
        }

        $existingTermIds = TaxonomyTerm::whereIn('id', $termIds)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $missingTermIds = array_values(array_diff($termIds, $existingTermIds));

        if (!empty($missingTermIds)) {
            throw ValidationException::withMessages([
                'taxonomy_term_ids' => ['One or more taxonomy term ids do not exist.'],
            ]);
        }
    }

    private function validateTaxonomyTermsForPostType(PostType $postType, array $termIds): void
    {
        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!$supports['taxonomies'] && !empty($termIds)) {
            throw ValidationException::withMessages([
                'taxonomy_term_ids' => ['This post type does not support taxonomies.'],
            ]);
        }

        if (empty($termIds)) {
            return;
        }

        $attachedTaxonomies = $postType->activeTaxonomies()->get();

        $allowedTaxonomyIds = $attachedTaxonomies
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $terms = TaxonomyTerm::whereIn('id', $termIds)->get(['id', 'taxonomy_id', 'name']);

        $invalidTaxonomyTermIds = $terms
            ->filter(fn($term) => !in_array((int) $term->taxonomy_id, $allowedTaxonomyIds, true))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        if (!empty($invalidTaxonomyTermIds)) {
            throw ValidationException::withMessages([
                'taxonomy_term_ids' => ['Some taxonomy terms do not belong to this post type taxonomies.'],
            ]);
        }

        $termsByTaxonomy = $terms->groupBy('taxonomy_id');

        foreach ($attachedTaxonomies as $taxonomy) {
            $selectionType = $this->getTaxonomySelectionType($taxonomy);
            $selectedCount = $termsByTaxonomy->get($taxonomy->id, collect())->count();

            if ($selectionType === 'single' && $selectedCount > 1) {
                throw ValidationException::withMessages([
                    'taxonomy_term_ids' => [$taxonomy->name . ' allows only one term.'],
                ]);
            }
        }
    }

    private function validateSubmittedCustomFieldsForPostType(PostType $postType, array $termIds, array $customFields): void
    {
        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!$supports['custom_fields'] && !empty($customFields)) {
            throw ValidationException::withMessages([
                'custom_fields' => ['This post type does not support custom fields.'],
            ]);
        }

        if (empty($customFields)) {
            return;
        }

        $allowedFieldIds = $this->resolveVisibleCustomFields($postType->id, $termIds)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $submittedFieldIds = collect($customFields)
            ->pluck('custom_field_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $unsupportedFieldIds = array_values(array_diff($submittedFieldIds, $allowedFieldIds));

        if (!empty($unsupportedFieldIds)) {
            throw ValidationException::withMessages([
                'custom_fields' => ['Some custom fields are not allowed for selected post type or selected taxonomy terms.'],
            ]);
        }
    }

    private function normalizePostTypeSupports(array|string|null $supports): array
    {
        if (is_string($supports)) {
            $decoded = json_decode($supports, true);
            $supports = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($supports)) {
            $supports = [];
        }

        $supports = collect($supports)
            ->map(fn($item) => Str::slug((string) $item, '_'))
            ->values()
            ->toArray();

        return [
            'featured_image' => in_array('featured_image', $supports, true),
            'title' => in_array('title', $supports, true),
            'custom_fields' => in_array('custom_fields', $supports, true),
            'content' => in_array('content', $supports, true),
            'taxonomies' => in_array('taxonomies', $supports, true),
            'excerpt' => in_array('excerpt', $supports, true),
            'gallery' => in_array('gallery', $supports, true),
        ];
    }

    private function getTaxonomySelectionType($taxonomy): string
    {
        $pivotType = $taxonomy->pivot?->selection_type ?? null;

        if (in_array($pivotType, ['single', 'multiple'], true)) {
            return $pivotType;
        }

        $slug = Str::slug($taxonomy->slug ?? $taxonomy->name, '-');

        return in_array($slug, ['property-types', 'property-type', 'property-status'], true)
            ? 'multiple'
            : 'single';
    }

    private function buildTaxonomyFields(PostType $postType, array $selectedTermIds = [])
    {
        return $postType->taxonomies->map(function ($taxonomy) use ($selectedTermIds) {
            $selectionType = $this->getTaxonomySelectionType($taxonomy);

            $terms = TaxonomyTerm::query()
                ->with([
                    'relationValues' => function ($query) {
                        $query
                            ->select(
                                'taxonomy_terms.id',
                                'taxonomy_terms.taxonomy_id',
                                'taxonomy_terms.name',
                                'taxonomy_terms.slug'
                            )
                            ->where('taxonomy_terms.status', true)
                            ->wherePivot('status', true);
                    },
                ])
                ->select(
                    'id',
                    'taxonomy_id',
                    'parent_id',
                    'relation_with_taxonomy_id',
                    'name',
                    'slug'
                )
                ->where('taxonomy_id', $taxonomy->id)
                ->where('status', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $dependencyMeta = $this->getTaxonomyDependencyMeta($terms, $selectedTermIds);
            $terms = $this->filterDependentTaxonomyTerms($terms, $selectedTermIds);

            return [
                'taxonomy_id' => $taxonomy->id,
                'taxonomy_name' => $taxonomy->name,
                'taxonomy_slug' => $taxonomy->slug,
                'field_label' => $taxonomy->name,
                'field_name' => 'taxonomies',
                'selection_type' => $selectionType,
                'multiple' => $selectionType === 'multiple',
                'request_key' => $selectionType === 'multiple' ? 'taxonomy_term_ids' : 'taxonomy_term_id',
                'is_dependent' => $dependencyMeta['is_dependent'],
                'depends_on_taxonomy_ids' => $dependencyMeta['depends_on_taxonomy_ids'],
                'depends_on_term_ids' => $dependencyMeta['depends_on_term_ids'],
                'depends_on_selected_term_ids' => $dependencyMeta['depends_on_selected_term_ids'],
                'terms' => $terms->map(fn($term) => [
                    'id' => $term->id,
                    'taxonomy_id' => $term->taxonomy_id,
                    'parent_id' => $term->parent_id,
                    'relation_with_taxonomy_id' => $term->relation_with_taxonomy_id,
                    'relation_value_term_ids' => $term->relationValues
                        ->pluck('id')
                        ->map(fn($id) => (int) $id)
                        ->values(),
                    'name' => $term->name,
                    'slug' => $term->slug,
                ])->values(),
            ];
        })->values();
    }

    private function getTaxonomyDependencyMeta($terms, array $selectedTermIds): array
    {
        $dependentTerms = $terms->filter(function ($term) {
            return !empty($term->relation_with_taxonomy_id) || $term->relationValues->isNotEmpty();
        });

        $dependsOnTaxonomyIds = $dependentTerms
            ->pluck('relation_with_taxonomy_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $dependsOnTermIds = $dependentTerms
            ->flatMap(fn($term) => $term->relationValues->pluck('id'))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $dependsOnSelectedTermIds = $dependsOnTermIds
            ->filter(fn($id) => in_array((int) $id, $selectedTermIds, true))
            ->values();

        return [
            'is_dependent' => $dependentTerms->isNotEmpty(),
            'depends_on_taxonomy_ids' => $dependsOnTaxonomyIds,
            'depends_on_term_ids' => $dependsOnTermIds,
            'depends_on_selected_term_ids' => $dependsOnSelectedTermIds,
        ];
    }

    private function filterDependentTaxonomyTerms($terms, array $selectedTermIds)
    {
        $hasDependentTerms = $terms->contains(function ($term) {
            return !empty($term->relation_with_taxonomy_id) || $term->relationValues->isNotEmpty();
        });

        if (!$hasDependentTerms) {
            return $terms->values();
        }

        if (empty($selectedTermIds)) {
            return collect();
        }

        return $terms
            ->filter(function ($term) use ($selectedTermIds) {
                $relationValueTermIds = $term->relationValues
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->values()
                    ->toArray();

                if (empty($relationValueTermIds)) {
                    return false;
                }

                return count(array_intersect($relationValueTermIds, $selectedTermIds)) > 0;
            })
            ->values();
    }


    private function baseFields(array $supports): array
    {
        return [
            [
                'key' => 'featured_image_id',
                'label' => 'Featured Image',
                'enabled' => $supports['featured_image'],
            ],
            [
                'key' => 'title',
                'label' => 'Title',
                'enabled' => $supports['title'],
            ],
            [
                'key' => 'content',
                'label' => 'Content',
                'enabled' => $supports['content'],
            ],
            [
                'key' => 'excerpt',
                'label' => 'Excerpt',
                'enabled' => $supports['excerpt'],
            ],
            [
                'key' => 'gallery_image_ids',
                'label' => 'Gallery',
                'enabled' => $supports['gallery'],
            ],
        ];
    }

    private function statusOptions(): array
    {
        return [
            [
                'label' => 'Ready for Publish',
                'value' => 'published',
            ],
            [
                'label' => 'Save as Draft',
                'value' => 'draft',
            ],
        ];
    }

    private function liveStatusOptions(): array
    {
        return [
            [
                'label' => 'Approve',
                'value' => 'approve',
            ],
            [
                'label' => 'Reject',
                'value' => 'reject',
            ],
            [
                'label' => 'Under Review',
                'value' => 'under_review',
            ],
            [
                'label' => 'Disapprove',
                'value' => 'disapprove',
            ],
            [
                'label' => 'Modify Review',
                'value' => 'modify_review',
            ],
            [
                'label' => 'Submit',
                'value' => 'submit',
            ],
        ];
    }

    private function resolveVisibleCustomFields(int $postTypeId, array $selectedTermIds)
    {
        $selectedTerms = TaxonomyTerm::whereIn('id', $selectedTermIds)
            ->get(['id', 'taxonomy_id']);

        $selectedTaxonomyIds = $selectedTerms
            ->pluck('taxonomy_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        return CustomField::query()
            ->with([
                'group.locationRules' => function ($query) {
                    $query->where('status', true)
                        ->whereNull('custom_field_id')
                        ->orderBy('rule_group', 'asc')
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc');
                },
                'options' => function ($query) {
                    $query->where('status', true)
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc');
                },
                'repeaters' => function ($query) {
                    $query->where('status', true)
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc')
                        ->with([
                            'options' => function ($optionQuery) {
                                $optionQuery->where('status', true)
                                    ->orderBy('sort_order', 'asc')
                                    ->orderBy('id', 'asc');
                            },
                        ]);
                },
                'locationRules' => function ($query) {
                    $query->where('status', true)
                        ->whereNotNull('custom_field_id')
                        ->orderBy('rule_group', 'asc')
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc');
                },
                'conditions.taxonomy',
                'conditions.taxonomyTerm',
            ])
            ->where('status', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->filter(function ($field) use ($postTypeId, $selectedTaxonomyIds, $selectedTermIds) {
                return $this->fieldLocationRulesMatch($field, $postTypeId, $selectedTaxonomyIds, $selectedTermIds)
                    && $this->fieldConditionsMatch($field, $selectedTermIds);
            })
            ->values();
    }
    private function fieldConditionsMatch($field, array $selectedTermIds): bool
    {
        $conditions = $field->conditions ?? collect();

        if ($conditions->isEmpty()) {
            return true;
        }

        $includeTermIds = $conditions
            ->filter(fn($condition) => ($condition->operator ?? 'include') === 'include')
            ->pluck('taxonomy_term_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        $excludeTermIds = $conditions
            ->filter(fn($condition) => ($condition->operator ?? 'include') === 'exclude')
            ->pluck('taxonomy_term_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        if (!empty($excludeTermIds) && count(array_intersect($excludeTermIds, $selectedTermIds)) > 0) {
            return false;
        }

        if (!empty($includeTermIds)) {
            return count(array_intersect($includeTermIds, $selectedTermIds)) > 0;
        }

        return true;
    }
    private function fieldLocationRulesMatch($field, int $postTypeId, array $selectedTaxonomyIds, array $selectedTermIds): bool
    {
        $groupRules = $field->group?->locationRules ?? collect();
        $fieldRules = $field->locationRules ?? collect();

        $groupMatched = $groupRules->isEmpty()
            ? true
            : $this->locationRulesCollectionMatch($groupRules, $postTypeId, $selectedTaxonomyIds, $selectedTermIds);

        if (!$groupMatched) {
            return false;
        }

        if ($fieldRules->isNotEmpty()) {
            return $this->locationRulesCollectionMatch($fieldRules, $postTypeId, $selectedTaxonomyIds, $selectedTermIds);
        }

        return true;
    }
    private function locationRulesCollectionMatch($rules, int $postTypeId, array $selectedTaxonomyIds, array $selectedTermIds): bool
    {
        if ($rules->isEmpty()) {
            return true;
        }

        $groups = $rules->groupBy(fn($rule) => $rule->rule_group ?: 1);

        foreach ($groups as $groupRules) {
            $matched = true;

            foreach ($groupRules as $rule) {
                if (!$this->locationRuleMatches($rule, $postTypeId, $selectedTaxonomyIds, $selectedTermIds)) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }
    private function locationRuleMatches($rule, int $postTypeId, array $selectedTaxonomyIds, array $selectedTermIds): bool
    {
        $operator = $rule->operator ?: 'is_equal_to';
        $matchType = $rule->match_type ?: 'specific';

        if ($rule->show_if === 'post_type') {
            $matched = $matchType === 'all'
                ? true
                : (int) $rule->post_type_id === $postTypeId;

            return $operator === 'is_not_equal_to' ? !$matched : $matched;
        }

        if ($rule->show_if === 'taxonomy') {
            if ($matchType === 'all') {
                $matched = true;
            } else {
                $taxonomyMatched = empty($rule->taxonomy_id)
                    ? true
                    : in_array((int) $rule->taxonomy_id, $selectedTaxonomyIds, true);

                $ruleTermIds = $this->normalizeIds($rule->taxonomy_term_ids ?? []);

                $termMatched = empty($ruleTermIds)
                    ? $taxonomyMatched
                    : count(array_intersect($ruleTermIds, $selectedTermIds)) > 0;

                $matched = $taxonomyMatched && $termMatched;
            }

            return $operator === 'is_not_equal_to' ? !$matched : $matched;
        }

        return false;
    }

    private function formatCustomField($field): array
    {
        return [
            'id' => $field->id,
            'custom_field_group_id' => $field->custom_field_group_id,
            'field_label' => $field->field_label,
            'field_name_slug' => $field->field_name_slug,
            'field_placeholder' => $field->field_placeholder,
            'field_type' => $field->field_type,
            'required' => $field->required,
            'checkbox_type' => $field->checkbox_type,
            'default_value' => $field->default_value,
            'validation_rules' => $field->validation_rules,
            'conditional_rules' => $field->conditional_rules,
            'media_limit' => $field->media_limit,
            'media_size' => $field->media_size,
            'media_format' => $field->media_format,
            'sort_order' => $field->sort_order,
            'status' => $field->status,
            'location_rules' => ($field->locationRules ?? collect())->map(fn($rule) => [
                'id' => $rule->id,
                'logic_operator' => $rule->logic_operator,
                'rule_group' => $rule->rule_group,
                'show_if' => $rule->show_if,
                'operator' => $rule->operator,
                'match_type' => $rule->match_type,
                'post_type_id' => $rule->post_type_id,
                'taxonomy_id' => $rule->taxonomy_id,
                'taxonomy_term_ids' => $rule->taxonomy_term_ids ?? [],
                'status' => $rule->status,
                'sort_order' => $rule->sort_order,
            ])->values(),
            'options' => ($field->options ?? collect())->map(fn($option) => [
                'id' => $option->id,
                'name' => $option->name,
                'value' => $option->value,
                'type' => $option->type,
                'sort_order' => $option->sort_order,
            ])->values(),
            'repeaters' => ($field->repeaters ?? collect())->map(fn($repeater) => [
                'id' => $repeater->id,
                'field_label' => $repeater->field_label,
                'field_name_slug' => $repeater->field_name_slug,
                'field_placeholder' => $repeater->field_placeholder,
                'field_type' => $repeater->field_type,
                'media_limit' => $repeater->media_limit,
                'media_size' => $repeater->media_size,
                'media_format' => $repeater->media_format,
                'sort_order' => $repeater->sort_order,
                'options' => ($repeater->options ?? collect())->map(fn($option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'value' => $option->value,
                    'type' => $option->type,
                    'sort_order' => $option->sort_order,
                ])->values(),
            ])->values(),
        ];
    }

    private function formatDynamicPostResponse(DynamicPost $post): array
    {
        $post->loadMissing([
            'postType',
            'parent:id,post_type_id,title,slug,status,live_status',
            'taxonomyTerms.taxonomy',
            'meta.customField.options',
            'meta.customField.repeaters.options',
        ]);

        $data = $post->toArray();
        $data['selected_taxonomies'] = $this->formatSelectedTaxonomies($post);
        $data['display_id'] = $post->listing_code ?? null;
        $data['featured_image'] = $this->formatMediaFileById($post->featured_image_id ?? null);
        $data['gallery_images'] = $this->formatMediaFilesByIds($post->gallery_image_ids ?? []);

        return $data;
    }

    private function formatMediaFileById(null|int|string $mediaId): ?array
    {
        if (empty($mediaId)) {
            return null;
        }

        $media = MediaFile::find((int) $mediaId);

        return $media ? $media->toArray() : null;
    }

    private function formatMediaFilesByIds(array|string|null $mediaIds): array
    {
        $ids = $this->normalizeIds($mediaIds);

        if (empty($ids)) {
            return [];
        }

        $mediaFiles = MediaFile::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn($id) => $mediaFiles->get((int) $id))
            ->filter()
            ->map(fn($media) => $media->toArray())
            ->values()
            ->toArray();
    }

    private function formatSelectedTaxonomies(DynamicPost $post): array
    {
        $terms = $post->taxonomyTerms ?? collect();

        return $terms
            ->groupBy('taxonomy_id')
            ->map(function ($taxonomyTerms) {
                $firstTerm = $taxonomyTerms->first();
                $taxonomy = $firstTerm->taxonomy;

                if (!$taxonomy) {
                    return null;
                }

                $selectionType = $this->getTaxonomySelectionType($taxonomy);

                return [
                    'taxonomy_id' => $taxonomy->id,
                    'taxonomy_name' => $taxonomy->name,
                    'taxonomy_slug' => $taxonomy->slug,
                    'selection_type' => $selectionType,
                    'multiple' => $selectionType === 'multiple',
                    'selected_term_id' => $selectionType === 'single' ? $firstTerm->id : null,
                    'selected_term_ids' => $taxonomyTerms->pluck('id')->map(fn($id) => (int) $id)->values(),
                    'selected_terms' => $taxonomyTerms->map(fn($term) => [
                        'id' => $term->id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ])->values(),
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    private function isConditionalFieldVisible(null|array|string $rules, array $selectedValues): bool
    {
        if (empty($rules)) {
            return true;
        }

        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        if (!is_array($rules)) {
            return true;
        }

        $dependsOn = $rules['depends_on'] ?? $rules['field'] ?? null;
        $operator = $rules['operator'] ?? 'equals';
        $expectedValue = $rules['value'] ?? null;
        $action = $rules['action'] ?? 'show';

        if (!$dependsOn) {
            return true;
        }

        $actualValue = $selectedValues[$dependsOn] ?? null;

        $matched = match ($operator) {
            'equals' => $actualValue == $expectedValue,
            'not_equals' => $actualValue != $expectedValue,
            'in' => in_array($actualValue, (array) $expectedValue),
            'not_in' => !in_array($actualValue, (array) $expectedValue),
            'empty' => empty($actualValue),
            'not_empty' => !empty($actualValue),
            default => true,
        };

        return $action === 'hide' ? !$matched : $matched;
    }
}
