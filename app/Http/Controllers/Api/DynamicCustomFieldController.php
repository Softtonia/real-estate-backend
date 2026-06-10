<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterOption;
use App\Models\CustomFieldCondition;
use App\Models\PostType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DynamicCustomFieldController extends Controller
{
    private array $fieldRelations = [
        'creator',
        'postType',
        'taxonomy',
        'options',
        'repeaters.options',
        'conditions.taxonomy',
        'conditions.taxonomyTerm',
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

    private function findCustomField(int|string $id): ?CustomField
    {
        return CustomField::where('id', $id)->first();
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'entity_type' => ['nullable', Rule::in(['post', 'taxonomy'])],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'group_id' => ['nullable', 'integer'],
                'field_type' => ['nullable', 'string'],
                'status' => ['nullable', 'boolean'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = CustomField::query()
                ->with($this->fieldRelations)
                ->when($request->filled('entity_type'), function ($q) use ($request) {
                    $q->where('entity_type', $request->entity_type);
                })
                ->when($request->filled('post_type_id'), function ($q) use ($request) {
                    $q->where('post_type_id', $request->post_type_id);
                })
                ->when($request->filled('taxonomy_id'), function ($q) use ($request) {
                    $q->where('taxonomy_id', $request->taxonomy_id);
                })
                ->when($request->filled('group_id'), function ($q) use ($request) {
                    $q->where('group_id', $request->group_id);
                })
                ->when($request->filled('field_type'), function ($q) use ($request) {
                    $q->where('field_type', $request->field_type);
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
                })
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc');

            $perPage = (int) $request->get('per_page', 15);

            return $this->successResponse('Custom fields fetched successfully.', $query->paginate($perPage));
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom fields.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom fields.', 500, $e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $this->validateField($request);

            $field = DB::transaction(function () use ($validated) {
                $options = $validated['options'] ?? [];
                $repeaters = $validated['repeaters'] ?? [];
                $conditions = $validated['conditions'] ?? [];

                unset($validated['options'], $validated['repeaters'], $validated['conditions']);

                $validated['field_name_slug'] = $validated['field_name_slug'] ?? Str::slug($validated['field_label'], '_');
                $validated['created_by'] = Auth::id();
                $validated['status'] = $validated['status'] ?? true;
                $validated['sort_order'] = $validated['sort_order'] ?? 0;

                $field = CustomField::create($validated);

                $this->saveOptions($field, $options);
                $this->saveRepeaters($field, $repeaters);
                $this->saveConditions($field, $conditions);

                return $field;
            });

            return $this->successResponse(
                'Custom field created successfully.',
                $field->load($this->fieldRelations),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while creating custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to create custom field.', 500, $e->getMessage());
        }
    }

    public function show(int|string $customField): JsonResponse
    {
        try {
            $field = $this->findCustomField($customField);

            if (!$field) {
                return $this->errorResponse('Custom field not found.', 404, 'No custom field exists with this id.', [
                    'id' => $customField,
                ]);
            }

            $field->load($this->fieldRelations);

            return $this->successResponse('Custom field fetched successfully.', $field);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field.', 500, $e->getMessage());
        }
    }

    public function update(Request $request, int|string $customField): JsonResponse
    {
        try {
            $field = $this->findCustomField($customField);

            if (!$field) {
                return $this->errorResponse('Custom field not found.', 404, 'No custom field exists with this id.', [
                    'id' => $customField,
                ]);
            }

            $validated = $this->validateField($request, $field->id);

            DB::transaction(function () use ($field, $validated) {
                $options = $validated['options'] ?? [];
                $repeaters = $validated['repeaters'] ?? [];
                $conditions = $validated['conditions'] ?? [];

                unset($validated['options'], $validated['repeaters'], $validated['conditions']);

                $validated['field_name_slug'] = $validated['field_name_slug'] ?? Str::slug($validated['field_label'], '_');

                $field->update($validated);

                $field->options()->delete();
                $field->repeaters()->delete();
                $field->conditions()->delete();

                $this->saveOptions($field, $options);
                $this->saveRepeaters($field, $repeaters);
                $this->saveConditions($field, $conditions);
            });

            return $this->successResponse(
                'Custom field updated successfully.',
                $field->fresh()->load($this->fieldRelations)
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while updating custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to update custom field.', 500, $e->getMessage());
        }
    }

    public function destroy(int|string $customField): JsonResponse
    {
        try {
            $field = $this->findCustomField($customField);

            if (!$field) {
                return $this->errorResponse('Custom field not found.', 404, 'No custom field exists with this id.', [
                    'id' => $customField,
                ]);
            }

            $field->delete();

            return $this->successResponse('Custom field deleted successfully.');
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete custom field.', 500, $e->getMessage());
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

            $existingIds = CustomField::whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            $missingIds = array_values(array_diff($ids, $existingIds));

            if (!empty($missingIds)) {
                return $this->errorResponse('Some custom fields were not found.', 404, 'One or more ids do not exist.', [
                    'missing_ids' => $missingIds,
                ]);
            }

            $deleted = DB::transaction(function () use ($existingIds) {
                return CustomField::whereIn('id', $existingIds)->delete();
            });

            return $this->successResponse('Selected custom fields deleted successfully.', null, 200, [
                'deleted_count' => $deleted,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting selected custom fields.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete selected custom fields.', 500, $e->getMessage());
        }
    }

    private function validateField(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'group_id' => ['nullable', 'integer'],

            'entity_type' => ['required', Rule::in(['post', 'taxonomy'])],

            'post_type_id' => [
                'nullable',
                'required_if:entity_type,post',
                'exists:post_types,id',
            ],

            'taxonomy_id' => [
                'nullable',
                'required_if:entity_type,taxonomy',
                'exists:taxonomies,id',
            ],

            'field_label' => ['required', 'string', 'max:255'],

            'field_name_slug' => [
                'nullable',
                'string',
                'max:255',
            ],

            'field_placeholder' => ['nullable', 'string', 'max:255'],

            'field_type' => [
                'required',
                Rule::in([
                    'text',
                    'texteditor',
                    'textarea',
                    'number',
                    'email',
                    'url',
                    'date',
                    'datetime',
                    'boolean',
                    'checkbox',
                    'radio',
                    'select',
                    'repeater',
                    'media',
                    'file',
                ]),
            ],

            'required' => ['nullable', Rule::in(['yes', 'no'])],
            'checkbox_type' => ['nullable', 'string', 'max:100'],
            'default_value' => ['nullable', 'string'],
            'validation_rules' => ['nullable', 'array'],
            'conditional_rules' => ['nullable', 'array'],
            'template_id' => ['nullable', 'integer'],
            'media_limit' => ['nullable', 'integer'],
            'media_size' => ['nullable', 'string', 'max:100'],
            'media_format' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],

            'options' => ['nullable', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:150'],
            'options.*.value' => ['required_with:options', 'string', 'max:150'],
            'options.*.type' => ['nullable', 'string', 'max:50'],
            'options.*.sort_order' => ['nullable', 'integer'],
            'options.*.status' => ['nullable', 'boolean'],

            'repeaters' => ['nullable', 'array'],
            'repeaters.*.field_label' => ['required_with:repeaters', 'string', 'max:255'],
            'repeaters.*.field_name_slug' => ['nullable', 'string', 'max:255'],
            'repeaters.*.field_placeholder' => ['nullable', 'string', 'max:255'],
            'repeaters.*.field_type' => [
                'required_with:repeaters',
                Rule::in([
                    'text',
                    'texteditor',
                    'textarea',
                    'number',
                    'email',
                    'url',
                    'date',
                    'datetime',
                    'boolean',
                    'checkbox',
                    'radio',
                    'select',
                    'media',
                    'file',
                ]),
            ],
            'repeaters.*.media_limit' => ['nullable', 'integer'],
            'repeaters.*.media_size' => ['nullable', 'string', 'max:100'],
            'repeaters.*.media_format' => ['nullable', 'string', 'max:255'],
            'repeaters.*.sort_order' => ['nullable', 'integer'],
            'repeaters.*.status' => ['nullable', 'boolean'],

            'repeaters.*.options' => ['nullable', 'array'],
            'repeaters.*.options.*.name' => ['required_with:repeaters.*.options', 'string', 'max:150'],
            'repeaters.*.options.*.value' => ['required_with:repeaters.*.options', 'string', 'max:150'],
            'repeaters.*.options.*.type' => ['nullable', 'string', 'max:50'],
            'repeaters.*.options.*.sort_order' => ['nullable', 'integer'],
            'repeaters.*.options.*.status' => ['nullable', 'boolean'],

            'conditions' => ['nullable', 'array'],
            'conditions.*.taxonomy_id' => ['required_with:conditions', 'exists:taxonomies,id'],
            'conditions.*.taxonomy_term_id' => ['required_with:conditions', 'exists:taxonomy_terms,id'],
            'conditions.*.operator' => ['nullable', Rule::in(['include', 'exclude'])],
        ]);
    }

    private function saveOptions(CustomField $field, array $options): void
    {
        foreach ($options as $index => $option) {
            CustomFieldOption::create([
                'custom_field_id' => $field->id,
                'type' => $option['type'] ?? $field->field_type,
                'name' => $option['name'],
                'value' => $option['value'],
                'sort_order' => $option['sort_order'] ?? $index,
                'status' => $option['status'] ?? true,
            ]);
        }
    }

    private function saveRepeaters(CustomField $field, array $repeaters): void
    {
        foreach ($repeaters as $index => $repeaterData) {
            $repeaterOptions = $repeaterData['options'] ?? [];

            unset($repeaterData['options']);

            $repeaterData['custom_field_id'] = $field->id;
            $repeaterData['group_id'] = $field->group_id;
            $repeaterData['field_name_slug'] = $repeaterData['field_name_slug']
                ?? Str::slug($repeaterData['field_label'], '_');
            $repeaterData['sort_order'] = $repeaterData['sort_order'] ?? $index;
            $repeaterData['status'] = $repeaterData['status'] ?? true;

            $repeater = CustomFieldRepeater::create($repeaterData);

            foreach ($repeaterOptions as $optionIndex => $option) {
                CustomFieldRepeaterOption::create([
                    'custom_field_repeater_id' => $repeater->id,
                    'type' => $option['type'] ?? $repeater->field_type,
                    'name' => $option['name'],
                    'value' => $option['value'],
                    'sort_order' => $option['sort_order'] ?? $optionIndex,
                    'status' => $option['status'] ?? true,
                ]);
            }
        }
    }

    private function saveConditions(CustomField $field, array $conditions): void
    {
        foreach ($conditions as $condition) {
            CustomFieldCondition::create([
                'custom_field_id' => $field->id,
                'taxonomy_id' => $condition['taxonomy_id'],
                'taxonomy_term_id' => $condition['taxonomy_term_id'],
                'operator' => $condition['operator'] ?? 'include',
            ]);
        }
    }

    public function fieldsByPostType(int|string $postType): JsonResponse
    {
        try {
            if (blank($postType)) {
                return $this->errorResponse('Post type is required.', 422, 'Please provide post type id, slug, or name.');
            }

            $postTypeData = PostType::query()
                ->where(function ($q) use ($postType) {
                    if (is_numeric($postType)) {
                        $q->where('id', $postType);
                    }

                    $q->orWhere('slug', $postType)
                        ->orWhere('name', $postType);
                })
                ->first();

            if (!$postTypeData) {
                return $this->errorResponse('Post type not found.', 404, 'No post type exists with this id, slug, or name.', [
                    'post_type' => $postType,
                ]);
            }

            $fields = CustomField::where('entity_type', 'post')
                ->where('post_type_id', $postTypeData->id)
                ->where('status', true)
                ->with([
                    'options' => function ($q) {
                        $q->where('status', true)
                            ->orderBy('sort_order', 'asc');
                    },
                    'repeaters' => function ($q) {
                        $q->where('status', true)
                            ->orderBy('sort_order', 'asc');
                    },
                    'repeaters.options' => function ($q) {
                        $q->where('status', true)
                            ->orderBy('sort_order', 'asc');
                    },
                    'conditions.taxonomy',
                    'conditions.taxonomyTerm',
                ])
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return $this->successResponse('Custom fields fetched by post type successfully.', $fields, 200, [
                'post_type' => [
                    'id' => $postTypeData->id,
                    'name' => $postTypeData->name,
                    'slug' => $postTypeData->slug,
                ],
            ]);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom fields by post type.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom fields by post type.', 500, $e->getMessage());
        }
    }
}