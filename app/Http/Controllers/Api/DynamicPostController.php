<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldGroup;
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
            ->filter(fn($id) => $id !== null && $id !== '')
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
                    ->map(fn($id) => (int) $id)
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
                            '' . $slug . ' already exists for this post type.',
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
                ->map(fn($id) => (int) $id)
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
    public function customFieldsByPostType(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['required', 'integer', 'exists:post_types,id'],
                'taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'taxonomy_term_ids' => ['nullable'],
            ]);

            $postTypeId = (int) $request->post_type_id;
            $termIds = $this->normalizeIds($request->taxonomy_term_ids);

            if (!empty($termIds)) {
                $existingTermIds = TaxonomyTerm::whereIn('id', $termIds)
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->toArray();

                $missingTermIds = array_values(array_diff($termIds, $existingTermIds));

                if (!empty($missingTermIds)) {
                    return $this->errorResponse(
                        'Some taxonomy terms were not found.',
                        404,
                        'One or more taxonomy term ids do not exist.',
                        [
                            'missing_taxonomy_term_ids' => $missingTermIds,
                        ]
                    );
                }
            }

            $taxonomyIds = [];

            if ($request->filled('taxonomy_id')) {
                $taxonomyIds[] = (int) $request->taxonomy_id;
            }

            if (!empty($termIds)) {
                $termTaxonomyIds = TaxonomyTerm::whereIn('id', $termIds)
                    ->pluck('taxonomy_id')
                    ->map(fn($id) => (int) $id)
                    ->toArray();

                $taxonomyIds = array_values(array_unique(array_merge($taxonomyIds, $termTaxonomyIds)));
            }

            $groups = CustomFieldGroup::query()
                ->with([
                    'locationRules',

                    'fields' => function ($fieldQuery) {
                        $fieldQuery
                            ->where('status', true)
                            ->orderBy('sort_order', 'asc')
                            ->orderBy('id', 'asc')
                            ->with([
                                'options' => function ($optionQuery) {
                                    $optionQuery
                                        ->where('status', true)
                                        ->orderBy('sort_order', 'asc')
                                        ->orderBy('id', 'asc');
                                },
                                'repeaters' => function ($repeaterQuery) {
                                    $repeaterQuery
                                        ->where('status', true)
                                        ->orderBy('sort_order', 'asc')
                                        ->orderBy('id', 'asc')
                                        ->with([
                                            'options' => function ($optionQuery) {
                                                $optionQuery
                                                    ->where('status', true)
                                                    ->orderBy('sort_order', 'asc')
                                                    ->orderBy('id', 'asc');
                                            },
                                        ]);
                                },
                            ]);
                    },
                ])

                // Required: post type rule must match
                ->whereHas('locationRules', function ($ruleQuery) use ($postTypeId) {
                    $ruleQuery
                        ->where('show_if', 'post_type')
                        ->where(function ($subQuery) use ($postTypeId) {
                            $subQuery
                                ->where('match_type', 'all')
                                ->orWhere(function ($specificQuery) use ($postTypeId) {
                                    $specificQuery
                                        ->where('match_type', 'specific')
                                        ->where('post_type_id', $postTypeId);
                                });
                        });
                })

                // Optional: apply taxonomy filtering only when taxonomy/terms are sent
                ->when(!empty($taxonomyIds) || !empty($termIds), function ($groupQuery) use ($taxonomyIds, $termIds) {
                    $groupQuery->where(function ($taxonomyGroupQuery) use ($taxonomyIds, $termIds) {
                        $taxonomyGroupQuery
                            ->whereDoesntHave('locationRules', function ($ruleQuery) {
                                $ruleQuery->where('show_if', 'taxonomy');
                            })
                            ->orWhereHas('locationRules', function ($ruleQuery) use ($taxonomyIds, $termIds) {
                                $ruleQuery
                                    ->where('show_if', 'taxonomy')
                                    ->where(function ($subQuery) use ($taxonomyIds, $termIds) {
                                        $subQuery
                                            ->where('match_type', 'all')
                                            ->orWhere(function ($specificQuery) use ($taxonomyIds, $termIds) {
                                                $specificQuery->where('match_type', 'specific');

                                                if (!empty($taxonomyIds)) {
                                                    $specificQuery->whereIn('taxonomy_id', $taxonomyIds);
                                                }

                                                if (!empty($termIds)) {
                                                    $specificQuery->where(function ($termQuery) use ($termIds) {
                                                        foreach ($termIds as $termId) {
                                                            $termQuery->orWhereJsonContains('taxonomy_term_ids', $termId);
                                                        }

                                                        $termQuery
                                                            ->orWhereNull('taxonomy_term_ids')
                                                            ->orWhereRaw('JSON_LENGTH(taxonomy_term_ids) = 0');
                                                    });
                                                }
                                            });
                                    });
                            });
                    });
                })

                ->orderBy('id', 'asc')
                ->get();

            $customFields = $groups
                ->flatMap(function ($group) {
                    return $group->fields->map(function ($field) use ($group) {
                        return [
                            'group_id' => $group->id,
                            'group_name' => $group->group_name,
                            'group_slug' => $group->group_slug,

                            'id' => $field->id,
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

                            'options' => $field->options->map(fn($option) => [
                                'id' => $option->id,
                                'name' => $option->name,
                                'value' => $option->value,
                                'type' => $option->type,
                                'sort_order' => $option->sort_order,
                            ])->values(),

                            'repeaters' => $field->repeaters->map(fn($repeater) => [
                                'id' => $repeater->id,
                                'field_label' => $repeater->field_label,
                                'field_name_slug' => $repeater->field_name_slug,
                                'field_placeholder' => $repeater->field_placeholder,
                                'field_type' => $repeater->field_type,
                                'media_limit' => $repeater->media_limit,
                                'media_size' => $repeater->media_size,
                                'media_format' => $repeater->media_format,
                                'sort_order' => $repeater->sort_order,

                                'options' => $repeater->options->map(fn($option) => [
                                    'id' => $option->id,
                                    'name' => $option->name,
                                    'value' => $option->value,
                                    'type' => $option->type,
                                    'sort_order' => $option->sort_order,
                                ])->values(),
                            ])->values(),
                        ];
                    });
                })
                ->sortBy('sort_order')
                ->values();

            return $this->successResponse(
                'Custom fields fetched successfully.',
                [
                    'post_type_id' => $postTypeId,
                    'taxonomy_ids' => $taxonomyIds,
                    'taxonomy_term_ids' => $termIds,
                    'groups_count' => $groups->count(),
                    'custom_fields_count' => $customFields->count(),
                    'groups' => $groups,
                    'custom_fields' => $customFields,
                ]
            );
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
            $request->validate([
                'post_type_id' => ['required', 'integer', 'exists:post_types,id'],
                'taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'taxonomy_term_ids' => ['nullable'],
                'custom_fields' => ['nullable', 'array'],
                'custom_fields.*.custom_field_id' => ['required_with:custom_fields', 'integer', 'exists:custom_fields,id'],
                'custom_fields.*.custom_field_option_id' => ['nullable', 'integer', 'exists:custom_field_options,id'],
                'custom_fields.*.value_text' => ['nullable'],
                'custom_fields.*.value_string' => ['nullable'],
                'custom_fields.*.value_number' => ['nullable'],
                'custom_fields.*.value_date' => ['nullable'],
                'custom_fields.*.value_datetime' => ['nullable'],
                'custom_fields.*.value_json' => ['nullable'],
            ]);

            $postTypeId = (int) $request->post_type_id;
            $termIds = $this->normalizeIds($request->taxonomy_term_ids);

            $taxonomyIds = [];

            if ($request->filled('taxonomy_id')) {
                $taxonomyIds[] = (int) $request->taxonomy_id;
            }

            if (!empty($termIds)) {
                $termTaxonomyIds = TaxonomyTerm::whereIn('id', $termIds)
                    ->pluck('taxonomy_id')
                    ->map(fn($id) => (int) $id)
                    ->toArray();

                $taxonomyIds = array_values(array_unique(array_merge($taxonomyIds, $termTaxonomyIds)));
            }

            $groups = CustomFieldGroup::query()
                ->with([
                    'locationRules',
                    'fields' => function ($fieldQuery) {
                        $fieldQuery
                            ->where('status', true)
                            ->orderBy('sort_order', 'asc')
                            ->orderBy('id', 'asc')
                            ->with([
                                'options' => function ($optionQuery) {
                                    $optionQuery
                                        ->where('status', true)
                                        ->orderBy('sort_order', 'asc')
                                        ->orderBy('id', 'asc');
                                },
                                'repeaters' => function ($repeaterQuery) {
                                    $repeaterQuery
                                        ->where('status', true)
                                        ->orderBy('sort_order', 'asc')
                                        ->orderBy('id', 'asc')
                                        ->with([
                                            'options' => function ($optionQuery) {
                                                $optionQuery
                                                    ->where('status', true)
                                                    ->orderBy('sort_order', 'asc')
                                                    ->orderBy('id', 'asc');
                                            }
                                        ]);
                                },
                            ]);
                    },
                ])
                ->whereHas('locationRules', function ($ruleQuery) use ($postTypeId) {
                    $ruleQuery
                        ->where('show_if', 'post_type')
                        ->where(function ($subQuery) use ($postTypeId) {
                            $subQuery
                                ->where('match_type', 'all')
                                ->orWhere(function ($specificQuery) use ($postTypeId) {
                                    $specificQuery
                                        ->where('match_type', 'specific')
                                        ->where('post_type_id', $postTypeId);
                                });
                        });
                })
                ->when(!empty($taxonomyIds) || !empty($termIds), function ($groupQuery) use ($taxonomyIds, $termIds) {
                    $groupQuery->where(function ($taxonomyGroupQuery) use ($taxonomyIds, $termIds) {
                        $taxonomyGroupQuery
                            ->whereDoesntHave('locationRules', function ($ruleQuery) {
                                $ruleQuery->where('show_if', 'taxonomy');
                            })
                            ->orWhereHas('locationRules', function ($ruleQuery) use ($taxonomyIds, $termIds) {
                                $ruleQuery
                                    ->where('show_if', 'taxonomy')
                                    ->where(function ($subQuery) use ($taxonomyIds, $termIds) {
                                        $subQuery
                                            ->where('match_type', 'all')
                                            ->orWhere(function ($specificQuery) use ($taxonomyIds, $termIds) {
                                                $specificQuery->where('match_type', 'specific');

                                                if (!empty($taxonomyIds)) {
                                                    $specificQuery->whereIn('taxonomy_id', $taxonomyIds);
                                                }

                                                if (!empty($termIds)) {
                                                    $specificQuery->where(function ($termQuery) use ($termIds) {
                                                        foreach ($termIds as $termId) {
                                                            $termQuery->orWhereJsonContains('taxonomy_term_ids', $termId);
                                                        }

                                                        $termQuery
                                                            ->orWhereNull('taxonomy_term_ids')
                                                            ->orWhereRaw('JSON_LENGTH(taxonomy_term_ids) = 0');
                                                    });
                                                }
                                            });
                                    });
                            });
                    });
                })
                ->orderBy('id', 'asc')
                ->get();

            $fields = $groups
                ->flatMap(fn($group) => $group->fields)
                ->values();

            $fieldsById = $fields->keyBy('id');

            $selectedValues = [];

            foreach ($request->custom_fields ?? [] as $submittedField) {
                $fieldId = (int) $submittedField['custom_field_id'];

                if (!$fieldsById->has($fieldId)) {
                    continue;
                }

                $field = $fieldsById[$fieldId];

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
                $rules = $field->conditional_rules;

                $isVisible = $this->isConditionalFieldVisible($rules, $selectedValues);

                $fieldData = [
                    'id' => $field->id,
                    'field_label' => $field->field_label,
                    'field_name_slug' => $field->field_name_slug,
                    'field_type' => $field->field_type,
                    'required' => $field->required,
                    'conditional_rules' => $field->conditional_rules,
                    'options' => $field->options->map(fn($option) => [
                        'id' => $option->id,
                        'name' => $option->name,
                        'value' => $option->value,
                    ])->values(),
                ];

                if ($isVisible) {
                    $visibleFields[] = $fieldData;
                } else {
                    $fieldData['hidden_reason'] = 'Conditional rule not matched.';
                    $hiddenFields[] = $fieldData;
                }
            }

            $allowedFieldIds = collect($visibleFields)->pluck('id')->values()->toArray();

            $submittedFieldIds = collect($request->custom_fields ?? [])
                ->pluck('custom_field_id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            $unsupportedFieldIds = array_values(array_diff($submittedFieldIds, $allowedFieldIds));

            return $this->successResponse('Custom fields resolved successfully.', [
                'post_type_id' => $postTypeId,
                'taxonomy_ids' => $taxonomyIds,
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

        if ($action === 'hide') {
            return !$matched;
        }

        return $matched;
    }
}
