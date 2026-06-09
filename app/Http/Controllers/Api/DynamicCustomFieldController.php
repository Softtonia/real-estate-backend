<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterOption;
use App\Models\CustomFieldCondition;
use App\Models\PostType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DynamicCustomFieldController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = CustomField::query()
                ->with(['creator', 'postType', 'taxonomy', 'options', 'repeaters.options', 'conditions.taxonomy', 'conditions.taxonomyTerm'])
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
            $perPage = $perPage > 100 ? 100 : $perPage;

            return response()->json([
                'status' => true,
                'message' => 'Custom fields fetched successfully.',
                'data' => $query->paginate($perPage),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch custom fields.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
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

            return response()->json([
                'status' => true,
                'message' => 'Custom field created successfully.',
                'data' => $field->load(['creator', 'options', 'repeaters.options', 'conditions.taxonomy', 'conditions.taxonomyTerm']),
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
                'message' => 'Unable to create custom field.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(CustomField $customField)
    {
        try {
            $customField->load([
                'creator',
                'postType',
                'taxonomy',
                'options',
                'repeaters.options',
                'conditions.taxonomy',
                'conditions.taxonomyTerm',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Custom field fetched successfully.',
                'data' => $customField,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch custom field.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, CustomField $customField)
    {
        try {
            $validated = $this->validateField($request, $customField->id);

            DB::transaction(function () use ($customField, $validated) {
                $options = $validated['options'] ?? [];
                $repeaters = $validated['repeaters'] ?? [];
                $conditions = $validated['conditions'] ?? [];

                unset($validated['options'], $validated['repeaters'], $validated['conditions']);

                $validated['field_name_slug'] = $validated['field_name_slug'] ?? Str::slug($validated['field_label'], '_');

                $customField->update($validated);

                $customField->options()->delete();
                $customField->repeaters()->delete();
                $customField->conditions()->delete();

                $this->saveOptions($customField, $options);
                $this->saveRepeaters($customField, $repeaters);
                $this->saveConditions($customField, $conditions);
            });

            return response()->json([
                'status' => true,
                'message' => 'Custom field updated successfully.',
                'data' => $customField->fresh()->load(['creator', 'options', 'repeaters.options', 'conditions.taxonomy', 'conditions.taxonomyTerm']),
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
                'message' => 'Unable to update custom field.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(CustomField $customField)
    {
        try {
            $customField->delete();

            return response()->json([
                'status' => true,
                'message' => 'Custom field deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete custom field.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'exists:custom_fields,id'],
            ]);

            DB::beginTransaction();

            $deleted = CustomField::whereIn('id', $request->ids)->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected custom fields deleted successfully.',
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
                'message' => 'Unable to delete selected custom fields.',
                'error' => $e->getMessage(),
            ], 500);
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
    public function fieldsByPostType($postType)
    {
        try {
            $postTypeData = PostType::where('id', $postType)
                ->orWhere('slug', $postType)
                ->orWhere('name', $postType)
                ->first();

            if (!$postTypeData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $fields = \App\Models\CustomField::where('entity_type', 'post')
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

            return response()->json([
                'status' => true,
                'message' => 'Custom fields fetched by post type successfully.',
                'post_type' => [
                    'id' => $postTypeData->id,
                    'name' => $postTypeData->name,
                    'slug' => $postTypeData->slug,
                ],
                'data' => $fields,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch custom fields by post type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
