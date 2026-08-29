<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldGroup;
use App\Models\CustomFieldGroupLocationRule;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldCondition;
use App\Models\CustomFieldRepeaterOption;
use App\Models\PostType;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use App\Services\CustomFieldValueService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomFieldGroupController extends Controller
{
    private array $groupRelations = [
        'creator',
        'locationRules.postType',
        'locationRules.taxonomy',
        'fields.options',
        'fields.repeaters.options',
        'fields.locationRules',
        'fields.conditions.taxonomy',
        'fields.conditions.taxonomyTerm',
    ];

    // ──────────────────────────────────────────────
    //  GROUP CRUD
    // ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = CustomFieldGroup::query()
                ->with($this->groupRelations)
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;
                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('group_name', 'like', "%{$search}%")
                            ->orWhere('group_slug', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('post_type_id'), function ($q) use ($request) {
                    $q->where(function ($groupQ) use ($request) {
                        $groupQ->whereHas('locationRules', function ($ruleQuery) use ($request) {
                            $this->scopePostTypeRule($ruleQuery, $request->post_type_id);
                        })->orWhereHas('fields.locationRules', function ($ruleQuery) use ($request) {
                            $this->scopePostTypeRule($ruleQuery, $request->post_type_id);
                        });
                    });
                })
                ->when($request->filled('taxonomy_id'), function ($q) use ($request) {
                    $q->where(function ($groupQ) use ($request) {
                        $groupQ->whereHas('locationRules', function ($ruleQuery) use ($request) {
                            $this->scopeTaxonomyRule($ruleQuery, $request->taxonomy_id);
                        })->orWhereHas('fields.locationRules', function ($ruleQuery) use ($request) {
                            $this->scopeTaxonomyRule($ruleQuery, $request->taxonomy_id);
                        });
                    });
                })
                ->orderBy('id', 'desc');

            $perPage = (int) $request->get('per_page', 15);
            $groups = $query->paginate($perPage);

            $groups->getCollection()->transform(fn($group) => $this->formatGroup($group));

            return $this->successResponse('Custom field groups fetched successfully.', $groups);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom field groups.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field groups.', 500, $e->getMessage());
        }
    }

    /**
     * Paginated list of all custom fields (independent of groups).
     */
    public function fieldsIndex(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'field_type' => ['nullable', 'string', Rule::in($this->fieldTypesArray())],
                'group_id' => ['nullable', 'integer', 'exists:custom_field_groups,id'],
                'status' => ['nullable', 'boolean'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'sort_by' => ['nullable', Rule::in(['field_label', 'field_type', 'sort_order', 'created_at', 'updated_at'])],
                'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            ]);

            // Summary counts
            $totalCustomFields = CustomField::count();
            $activeFields = CustomField::where('status', true)->count();
            $inactiveFields = CustomField::where('status', false)->count();
            $fieldGroups = CustomFieldGroup::count();

            $query = CustomField::with([
                'group:id,group_name,group_slug',
                'options',
                'repeaters.options',
                'locationRules',
                'conditions.taxonomy',
                'conditions.taxonomyTerm',
                'creator',
            ]);

            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('field_label', 'like', "%{$search}%")
                        ->orWhere('field_name_slug', 'like', "%{$search}%");
                });
            }

            if ($request->filled('field_type')) {
                $query->where('field_type', $request->field_type);
            }

            if ($request->filled('group_id')) {
                $query->where('custom_field_group_id', $request->group_id);
            }

            if ($request->filled('status')) {
                $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
            }

            $sortBy = $request->get('sort_by', 'sort_order');
            $sortOrder = $request->get('sort_order', 'asc');

            $query->orderBy($sortBy, $sortOrder)
                ->orderBy('id', 'asc');

            $perPage = (int) $request->get('per_page', 15);

            $fields = $query->paginate($perPage);

            $fields->getCollection()->transform(function ($field) {
                $data = $this->formatField($field);

                $data['group_id'] = $field->custom_field_group_id;
                $data['group_name'] = $field->group?->group_name;
                $data['group_slug'] = $field->group?->group_slug;

                return $data;
            });

            return $this->successResponse('Custom fields fetched successfully.', $fields, 200, [
                'summary' => [
                    'total_custom_fields' => $totalCustomFields,
                    'active_fields' => $activeFields,
                    'inactive_fields' => $inactiveFields,
                    'field_groups' => $fieldGroups,
                ],
            ]);
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
            $validated = $this->validateGroupStore($request);

            $group = DB::transaction(function () use ($validated) {
                $fields = $validated['fields'] ?? [];
                $locationRules = $validated['location_rules'] ?? [];

                unset($validated['location_rules'], $validated['fields']);

                $group = CustomFieldGroup::create([
                    'group_name' => $validated['group_name'],
                    'group_slug' => !empty($validated['group_slug'])
                        ? CustomFieldGroup::generateUniqueSlug($validated['group_slug'])
                        : CustomFieldGroup::generateUniqueSlug($validated['group_name']),
                    'created_by' => Auth::id(),
                ]);

                $this->saveLocationRules($group->id, null, $locationRules);

                $this->saveFields($group, $fields);

                return $group;
            });

            return $this->successResponse(
                'Custom field group created successfully.',
                $this->formatGroup($group->fresh()->load($this->groupRelations)),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while creating custom field group.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to create custom field group.', 500, $e->getMessage());
        }
    }

    public function show(int|string $id): JsonResponse
    {
        try {
            $group = CustomFieldGroup::with($this->groupRelations)->find($id);

            if (!$group) {
                return $this->errorResponse('Custom field group not found.', 404);
            }

            return $this->successResponse('Custom field group fetched successfully.', $this->formatGroup($group));
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom field group.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field group.', 500, $e->getMessage());
        }
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        try {
            $group = CustomFieldGroup::find($id);

            if (!$group) {
                return $this->errorResponse('Custom field group not found.', 404);
            }

            $validated = $this->validateGroupUpdate($request, $group);

            DB::transaction(function () use ($group, $validated) {
                $updateData = [];

                if (isset($validated['group_name'])) {
                    $updateData['group_name'] = $validated['group_name'];
                }

                if (isset($validated['group_slug'])) {
                    $updateData['group_slug'] = CustomFieldGroup::generateUniqueSlug($validated['group_slug'], $group->id);
                } elseif (isset($validated['group_name']) && $validated['group_name'] !== $group->group_name) {
                    $updateData['group_slug'] = CustomFieldGroup::generateUniqueSlug($validated['group_name'], $group->id);
                }

                if (!empty($updateData)) {
                    $group->update($updateData);
                }

                if (array_key_exists('location_rules', $validated)) {
                    $group->locationRules()
                        ->whereNull('custom_field_id')
                        ->delete();

                    $this->saveLocationRules(
                        $group->id,
                        null,
                        $validated['location_rules'] ?? []
                    );
                }

                if (isset($validated['fields'])) {
                    $this->syncFields($group, $validated['fields']);
                }
            });

            return $this->successResponse(
                'Custom field group updated successfully.',
                $this->formatGroup($group->fresh()->load($this->groupRelations))
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while updating custom field group.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to update custom field group.', 500, $e->getMessage());
        }
    }
    public function updateFieldById(Request $request, int|string $fieldId): JsonResponse
    {
        try {
            $field = CustomField::find($fieldId);

            if (!$field) {
                return $this->errorResponse('Custom field not found.', 404);
            }

            $validated = $this->validateFieldData($request, true);

            DB::transaction(function () use ($field, $validated) {
                $updateData = [];

                foreach (
                    [
                        'field_label',
                        'field_placeholder',
                        'field_type',
                        'required',
                        'checkbox_type',
                        'default_value',
                        'validation_rules',
                        'conditional_rules',
                        'media_limit',
                        'media_size',
                        'media_format',
                        'has_featured',
                        'sort_order',
                        'status'
                    ] as $key
                ) {
                    if (array_key_exists($key, $validated)) {
                        $updateData[$key] = $validated[$key];
                    }
                }

                if (isset($validated['field_name_slug']) || isset($validated['field_label'])) {
                    $updateData['field_name_slug'] = $this->resolveFieldSlug(
                        $field->custom_field_group_id,
                        [
                            'field_name_slug' => $validated['field_name_slug'] ?? $field->field_name_slug,
                            'field_label' => $validated['field_label'] ?? $field->field_label,
                        ],
                        $field->id
                    );
                }

                if (!empty($updateData)) {
                    $field->update($updateData);
                }

                if (isset($validated['location_rules'])) {
                    $field->locationRules()->delete();
                    $this->saveLocationRules($field->custom_field_group_id, $field->id, $validated['location_rules']);
                }

                if (isset($validated['options'])) {
                    $field->options()->delete();
                    $this->saveOptions($field, $validated['options']);
                }

                if (isset($validated['repeaters'])) {
                    $field->repeaters()->delete();
                    $this->saveRepeaters($field, $validated['repeaters']);
                }

                if (isset($validated['conditions'])) {
                    $field->conditions()->delete();
                    $this->saveConditions($field, $validated['conditions']);
                }
            });

            return $this->successResponse(
                'Custom field updated successfully.',
                $this->formatField($field->fresh()->load([
                    'options',
                    'repeaters.options',
                    'locationRules',
                    'conditions.taxonomy',
                    'conditions.taxonomyTerm'
                ]))
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while updating custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to update custom field.', 500, $e->getMessage());
        }
    }

    public function destroy(int|string $id): JsonResponse
    {
        try {
            $group = CustomFieldGroup::find($id);
            if (!$group) {
                return $this->errorResponse('Custom field group not found.', 404);
            }

            $group->delete();

            return $this->successResponse('Custom field group deleted successfully.');
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting custom field group.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete custom field group.', 500, $e->getMessage());
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'exists:custom_field_groups,id'],
            ]);

            $ids = array_values(array_unique($request->ids));
            $deleted = DB::transaction(function () use ($ids) {
                $groups = CustomFieldGroup::whereIn('id', $ids)->get();
                $count = 0;
                foreach ($groups as $group) {
                    $group->delete();
                    $count++;
                }
                return $count;
            });

            return $this->successResponse('Selected custom field groups deleted successfully.', null, 200, [
                'deleted_count' => $deleted,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting selected custom field groups.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete selected custom field groups.', 500, $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  PER-FIELD CRUD
    // ──────────────────────────────────────────────

    public function storeField(Request $request, int|string $groupId): JsonResponse
    {
        try {
            $group = CustomFieldGroup::find($groupId);
            if (!$group) {
                return $this->errorResponse('Custom field group not found.', 404);
            }

            $validated = $this->validateFieldData($request);

            $field = DB::transaction(function () use ($group, $validated) {
                $locationRules = $validated['location_rules'] ?? [];
                $options = $validated['options'] ?? [];
                $repeaters = $validated['repeaters'] ?? [];
                $conditions = $validated['conditions'] ?? [];

                unset($validated['location_rules'], $validated['options'], $validated['repeaters'], $validated['conditions']);

                $field = CustomField::create(array_merge($validated, [
                    'custom_field_group_id' => $group->id,
                    'field_name_slug' => $this->resolveFieldSlug($group->id, $validated),
                    'sort_order' => $validated['sort_order'] ?? CustomField::where('custom_field_group_id', $group->id)->max('sort_order') + 1,
                    'status' => $validated['status'] ?? true,
                    'created_by' => Auth::id(),
                ]));

                $this->saveLocationRules($group->id, $field->id, $locationRules);
                $this->saveOptions($field, $options);
                $this->saveRepeaters($field, $repeaters);
                $this->saveConditions($field, $conditions);

                return $field;
            });

            return $this->successResponse(
                'Field added successfully.',
                $this->formatField($field->fresh()->load(['options', 'repeaters.options', 'locationRules', 'conditions'])),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to add field.', 500, $e->getMessage());
        }
    }

    public function updateField(Request $request, int|string $groupId, int|string $fieldId): JsonResponse
    {
        try {
            $field = CustomField::where('custom_field_group_id', $groupId)->find($fieldId);
            if (!$field) {
                return $this->errorResponse('Field not found in this group.', 404);
            }

            $validated = $this->validateFieldData($request, true);

            DB::transaction(function () use ($field, $validated) {
                $updateData = [];

                foreach (
                    [
                        'field_label',
                        'field_placeholder',
                        'field_type',
                        'required',
                        'checkbox_type',
                        'default_value',
                        'validation_rules',
                        'conditional_rules',
                        'media_limit',
                        'media_size',
                        'media_format',
                        'has_featured',
                        'sort_order',
                        'status'
                    ] as $key
                ) {
                    if (array_key_exists($key, $validated)) {
                        $updateData[$key] = $validated[$key];
                    }
                }

                if (isset($validated['field_name_slug'])) {
                    $updateData['field_name_slug'] = Str::slug($validated['field_name_slug'], '_');
                } elseif (isset($validated['field_label'])) {
                    $updateData['field_name_slug'] = Str::slug($validated['field_label'], '_');
                }

                if (!empty($updateData)) {
                    $field->update($updateData);
                }

                if (isset($validated['location_rules'])) {
                    $field->locationRules()->delete();
                    $this->saveLocationRules($field->custom_field_group_id, $field->id, $validated['location_rules']);
                }

                if (isset($validated['options'])) {
                    $field->options()->delete();
                    $this->saveOptions($field, $validated['options']);
                }

                if (isset($validated['repeaters'])) {
                    $field->repeaters()->delete();
                    $this->saveRepeaters($field, $validated['repeaters']);
                }

                if (isset($validated['conditions'])) {
                    $field->conditions()->delete();
                    $this->saveConditions($field, $validated['conditions']);
                }
            });

            return $this->successResponse(
                'Field updated successfully.',
                $this->formatField($field->fresh()->load(['options', 'repeaters.options', 'locationRules', 'conditions']))
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to update field.', 500, $e->getMessage());
        }
    }

    public function destroyField(int|string $groupId, int|string $fieldId): JsonResponse
    {
        try {
            $field = CustomField::where('custom_field_group_id', $groupId)->find($fieldId);
            if (!$field) {
                return $this->errorResponse('Field not found in this group.', 404);
            }

            $field->delete();

            return $this->successResponse('Field deleted successfully.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete field.', 500, $e->getMessage());
        }
    }

    public function reorderFields(Request $request, int|string $groupId): JsonResponse
    {
        try {
            $request->validate([
                'fields' => ['required', 'array', 'min:1'],
                'fields.*.id' => ['required', 'integer', 'exists:custom_fields,id'],
                'fields.*.sort_order' => ['required', 'integer', 'min:0'],
            ]);

            DB::transaction(function () use ($request, $groupId) {
                foreach ($request->fields as $fieldData) {
                    CustomField::where('custom_field_group_id', $groupId)
                        ->where('id', $fieldData['id'])
                        ->update(['sort_order' => $fieldData['sort_order']]);
                }
            });

            return $this->successResponse('Fields reordered successfully.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to reorder fields.', 500, $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  FIELD VALUE VALIDATION
    // ──────────────────────────────────────────────

    private function validateFieldValues(array $customFields): void
    {
        foreach ($customFields as $field) {
            $customField = CustomField::find($field['custom_field_id'] ?? 0);
            if (!$customField)
                continue;

            $value = $field['value'] ?? $field['value_text'] ?? $field['value_string']
                ?? $field['value_number'] ?? $field['value_date']
                ?? $field['value_datetime'] ?? $field['value_json'] ?? null;

            $error = CustomFieldValueService::validateFieldValue($customField, $value, $customField->isRequired());

            if ($error) {
                throw ValidationException::withMessages([
                    "custom_fields.{$customField->field_name_slug}" => [$error],
                ]);
            }
        }
    }

    public function destroyFieldById(int|string $fieldId): JsonResponse
    {
        try {
            $field = CustomField::find($fieldId);

            if (!$field) {
                return $this->errorResponse('Custom field not found.', 404);
            }

            DB::transaction(function () use ($field) {
                // Delete field location rules
                $field->locationRules()->delete();

                // Delete field options
                $field->options()->delete();

                // Delete repeater options first
                $repeaterIds = $field->repeaters()->pluck('id')->toArray();

                if (!empty($repeaterIds)) {
                    CustomFieldRepeaterOption::whereIn('custom_field_repeater_id', $repeaterIds)->delete();
                }

                // Delete repeaters
                $field->repeaters()->delete();

                // Delete field conditions
                $field->conditions()->delete();

                // Delete field values if relation exists
                if (method_exists($field, 'values')) {
                    $field->values()->delete();
                }

                // Delete custom field
                $field->delete();
            });

            return $this->successResponse('Custom field deleted successfully.');
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete custom field.', 500, $e->getMessage());
        }
    }

    public function deleteCustomFieldsById(Request $request, int|string|null $fieldId = null): JsonResponse
    {
        return $this->bulkDeleteFieldsWithRouteId($request, $fieldId);
    }

    public function bulkDeleteFields(Request $request): JsonResponse
    {
        return $this->bulkDeleteFieldsWithRouteId($request, null);
    }

    private function bulkDeleteFieldsWithRouteId(Request $request, int|string|null $routeId = null): JsonResponse
    {
        try {
            $ids = $this->parseCustomFieldIdsFromRequest($request, $routeId);

            if (empty($ids)) {
                return $this->errorResponse('Field ID is required.', 400);
            }

            $existingFields = CustomField::whereIn('id', $ids)->get();

            if ($existingFields->isEmpty()) {
                return $this->errorResponse('Custom field not found.', 404);
            }

            $existingIds = $existingFields->pluck('id')->toArray();

            $deletedCount = DB::transaction(function () use ($existingIds) {
                // Delete field location rules
                CustomFieldGroupLocationRule::whereIn('custom_field_id', $existingIds)->delete();

                // Delete field options
                CustomFieldOption::whereIn('custom_field_id', $existingIds)->delete();

                // Delete repeater options first
                $repeaterIds = CustomFieldRepeater::whereIn('custom_field_id', $existingIds)
                    ->pluck('id')
                    ->toArray();

                if (!empty($repeaterIds)) {
                    CustomFieldRepeaterOption::whereIn('custom_field_repeater_id', $repeaterIds)->delete();
                }

                // Delete repeaters
                CustomFieldRepeater::whereIn('custom_field_id', $existingIds)->delete();

                // Delete field conditions
                CustomFieldCondition::whereIn('custom_field_id', $existingIds)->delete();

                // Delete custom fields
                return CustomField::whereIn('id', $existingIds)->delete();
            });

            $message = count($existingIds) === 1
                ? 'Custom field deleted successfully.'
                : 'Selected custom fields deleted successfully.';

            return $this->successResponse($message, null, 200, [
                'deleted_count' => $deletedCount,
            ]);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete custom field.', 500, $e->getMessage());
        }
    }

    private function parseCustomFieldIdsFromRequest(Request $request, int|string|null $routeId = null): array
    {
        $rawCandidates = [];

        if (!is_null($routeId) && $routeId !== '') {
            $rawCandidates[] = $routeId;
        }

        $keys = [
            'ids',
            'id',
            'field_id',
            'field_ids',
            'custom_field_id',
            'custom_field_ids',
            'fieldId',
            'fieldIds',
            'customFieldId',
            'customFieldIds',
            'items',
            'data'
        ];

        foreach ($keys as $key) {
            if ($request->has($key)) {
                $val = $request->input($key);
                if (!is_null($val) && $val !== '') {
                    $rawCandidates[] = $val;
                }
            }
        }

        if (empty($rawCandidates)) {
            $all = $request->all();
            foreach ($all as $k => $v) {
                if (!is_null($v) && $v !== '') {
                    $rawCandidates[] = $v;
                }
            }
        }

        $ids = [];
        foreach ($rawCandidates as $item) {
            $this->extractCustomFieldIdsFromItem($item, $ids);
        }

        return array_values(array_unique(array_filter($ids, fn($id) => is_int($id) && $id > 0)));
    }

    private function extractCustomFieldIdsFromItem(mixed $item, array &$ids): void
    {
        if (is_array($item)) {
            foreach ($item as $subItem) {
                $this->extractCustomFieldIdsFromItem($subItem, $ids);
            }
        } elseif (is_numeric($item)) {
            $ids[] = (int) $item;
        } elseif (is_string($item)) {
            $item = trim($item);
            if ($item === '') {
                return;
            }

            if (str_starts_with($item, '[') && str_ends_with($item, ']')) {
                $decoded = json_decode($item, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $subItem) {
                        $this->extractCustomFieldIdsFromItem($subItem, $ids);
                    }
                    return;
                }
            }

            if (str_contains($item, ',')) {
                $parts = explode(',', $item);
                foreach ($parts as $p) {
                    $this->extractCustomFieldIdsFromItem($p, $ids);
                }
                return;
            }

            $cleaned = trim($item, " \t\n\r\0\x0B\"'[]");
            if (is_numeric($cleaned)) {
                $ids[] = (int) $cleaned;
            }
        }
    }

    // ──────────────────────────────────────────────
    //  POST TYPE / TAXONOMY / TERM LISTING (for dropdowns)
    // ──────────────────────────────────────────────

    public function postTypesList(): JsonResponse
    {
        try {
            $postTypes = PostType::query()
                ->select('id', 'name', 'slug')
                ->active()
                ->ordered()
                ->get();

            return $this->successResponse('Post types fetched successfully.', $postTypes);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch post types.', 500, $e->getMessage());
        }
    }

    public function taxonomiesList(): JsonResponse
    {
        try {
            $taxonomies = Taxonomy::query()
                ->select('id', 'name', 'slug', 'hierarchical')
                ->active()
                ->ordered()
                ->get();

            return $this->successResponse('Taxonomies fetched successfully.', $taxonomies);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch taxonomies.', 500, $e->getMessage());
        }
    }

    public function taxonomyTermsList(int|string $taxonomyId): JsonResponse
    {
        try {
            $taxonomy = Taxonomy::query()
                ->select('id', 'name', 'slug')
                ->where(function ($q) use ($taxonomyId) {
                    if (is_numeric($taxonomyId))
                        $q->where('id', $taxonomyId);
                    $q->orWhere('slug', $taxonomyId);
                })
                ->first();

            if (!$taxonomy) {
                return $this->errorResponse('Taxonomy not found.', 404);
            }

            $terms = TaxonomyTerm::query()
                ->select('id', 'taxonomy_id', 'parent_id', 'name', 'slug')
                ->where('taxonomy_id', $taxonomy->id)
                ->active()
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return $this->successResponse('Taxonomy terms fetched successfully.', $terms, 200, [
                'taxonomy' => ['id' => $taxonomy->id, 'name' => $taxonomy->name, 'slug' => $taxonomy->slug],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch taxonomy terms.', 500, $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  FILTERING BY LOCATION
    // ──────────────────────────────────────────────

    public function groupsByPostType(int|string $postType): JsonResponse
    {
        try {
            $postTypeData = PostType::query()
                ->where(function ($q) use ($postType) {
                    if (is_numeric($postType))
                        $q->where('id', $postType);
                    $q->orWhere('slug', $postType)->orWhere('name', $postType);
                })
                ->first();

            if (!$postTypeData) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $groups = CustomFieldGroup::with($this->groupRelations)
                ->where(function ($q) use ($postTypeData) {
                    $q->whereHas('locationRules', fn($r) => $this->scopePostTypeRule($r, $postTypeData->id))
                        ->orWhereHas('fields.locationRules', fn($r) => $this->scopePostTypeRule($r, $postTypeData->id));
                })
                ->orderBy('id', 'asc')
                ->get()
                ->map(fn($group) => $this->formatGroup($group));

            return $this->successResponse('Custom field groups fetched by post type successfully.', $groups, 200, [
                'post_type' => ['id' => $postTypeData->id, 'name' => $postTypeData->name, 'slug' => $postTypeData->slug],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field groups by post type.', 500, $e->getMessage());
        }
    }

    public function groupsByTaxonomy(Request $request, int|string $taxonomy): JsonResponse
    {
        try {
            $request->validate([
                'taxonomy_term_ids' => ['nullable', 'array'],
                'taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            ]);

            $taxonomyData = Taxonomy::query()
                ->where(function ($q) use ($taxonomy) {
                    if (is_numeric($taxonomy))
                        $q->where('id', $taxonomy);
                    $q->orWhere('slug', $taxonomy)->orWhere('name', $taxonomy);
                })
                ->first();

            if (!$taxonomyData) {
                return $this->errorResponse('Taxonomy not found.', 404);
            }

            $selectedTermIds = collect($request->taxonomy_term_ids ?? [])->map(fn($id) => (int) $id)->toArray();

            $groups = CustomFieldGroup::with($this->groupRelations)
                ->where(function ($q) use ($taxonomyData, $selectedTermIds) {
                    $q->whereHas('locationRules', fn($r) => $this->scopeTaxonomyRuleWithTerms($r, $taxonomyData->id, $selectedTermIds))
                        ->orWhereHas('fields.locationRules', fn($r) => $this->scopeTaxonomyRuleWithTerms($r, $taxonomyData->id, $selectedTermIds));
                })
                ->orderBy('id', 'asc')
                ->get()
                ->map(fn($group) => $this->formatGroup($group));

            return $this->successResponse('Custom field groups fetched by taxonomy successfully.', $groups, 200, [
                'taxonomy' => ['id' => $taxonomyData->id, 'name' => $taxonomyData->name, 'slug' => $taxonomyData->slug],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field groups by taxonomy.', 500, $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  VALIDATION
    // ──────────────────────────────────────────────

    private function commonFieldValidationRules(): array
    {
        return [
            'location_rules' => ['nullable', 'array'],
            'location_rules.*.logic_operator' => ['nullable', Rule::in(['and', 'or'])],
            'location_rules.*.show_if' => ['required_with:location_rules', Rule::in(['post_type', 'taxonomy'])],
            'location_rules.*.operator' => ['nullable', Rule::in(['is_equal_to', 'is_not_equal_to'])],
            'location_rules.*.match_type' => ['nullable', Rule::in(['all', 'specific'])],
            'location_rules.*.post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'location_rules.*.taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
            'location_rules.*.taxonomy_term_ids' => ['nullable', 'array'],
            'location_rules.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

            'options' => ['nullable', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:150'],
            'options.*.value' => ['required_with:options', 'string', 'max:150'],
            'options.*.type' => ['nullable', 'string', 'max:50'],
            'options.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'options.*.status' => ['nullable', 'boolean'],

            'repeaters' => ['nullable', 'array'],
            'repeaters.*.field_label' => ['required_with:repeaters', 'string', 'max:255'],
            'repeaters.*.field_name_slug' => ['nullable', 'string', 'max:255'],
            'repeaters.*.field_placeholder' => ['nullable', 'string', 'max:255'],
            'repeaters.*.field_type' => ['required_with:repeaters', Rule::in($this->repeaterFieldTypesArray())],
            'repeaters.*.media_limit' => ['nullable', 'integer', 'min:1'],
            'repeaters.*.media_size' => ['nullable', 'string', 'max:100'],
            'repeaters.*.media_format' => ['nullable', 'string', 'max:255'],
            'repeaters.*.has_featured' => ['nullable', 'boolean'],
            'repeaters.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'repeaters.*.status' => ['nullable', 'boolean'],
            'repeaters.*.options' => ['nullable', 'array'],
            'repeaters.*.options.*.name' => ['required_with:repeaters.*.options', 'string', 'max:150'],
            'repeaters.*.options.*.value' => ['required_with:repeaters.*.options', 'string', 'max:150'],
            'repeaters.*.options.*.type' => ['nullable', 'string', 'max:50'],
            'repeaters.*.options.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'repeaters.*.options.*.status' => ['nullable', 'boolean'],

            'conditions' => ['nullable', 'array'],
            'conditions.*.taxonomy_id' => ['required_with:conditions', 'integer', 'exists:taxonomies,id'],
            'conditions.*.taxonomy_term_id' => ['required_with:conditions', 'integer', 'exists:taxonomy_terms,id'],
            'conditions.*.operator' => ['nullable', 'in:include,exclude'],
        ];
    }

    private function validateGroupStore(Request $request): array
    {
        $rules = [
            'group_name' => ['required', 'string', 'max:200'],
            'group_slug' => ['nullable', 'string', 'max:200'],

            'location_rules' => ['nullable', 'array'],
            'location_rules.*.logic_operator' => ['nullable', Rule::in(['and', 'or'])],
            'location_rules.*.show_if' => ['required_with:location_rules', Rule::in(['post_type', 'taxonomy'])],
            'location_rules.*.operator' => ['nullable', Rule::in(['is_equal_to', 'is_not_equal_to'])],
            'location_rules.*.match_type' => ['nullable', Rule::in(['all', 'specific'])],
            'location_rules.*.post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'location_rules.*.taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
            'location_rules.*.taxonomy_term_ids' => ['nullable', 'array'],
            'location_rules.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

            'fields' => ['nullable', 'array'],
            'fields.*.field_label' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.field_name_slug' => ['nullable', 'string', 'max:255'],
            'fields.*.field_placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.field_type' => ['required_with:fields', Rule::in($this->fieldTypesArray())],
            'fields.*.required' => ['nullable', Rule::in(['yes', 'no'])],
            'fields.*.checkbox_type' => ['nullable', 'string', 'max:100'],
            'fields.*.default_value' => ['nullable', 'string'],
            'fields.*.validation_rules' => ['nullable', 'array'],
            'fields.*.conditional_rules' => ['nullable', 'array'],
            'fields.*.media_limit' => ['nullable', 'integer', 'min:1'],
            'fields.*.media_size' => ['nullable', 'string', 'max:100'],
            'fields.*.media_format' => ['nullable', 'string', 'max:255'],
            'fields.*.has_featured' => ['nullable', 'boolean'],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.status' => ['nullable', 'boolean'],

            'fields.*.conditions' => ['nullable', 'array'],
            'fields.*.conditions.*.taxonomy_id' => ['required_with:fields.*.conditions', 'integer', 'exists:taxonomies,id'],
            'fields.*.conditions.*.taxonomy_term_id' => ['required_with:fields.*.conditions', 'integer', 'exists:taxonomy_terms,id'],
            'fields.*.conditions.*.operator' => ['nullable', 'in:include,exclude'],
        ];

        return $request->validate(array_merge($rules, $this->commonFieldNestedValidationRules()));
    }

    private function commonFieldNestedValidationRules(): array
    {
        return [
            'fields.*.location_rules' => ['nullable', 'array'],
            'fields.*.location_rules.*.logic_operator' => ['nullable', Rule::in(['and', 'or'])],
            'fields.*.location_rules.*.show_if' => ['required_with:fields.*.location_rules', Rule::in(['post_type', 'taxonomy'])],
            'fields.*.location_rules.*.operator' => ['nullable', Rule::in(['is_equal_to', 'is_not_equal_to'])],
            'fields.*.location_rules.*.match_type' => ['nullable', Rule::in(['all', 'specific'])],
            'fields.*.location_rules.*.post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'fields.*.location_rules.*.taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
            'fields.*.location_rules.*.taxonomy_term_ids' => ['nullable', 'array'],
            'fields.*.location_rules.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*.name' => ['required_with:fields.*.options', 'string', 'max:150'],
            'fields.*.options.*.value' => ['required_with:fields.*.options', 'string', 'max:150'],
            'fields.*.options.*.type' => ['nullable', 'string', 'max:50'],
            'fields.*.options.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.options.*.status' => ['nullable', 'boolean'],

            'fields.*.repeaters' => ['nullable', 'array'],
            'fields.*.repeaters.*.field_label' => ['required_with:fields.*.repeaters', 'string', 'max:255'],
            'fields.*.repeaters.*.field_name_slug' => ['nullable', 'string', 'max:255'],
            'fields.*.repeaters.*.field_placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.repeaters.*.field_type' => ['required_with:fields.*.repeaters', Rule::in($this->repeaterFieldTypesArray())],
            'fields.*.repeaters.*.media_limit' => ['nullable', 'integer', 'min:1'],
            'fields.*.repeaters.*.media_size' => ['nullable', 'string', 'max:100'],
            'fields.*.repeaters.*.media_format' => ['nullable', 'string', 'max:255'],
            'fields.*.repeaters.*.has_featured' => ['nullable', 'boolean'],
            'fields.*.repeaters.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.repeaters.*.status' => ['nullable', 'boolean'],
            'fields.*.repeaters.*.options' => ['nullable', 'array'],
            'fields.*.repeaters.*.options.*.name' => ['required_with:fields.*.repeaters.*.options', 'string', 'max:150'],
            'fields.*.repeaters.*.options.*.value' => ['required_with:fields.*.repeaters.*.options', 'string', 'max:150'],
            'fields.*.repeaters.*.options.*.type' => ['nullable', 'string', 'max:50'],
            'fields.*.repeaters.*.options.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.repeaters.*.options.*.status' => ['nullable', 'boolean'],
        ];
    }

    private function validateGroupUpdate(Request $request, CustomFieldGroup $group): array
    {
        $rules = [
            'group_name' => ['sometimes', 'required', 'string', 'max:200'],
            'group_slug' => ['nullable', 'string', 'max:200'],

            'location_rules' => ['nullable', 'array'],
            'location_rules.*.logic_operator' => ['nullable', Rule::in(['and', 'or'])],
            'location_rules.*.show_if' => ['required_with:location_rules', Rule::in(['post_type', 'taxonomy'])],
            'location_rules.*.operator' => ['nullable', Rule::in(['is_equal_to', 'is_not_equal_to'])],
            'location_rules.*.match_type' => ['nullable', Rule::in(['all', 'specific'])],
            'location_rules.*.post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'location_rules.*.taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
            'location_rules.*.taxonomy_term_ids' => ['nullable', 'array'],
            'location_rules.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

            'fields' => ['nullable', 'array'],
            'fields.*.id' => ['nullable', 'integer', 'exists:custom_fields,id'],
            'fields.*.field_label' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.field_name_slug' => ['nullable', 'string', 'max:255'],
            'fields.*.field_placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.field_type' => ['required_with:fields', Rule::in($this->fieldTypesArray())],
            'fields.*.required' => ['nullable', Rule::in(['yes', 'no'])],
            'fields.*.checkbox_type' => ['nullable', 'string', 'max:100'],
            'fields.*.default_value' => ['nullable', 'string'],
            'fields.*.validation_rules' => ['nullable', 'array'],
            'fields.*.conditional_rules' => ['nullable', 'array'],
            'fields.*.media_limit' => ['nullable', 'integer', 'min:1'],
            'fields.*.media_size' => ['nullable', 'string', 'max:100'],
            'fields.*.media_format' => ['nullable', 'string', 'max:255'],
            'fields.*.has_featured' => ['nullable', 'boolean'],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.status' => ['nullable', 'boolean'],

            'fields.*.conditions' => ['nullable', 'array'],
            'fields.*.conditions.*.taxonomy_id' => ['required_with:fields.*.conditions', 'integer', 'exists:taxonomies,id'],
            'fields.*.conditions.*.taxonomy_term_id' => ['required_with:fields.*.conditions', 'integer', 'exists:taxonomy_terms,id'],
            'fields.*.conditions.*.operator' => ['nullable', 'in:include,exclude'],
        ];

        return $request->validate(array_merge($rules, $this->commonFieldNestedValidationRules()));
    }

    private function validateFieldData(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'field_label' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'field_name_slug' => ['nullable', 'string', 'max:255'],
            'field_placeholder' => ['nullable', 'string', 'max:255'],
            'field_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in($this->fieldTypesArray())],
            'required' => ['nullable', Rule::in(['yes', 'no'])],
            'checkbox_type' => ['nullable', 'string', 'max:100'],
            'default_value' => ['nullable', 'string'],
            'validation_rules' => ['nullable', 'array'],
            'conditional_rules' => ['nullable', 'array'],
            'media_limit' => ['nullable', 'integer', 'min:1'],
            'media_size' => ['nullable', 'string', 'max:100'],
            'media_format' => ['nullable', 'string', 'max:255'],
            'has_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];

        return $request->validate(array_merge($rules, $this->commonFieldValidationRules()));
    }

    // ──────────────────────────────────────────────
    //  SAVE LOCATION RULES (flat with logic_operator)
    // ──────────────────────────────────────────────


    private function saveLocationRules(?int $groupId, ?int $fieldId, array $locationRules): void
    {
        $ruleGroup = 1;

        foreach ($locationRules as $index => $rule) {
            $showIf = $rule['show_if'] ?? null;

            if (!$showIf) {
                continue;
            }

            $operator = $rule['operator'] ?? 'is_equal_to';
            $matchType = $rule['match_type'] ?? 'specific';
            $logicOperator = $index === 0 ? null : ($rule['logic_operator'] ?? 'and');

            if ($logicOperator === 'or') {
                $ruleGroup++;
            }

            CustomFieldGroupLocationRule::create([
                'custom_field_group_id' => $groupId,
                'custom_field_id' => $fieldId,
                'logic_operator' => $logicOperator,
                'rule_group' => $ruleGroup,
                'show_if' => $showIf,
                'operator' => $operator,
                'match_type' => $matchType,
                'post_type_id' => $showIf === 'post_type' && $matchType === 'specific'
                    ? ($rule['post_type_id'] ?? null)
                    : null,
                'taxonomy_id' => $showIf === 'taxonomy' && $matchType === 'specific'
                    ? ($rule['taxonomy_id'] ?? null)
                    : null,
                'taxonomy_term_ids' => $showIf === 'taxonomy'
                    ? ($rule['taxonomy_term_ids'] ?? [])
                    : null,
                'status' => $rule['status'] ?? true,
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function evaluateLocationRules($rules, array $context): bool
    {
        if ($rules->isEmpty()) {
            return true; // No rules means always show
        }

        // Sort by sort_order
        $sorted = $rules->sortBy('sort_order')->values();

        // Split into blocks on 'or'
        $blocks = [];
        $currentBlock = [];

        foreach ($sorted as $rule) {
            if ($rule->logic_operator === 'or' && !empty($currentBlock)) {
                // Start a new block
                $blocks[] = $currentBlock;
                $currentBlock = [$rule];
            } else {
                $currentBlock[] = $rule;
            }
        }

        if (!empty($currentBlock)) {
            $blocks[] = $currentBlock;
        }

        // Evaluate each block (AND inside block, OR across blocks)
        foreach ($blocks as $block) {
            $blockMatch = true;

            foreach ($block as $rule) {
                if (!$this->evaluateSingleRule($rule, $context)) {
                    $blockMatch = false;
                    break;
                }
            }

            if ($blockMatch) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a single location rule against context.
     */
    private function evaluateSingleRule($rule, array $context): bool
    {
        $showIf = $rule->show_if;
        $operator = $rule->operator ?? 'is_equal_to';
        $matchType = $rule->match_type ?? 'specific';

        if ($matchType === 'all') {
            return true;
        }

        if ($showIf === 'post_type') {
            $contextPostTypeId = $context['post_type_id'] ?? null;
            $rulePostTypeId = $rule->post_type_id;

            if (!$contextPostTypeId || !$rulePostTypeId) {
                return false;
            }

            $matches = (int) $contextPostTypeId === (int) $rulePostTypeId;
            return $operator === 'is_equal_to' ? $matches : !$matches;
        }

        if ($showIf === 'taxonomy') {
            $contextTaxonomyId = $context['taxonomy_id'] ?? null;
            $ruleTaxonomyId = $rule->taxonomy_id;

            $taxonomyMatches = $contextTaxonomyId && $ruleTaxonomyId && (int) $contextTaxonomyId === (int) $ruleTaxonomyId;

            if (!$taxonomyMatches) {
                return $operator === 'is_not_equal_to';
            }

            $ruleTermIds = $rule->taxonomy_term_ids ?? [];
            $contextTermIds = $context['taxonomy_term_ids'] ?? [];

            if (empty($ruleTermIds)) {
                return true;
            }

            if (empty($contextTermIds)) {
                return false;
            }

            $hasMatchingTerm = !empty(array_intersect($ruleTermIds, $contextTermIds));
            return $operator === 'is_equal_to' ? $hasMatchingTerm : !$hasMatchingTerm;
        }

        return true;
    }

    // ──────────────────────────────────────────────
    //  SAVE HELPERS
    // ──────────────────────────────────────────────

    private function syncFields(CustomFieldGroup $group, array $fields): void
    {
        foreach ($fields as $index => $fieldData) {
            $fieldId = $fieldData['id'] ?? null;

            $hasLocationRules = array_key_exists('location_rules', $fieldData);
            $hasOptions = array_key_exists('options', $fieldData);
            $hasRepeaters = array_key_exists('repeaters', $fieldData);
            $hasConditions = array_key_exists('conditions', $fieldData);

            $locationRules = $fieldData['location_rules'] ?? [];
            $options = $fieldData['options'] ?? [];
            $repeaters = $fieldData['repeaters'] ?? [];
            $conditions = $fieldData['conditions'] ?? [];

            unset(
                $fieldData['id'],
                $fieldData['location_rules'],
                $fieldData['options'],
                $fieldData['repeaters'],
                $fieldData['conditions']
            );

            $field = null;

            if ($fieldId) {
                $field = CustomField::where('custom_field_group_id', $group->id)
                    ->where('id', $fieldId)
                    ->first();
            }

            if (!$field) {
                $lookupSlug = null;

                if (!empty($fieldData['field_name_slug'])) {
                    $lookupSlug = Str::slug($fieldData['field_name_slug'], '_');
                } elseif (!empty($fieldData['field_label'])) {
                    $lookupSlug = Str::slug($fieldData['field_label'], '_');
                }

                if ($lookupSlug) {
                    $field = CustomField::where('custom_field_group_id', $group->id)
                        ->where('field_name_slug', $lookupSlug)
                        ->first();
                }
            }

            if ($field) {
                $updateData = $fieldData;

                if (array_key_exists('field_name_slug', $fieldData) || array_key_exists('field_label', $fieldData)) {
                    $updateData['field_name_slug'] = $this->resolveFieldSlug(
                        $group->id,
                        [
                            'field_name_slug' => $fieldData['field_name_slug'] ?? $field->field_name_slug,
                            'field_label' => $fieldData['field_label'] ?? $field->field_label,
                        ],
                        $field->id
                    );
                }

                if (!empty($updateData)) {
                    $field->update($updateData);
                }
            } else {
                $field = CustomField::create(array_merge($fieldData, [
                    'custom_field_group_id' => $group->id,
                    'field_name_slug' => $this->resolveFieldSlug($group->id, $fieldData),
                    'sort_order' => $fieldData['sort_order'] ?? ($index + 1),
                    'status' => $fieldData['status'] ?? true,
                    'created_by' => Auth::id(),
                ]));
            }

            if ($hasLocationRules) {
                $field->locationRules()->delete();
                $this->saveLocationRules($group->id, $field->id, $locationRules);
            }

            if ($hasOptions) {
                $field->options()->delete();
                $this->saveOptions($field, $options);
            }

            if ($hasRepeaters) {
                $repeaterIds = $field->repeaters()->pluck('id')->toArray();

                if (!empty($repeaterIds)) {
                    CustomFieldRepeaterOption::whereIn('custom_field_repeater_id', $repeaterIds)->delete();
                }

                $field->repeaters()->delete();
                $this->saveRepeaters($field, $repeaters);
            }

            if ($hasConditions) {
                $field->conditions()->delete();
                $this->saveConditions($field, $conditions);
            }
        }
    }

    private function saveFields(CustomFieldGroup $group, array $fields): void
    {
        foreach ($fields as $index => $fieldData) {
            $locationRules = $fieldData['location_rules'] ?? [];
            $options = $fieldData['options'] ?? [];
            $repeaters = $fieldData['repeaters'] ?? [];
            $conditions = $fieldData['conditions'] ?? [];

            unset($fieldData['location_rules'], $fieldData['options'], $fieldData['repeaters'], $fieldData['conditions']);

            $field = CustomField::create([
                'custom_field_group_id' => $group->id,
                'field_label' => $fieldData['field_label'],
                'field_name_slug' => $this->resolveFieldSlug($group->id, $fieldData),
                'field_placeholder' => $fieldData['field_placeholder'] ?? null,
                'field_type' => $fieldData['field_type'],
                'required' => $fieldData['required'] ?? 'no',
                'checkbox_type' => $fieldData['checkbox_type'] ?? null,
                'default_value' => $fieldData['default_value'] ?? null,
                'validation_rules' => $fieldData['validation_rules'] ?? null,
                'conditional_rules' => $fieldData['conditional_rules'] ?? null,
                'media_limit' => $fieldData['media_limit'] ?? null,
                'media_size' => $fieldData['media_size'] ?? null,
                'media_format' => $fieldData['media_format'] ?? null,
                'has_featured' => $fieldData['has_featured'] ?? false,
                'sort_order' => $fieldData['sort_order'] ?? ($index + 1),
                'status' => $fieldData['status'] ?? true,
                'created_by' => Auth::id(),
            ]);

            $this->saveLocationRules($group->id, $field->id, $locationRules);
            $this->saveOptions($field, $options);
            $this->saveRepeaters($field, $repeaters);
            $this->saveConditions($field, $conditions);
        }
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
            $options = $repeaterData['options'] ?? [];
            unset($repeaterData['options']);

            $repeater = CustomFieldRepeater::create([
                'custom_field_id' => $field->id,
                'field_label' => $repeaterData['field_label'],
                'field_name_slug' => !empty($repeaterData['field_name_slug'])
                    ? Str::slug($repeaterData['field_name_slug'], '_')
                    : Str::slug($repeaterData['field_label'], '_'),
                'field_placeholder' => $repeaterData['field_placeholder'] ?? null,
                'field_type' => $repeaterData['field_type'],
                'media_limit' => $repeaterData['media_limit'] ?? null,
                'media_size' => $repeaterData['media_size'] ?? null,
                'media_format' => $repeaterData['media_format'] ?? null,
                'has_featured' => $repeaterData['has_featured'] ?? false,
                'sort_order' => $repeaterData['sort_order'] ?? $index,
                'status' => $repeaterData['status'] ?? true,
            ]);

            foreach ($options as $optionIndex => $option) {
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

    // ──────────────────────────────────────────────
    //  FORMATTING
    // ──────────────────────────────────────────────

    private function formatGroup(CustomFieldGroup $group): array
    {
        return [
            'id' => $group->id,
            'group_name' => $group->group_name,
            'group_slug' => $group->group_slug,
            'creator' => $this->formatCreator($group->creator),
            'location_rules' => $group->relationLoaded('locationRules')
                ? $group->locationRules->whereNull('custom_field_id')->sortBy('sort_order')->values()->map(fn($rule) => [
                    'id' => $rule->id,
                    'logic_operator' => $rule->logic_operator,
                    'show_if' => $rule->show_if,
                    'operator' => $rule->operator ?? 'is_equal_to',
                    'match_type' => $rule->match_type,
                    'post_type_id' => $rule->post_type_id,
                    'taxonomy_id' => $rule->taxonomy_id,
                    'taxonomy_term_ids' => $rule->taxonomy_term_ids ?? [],
                    'status' => $rule->status ?? true,
                    'sort_order' => $rule->sort_order ?? 0,
                ])->values() : [],
            'fields' => $group->relationLoaded('fields')
                ? $group->fields->map(fn($field) => $this->formatField($field))->values() : [],
            'created_at' => $group->created_at,
            'updated_at' => $group->updated_at,
        ];
    }

    private function formatField(CustomField $field): array
    {
        $data = [
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
            'has_featured' => (bool) ($field->has_featured ?? false),
            'sort_order' => $field->sort_order,
            'status' => $field->status,
            'created_by' => $field->created_by,
            'created_at' => $field->created_at,
            'updated_at' => $field->updated_at,
        ];

        if ($field->relationLoaded('locationRules')) {
            $data['location_rules'] = $field->locationRules->sortBy('sort_order')->values()->map(fn($rule) => [
                'id' => $rule->id,
                'logic_operator' => $rule->logic_operator,
                'show_if' => $rule->show_if,
                'operator' => $rule->operator ?? 'is_equal_to',
                'match_type' => $rule->match_type,
                'post_type_id' => $rule->post_type_id,
                'taxonomy_id' => $rule->taxonomy_id,
                'taxonomy_term_ids' => $rule->taxonomy_term_ids ?? [],
                'status' => $rule->status ?? true,
                'sort_order' => $rule->sort_order ?? 0,
            ])->values();
        } else {
            $data['location_rules'] = [];
        }

        if ($field->relationLoaded('conditions')) {
            $data['conditions'] = $field->conditions->map(fn($c) => [
                'id' => $c->id,
                'taxonomy_id' => $c->taxonomy_id,
                'taxonomy_term_id' => $c->taxonomy_term_id,
                'operator' => $c->operator,
                'taxonomy' => $c->taxonomy ? ['id' => $c->taxonomy->id, 'name' => $c->taxonomy->name] : null,
                'taxonomy_term' => $c->taxonomyTerm ? ['id' => $c->taxonomyTerm->id, 'name' => $c->taxonomyTerm->name] : null,
            ])->values();
        } else {
            $data['conditions'] = [];
        }

        $data['options'] = $field->relationLoaded('options') ? $field->options->toArray() : [];
        $data['repeaters'] = $field->relationLoaded('repeaters') ? $field->repeaters->toArray() : [];

        return $data;
    }

    private function formatCreator($user): ?array
    {
        if (!$user)
            return null;

        return [
            'id' => $user->id,
            'full_name' => $this->getUserFullName($user),
            'role' => $this->getUserRoleName($user),
        ];
    }

    private function getUserFullName($user): ?string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return !empty($fullName) ? $fullName : ($user->name ?? $user->user_name ?? $user->email ?? null);
    }

    private function getUserRoleName($user): ?string
    {
        if (method_exists($user, 'roles')) {
            try {
                $roleName = $user->roles()->pluck('name')->first();
                if (!empty($roleName))
                    return $roleName;
            } catch (Throwable $e) {
            }
        }

        if (isset($user->role) && is_object($user->role))
            return $user->role->name ?? null;
        if (isset($user->role) && is_array($user->role))
            return $user->role['name'] ?? null;
        if (isset($user->role) && is_string($user->role))
            return $user->role;
        if (isset($user->role_slug) && is_string($user->role_slug))
            return $user->role_slug;

        if (isset($user->role_id)) {
            try {
                $role = DB::table('roles')->where('id', $user->role_id)->first();
                return $role->name ?? $role->role_name ?? null;
            } catch (Throwable $e) {
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────

    private function resolveFieldSlug(int $groupId, array $fieldData, ?int $ignoreId = null): string
    {
        $slug = !empty($fieldData['field_name_slug'])
            ? Str::slug($fieldData['field_name_slug'], '_')
            : Str::slug($fieldData['field_label'], '_');

        $baseSlug = $slug;
        $counter = 1;
        while (
            CustomField::where('custom_field_group_id', $groupId)
                ->where('field_name_slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $baseSlug . '_' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function fieldTypesArray(): array
    {
        return [
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
        ];
    }

    private function repeaterFieldTypesArray(): array
    {
        return [
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
        ];
    }

    private function scopePostTypeRule($query, int $postTypeId): void
    {
        $query->where('show_if', 'post_type')
            ->where(function ($sub) use ($postTypeId) {
                $sub->where('match_type', 'all')
                    ->orWhere(function ($specific) use ($postTypeId) {
                        $specific->where('match_type', 'specific')
                            ->where('post_type_id', $postTypeId);
                    });
            });
    }

    private function scopeTaxonomyRule($query, int $taxonomyId): void
    {
        $query->where('show_if', 'taxonomy')
            ->where(function ($sub) use ($taxonomyId) {
                $sub->where('match_type', 'all')
                    ->orWhere(function ($specific) use ($taxonomyId) {
                        $specific->where('match_type', 'specific')
                            ->where('taxonomy_id', $taxonomyId);
                    });
            });
    }

    private function scopeTaxonomyRuleWithTerms($query, int $taxonomyId, array $termIds): void
    {
        $query->where('show_if', 'taxonomy')
            ->where(function ($sub) use ($taxonomyId, $termIds) {
                $sub->where('match_type', 'all')
                    ->orWhere(function ($specific) use ($taxonomyId, $termIds) {
                        $specific->where('match_type', 'specific')
                            ->where('taxonomy_id', $taxonomyId);
                        if (!empty($termIds)) {
                            $specific->where(function ($termQuery) use ($termIds) {
                                foreach ($termIds as $termId) {
                                    $termQuery->orWhereJsonContains('taxonomy_term_ids', $termId);
                                }
                                $termQuery->orWhereNull('taxonomy_term_ids');
                            });
                        }
                    });
            });
    }

    // ──────────────────────────────────────────────
    //  RESPONSE FORMATTERS
    // ──────────────────────────────────────────────

    private function successResponse(string $message, mixed $data = null, int $statusCode = 200, array $extra = []): JsonResponse
    {
        $response = array_merge(['status' => true, 'message' => $message], $extra);
        if (!is_null($data))
            $response['data'] = $data;
        return response()->json($response, $statusCode);
    }

    private function errorResponse(string $message, int $statusCode = 500, mixed $error = null, array $extra = []): JsonResponse
    {
        $response = array_merge(['status' => false, 'message' => $message], $extra);
        if (!is_null($error))
            $response['error'] = $error;
        return response()->json($response, $statusCode);
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return $this->errorResponse('Validation failed.', 422, null, ['errors' => $e->errors()]);
    }

    private function databaseErrorResponse(QueryException $e, string $message): JsonResponse
    {
        return $this->errorResponse($message, 500, $e->getMessage(), ['error_type' => 'database_error']);
    }
    public function showFieldById(int|string $fieldId): JsonResponse
    {
        try {
            $field = CustomField::with([
                'group:id,group_name,group_slug',
                'options',
                'repeaters.options',
                'locationRules',
                'conditions.taxonomy',
                'conditions.taxonomyTerm',
                'creator',
            ])->find($fieldId);

            if (!$field) {
                return $this->errorResponse('Custom field not found.', 404);
            }

            return $this->successResponse(
                'Custom field fetched successfully.',
                $this->formatField($field)
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom field.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field.', 500, $e->getMessage());
        }
    }

    public function slugUniquenessCheck(Request $request): JsonResponse
    {
        $rawSlug = $request->input('slug')
            ?? $request->input('field_name_slug')
            ?? $request->input('field_key')
            ?? $request->input('name')
            ?? $request->input('value')
            ?? '';

        if (trim((string) $rawSlug) === '') {
            return response()->json([
                'status' => true,
                'success' => true,
                'is_unique' => true,
                'unique' => true,
                'available' => true,
                'exists' => false,
                'message' => 'Slug is available',
            ], 200);
        }

        $cleanSlug = trim((string) $rawSlug);
        $underscoredSlug = Str::slug($cleanSlug, '_');
        $hyphenatedSlug = Str::slug($cleanSlug, '-');

        $type = strtolower(trim((string) ($request->input('type') ?? $request->input('context') ?? $request->input('model') ?? 'field')));
        $groupId = $request->input('group_id') ?? $request->input('custom_field_group_id');
        $ignoreId = $request->input('ignore_id') ?? $request->input('exclude_id') ?? $request->input('id') ?? $request->input('field_id');

        $exists = false;

        if (in_array($type, ['group', 'custom_field_group', 'field_group'])) {
            $query = CustomFieldGroup::query()
                ->where(function ($q) use ($cleanSlug, $underscoredSlug, $hyphenatedSlug) {
                    $q->where('group_slug', $cleanSlug)
                        ->orWhere('group_slug', $underscoredSlug)
                        ->orWhere('group_slug', $hyphenatedSlug);
                });
            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
            $exists = $query->exists();
        } else {
            $query = CustomField::query()
                ->where(function ($q) use ($cleanSlug, $underscoredSlug, $hyphenatedSlug) {
                    $q->where('field_name_slug', $cleanSlug)
                        ->orWhere('field_name_slug', $underscoredSlug)
                        ->orWhere('field_name_slug', $hyphenatedSlug);
                });

            if ($groupId) {
                $query->where('custom_field_group_id', $groupId);
            }

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }

            $exists = $query->exists();
        }

        $isUnique = !$exists;

        return response()->json([
            'status' => true,
            'success' => true,
            'is_unique' => $isUnique,
            'unique' => $isUnique,
            'available' => $isUnique,
            'exists' => $exists,
            'slug' => $cleanSlug,
            'message' => $isUnique ? 'Slug is available' : 'This field key is already taken.',
        ], 200);
    }

    public function getFieldTypes(): JsonResponse
    {
        $types = [
            ['key' => 'text', 'label' => 'Text', 'description' => 'Single line text input'],
            ['key' => 'textarea', 'label' => 'Textarea', 'description' => 'Multi-line text input'],
            ['key' => 'number', 'label' => 'Number', 'description' => 'Numeric digit input'],
            ['key' => 'texteditor', 'label' => 'Text Editor', 'description' => 'WYSIWYG rich text editor'],
            ['key' => 'select', 'label' => 'Select', 'description' => 'Dropdown options select'],
            ['key' => 'checkbox', 'label' => 'Checkbox', 'description' => 'Checkbox options selection'],
            ['key' => 'radio', 'label' => 'Radio', 'description' => 'Single option radio selection'],
            ['key' => 'boolean', 'label' => 'Boolean (True/False)', 'description' => 'True/False or Yes/No toggle switch'],
            ['key' => 'email', 'label' => 'Email', 'description' => 'Email address input'],
            ['key' => 'url', 'label' => 'URL', 'description' => 'Web address link'],
            ['key' => 'date', 'label' => 'Date', 'description' => 'Date picker input'],
            ['key' => 'datetime', 'label' => 'Date Time', 'description' => 'Date and time picker input'],
            ['key' => 'repeater', 'label' => 'Repeater', 'description' => 'Repeater sub-fields container'],
            ['key' => 'media', 'label' => 'Media', 'description' => 'Image/Media gallery uploader'],
            ['key' => 'file', 'label' => 'File', 'description' => 'File attachment uploader'],
        ];

        return response()->json([
            'status' => true,
            'success' => true,
            'data' => $types,
        ], 200);
    }
}
