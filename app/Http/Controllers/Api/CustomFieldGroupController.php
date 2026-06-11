<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldGroup;
use App\Models\CustomFieldGroupLocationRule;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterOption;
use App\Models\PostType;
use App\Models\Taxonomy;
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
    ];

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
                    $q->whereHas('locationRules', function ($ruleQuery) use ($request) {
                        $ruleQuery->where('show_if', 'post_type')
                            ->where(function ($sub) use ($request) {
                                $sub->where('match_type', 'all')
                                    ->orWhere('post_type_id', $request->post_type_id);
                            });
                    });
                })
                ->when($request->filled('taxonomy_id'), function ($q) use ($request) {
                    $q->whereHas('locationRules', function ($ruleQuery) use ($request) {
                        $ruleQuery->where('show_if', 'taxonomy')
                            ->where(function ($sub) use ($request) {
                                $sub->where('match_type', 'all')
                                    ->orWhere('taxonomy_id', $request->taxonomy_id);
                            });
                    });
                })
                ->orderBy('id', 'desc');

            $perPage = (int) $request->get('per_page', 15);

            $groups = $query->paginate($perPage);

            $groups->getCollection()->transform(function ($group) {
                return $this->formatGroup($group);
            });

            return $this->successResponse(
                'Custom field groups fetched successfully.',
                $groups
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom field groups.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field groups.', 500, $e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $this->validateGroup($request);

            $group = DB::transaction(function () use ($validated) {
                $locationRules = $validated['location_rules'] ?? [];
                $fields = $validated['fields'] ?? [];

                unset($validated['location_rules'], $validated['fields']);

                $group = CustomFieldGroup::create([
                    'group_name' => $validated['group_name'],
                    'group_slug' => !empty($validated['group_slug'])
                        ? CustomFieldGroup::generateUniqueSlug($validated['group_slug'])
                        : CustomFieldGroup::generateUniqueSlug($validated['group_name']),
                    'created_by' => Auth::id(),
                ]);

                // ✅ Save location rules
                $this->saveLocationRules($group, $locationRules);

                // Save custom fields
                $this->saveFields($group, $fields);

                return $group;
            });

            $group = $group->fresh()->load($this->groupRelations);

            return $this->successResponse(
                'Custom field group created successfully.',
                $this->formatGroup($group),
                201
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to create custom field group.', 500, $e->getMessage());
        }
    }

    public function show(int|string $id): JsonResponse
    {
        try {
            $group = CustomFieldGroup::with($this->groupRelations)->find($id);

            if (!$group) {
                return $this->errorResponse('Custom field group not found.', 404, 'No custom field group exists with this id.', [
                    'id' => $id,
                ]);
            }

            return $this->successResponse(
                'Custom field group fetched successfully.',
                $this->formatGroup($group)
            );
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

            $validated = $this->validateGroup($request, $group->id);

            DB::transaction(function () use ($group, $validated) {
                $locationRules = $validated['location_rules'] ?? [];
                $fields = $validated['fields'] ?? [];

                unset($validated['location_rules'], $validated['fields']);

                $slug = $group->group_slug;
                if (!empty($validated['group_slug'])) {
                    $slug = CustomFieldGroup::generateUniqueSlug($validated['group_slug'], $group->id);
                } elseif ($validated['group_name'] !== $group->group_name) {
                    $slug = CustomFieldGroup::generateUniqueSlug($validated['group_name'], $group->id);
                }

                $group->update([
                    'group_name' => $validated['group_name'],
                    'group_slug' => $slug,
                ]);

                // Delete old rules and fields
                $group->locationRules()->delete();
                $group->fields()->delete();

                // Save updated location rules
                $this->saveLocationRules($group, $locationRules);

                // Save updated fields
                $this->saveFields($group, $fields);
            });

            $group = $group->fresh()->load($this->groupRelations);

            return $this->successResponse(
                'Custom field group updated successfully.',
                $this->formatGroup($group)
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to update custom field group.', 500, $e->getMessage());
        }
    }

    public function destroy(int|string $id): JsonResponse
    {
        try {
            $group = CustomFieldGroup::find($id);

            if (!$group) {
                return $this->errorResponse('Custom field group not found.', 404, 'No custom field group exists with this id.', [
                    'id' => $id,
                ]);
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
                return CustomFieldGroup::whereIn('id', $ids)->delete();
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

    public function groupsByPostType(int|string $postType): JsonResponse
    {
        try {
            $postTypeData = PostType::query()
                ->where(function ($q) use ($postType) {
                    if (is_numeric($postType)) $q->where('id', $postType);
                    $q->orWhere('slug', $postType)->orWhere('name', $postType);
                })
                ->first();

            if (!$postTypeData) return $this->errorResponse('Post type not found.', 404);

            $groups = CustomFieldGroup::with($this->groupRelations)
                ->whereHas('locationRules', function ($q) use ($postTypeData) {
                    $q->where('show_if', 'post_type')
                        ->where(function ($sub) use ($postTypeData) {
                            $sub->where('match_type', 'all')
                                ->orWhere('post_type_id', $postTypeData->id);
                        });
                })
                ->orderBy('id', 'asc')
                ->get()
                ->map(fn($group) => $this->formatGroup($group));

            return $this->successResponse('Custom field groups fetched by post type successfully.', $groups, 200, [
                'post_type' => [
                    'id' => $postTypeData->id,
                    'name' => $postTypeData->name,
                    'slug' => $postTypeData->slug,
                ],
            ]);
        } catch (\Throwable $e) {
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
                    if (is_numeric($taxonomy)) $q->where('id', $taxonomy);
                    $q->orWhere('slug', $taxonomy)->orWhere('name', $taxonomy);
                })
                ->first();

            if (!$taxonomyData) return $this->errorResponse('Taxonomy not found.', 404);

            $selectedTermIds = collect($request->taxonomy_term_ids ?? [])->map(fn($id) => (int)$id)->toArray();

            $groups = CustomFieldGroup::with($this->groupRelations)
                ->whereHas('locationRules', function ($q) use ($taxonomyData, $selectedTermIds) {
                    $q->where('show_if', 'taxonomy')
                        ->where(function ($sub) use ($taxonomyData, $selectedTermIds) {
                            $sub->where('match_type', 'all')
                                ->orWhere(function ($specific) use ($taxonomyData, $selectedTermIds) {
                                    $specific->where('taxonomy_id', $taxonomyData->id);
                                    if ($selectedTermIds) {
                                        $specific->where(function ($termQuery) use ($selectedTermIds) {
                                            foreach ($selectedTermIds as $termId) {
                                                $termQuery->orWhereJsonContains('taxonomy_term_ids', $termId);
                                            }
                                            $termQuery->orWhereNull('taxonomy_term_ids');
                                        });
                                    }
                                });
                        });
                })
                ->orderBy('id', 'asc')
                ->get()
                ->map(fn($group) => $this->formatGroup($group));

            return $this->successResponse('Custom field groups fetched by taxonomy successfully.', $groups, 200, [
                'taxonomy' => [
                    'id' => $taxonomyData->id,
                    'name' => $taxonomyData->name,
                    'slug' => $taxonomyData->slug,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to fetch custom field groups by taxonomy.', 500, $e->getMessage());
        }
    }

    private function validateGroup(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'group_name' => ['required', 'string', 'max:200'],
            'group_slug' => ['nullable', 'string', 'max:200'],

            'location_rules' => ['required', 'array', 'min:1'],
            'location_rules.*.show_if' => ['required', Rule::in(['post_type', 'taxonomy'])],
            'location_rules.*.match_type' => ['nullable', Rule::in(['all', 'specific'])],
            'location_rules.*.post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'location_rules.*.taxonomy_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
            'location_rules.*.taxonomy_term_ids' => ['nullable', 'array'],
            'location_rules.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

            'fields' => ['required', 'array', 'min:1'],
            'fields.*.field_label' => ['required', 'string', 'max:255'],
            'fields.*.field_name_slug' => ['nullable', 'string', 'max:255'],
            'fields.*.field_placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.field_type' => ['required', Rule::in($this->fieldTypes())],
            'fields.*.required' => ['nullable', Rule::in(['yes', 'no'])],
            'fields.*.checkbox_type' => ['nullable', 'string', 'max:100'],
            'fields.*.default_value' => ['nullable', 'string'],
            'fields.*.validation_rules' => ['nullable', 'array'],
            'fields.*.conditional_rules' => ['nullable', 'array'],
            'fields.*.media_limit' => ['nullable', 'integer', 'min:1'],
            'fields.*.media_size' => ['nullable', 'string', 'max:100'],
            'fields.*.media_format' => ['nullable', 'string', 'max:255'],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.status' => ['nullable', 'boolean'],

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
            'fields.*.repeaters.*.field_type' => ['required_with:fields.*.repeaters', Rule::in($this->repeaterFieldTypes())],
            'fields.*.repeaters.*.media_limit' => ['nullable', 'integer', 'min:1'],
            'fields.*.repeaters.*.media_size' => ['nullable', 'string', 'max:100'],
            'fields.*.repeaters.*.media_format' => ['nullable', 'string', 'max:255'],
            'fields.*.repeaters.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.repeaters.*.status' => ['nullable', 'boolean'],

            'fields.*.repeaters.*.options' => ['nullable', 'array'],
            'fields.*.repeaters.*.options.*.name' => ['required_with:fields.*.repeaters.*.options', 'string', 'max:150'],
            'fields.*.repeaters.*.options.*.value' => ['required_with:fields.*.repeaters.*.options', 'string', 'max:150'],
            'fields.*.repeaters.*.options.*.type' => ['nullable', 'string', 'max:50'],
            'fields.*.repeaters.*.options.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'fields.*.repeaters.*.options.*.status' => ['nullable', 'boolean'],
        ]);
    }

    private function saveLocationRules(CustomFieldGroup $group, array $locationRules): void
    {
        foreach ($locationRules as $index => $rule) {
            CustomFieldGroupLocationRule::create([
                'custom_field_group_id' => $group->id,
                'show_if' => $rule['show_if'],
                'match_type' => $rule['match_type'] ?? 'specific',
                'post_type_id' => $rule['show_if'] === 'post_type' && $rule['match_type'] === 'specific'
                    ? ($rule['post_type_id'] ?? null)
                    : null,
                'taxonomy_id' => $rule['show_if'] === 'taxonomy' && $rule['match_type'] === 'specific'
                    ? ($rule['taxonomy_id'] ?? null)
                    : null,
                'taxonomy_term_ids' => $rule['show_if'] === 'taxonomy'
                    ? ($rule['taxonomy_term_ids'] ?? null)
                    : null,
            ]);
        }
    }

    private function saveFields(CustomFieldGroup $group, array $fields): void
    {
        foreach ($fields as $index => $fieldData) {
            $options = $fieldData['options'] ?? [];
            $repeaters = $fieldData['repeaters'] ?? [];

            unset($fieldData['options'], $fieldData['repeaters']);

            $field = CustomField::create([
                'custom_field_group_id' => $group->id,
                'field_label' => $fieldData['field_label'],
                'field_name_slug' => !empty($fieldData['field_name_slug'])
                    ? Str::slug($fieldData['field_name_slug'], '_')
                    : Str::slug($fieldData['field_label'], '_'),
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
                'sort_order' => $fieldData['sort_order'] ?? $index,
                'status' => $fieldData['status'] ?? true,
                'created_by' => Auth::id(),
            ]);

            $this->saveOptions($field, $options);
            $this->saveRepeaters($field, $repeaters);
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

    private function formatGroup(CustomFieldGroup $group): array
    {
        return [
            'id' => $group->id,
            'group_name' => $group->group_name,
            'group_slug' => $group->group_slug,
            'creator' => $group->creator ? [
                'id' => $group->creator->id,
                'full_name' => trim(($group->creator->first_name ?? '') . ' ' . ($group->creator->last_name ?? '')),
                'role' => $this->getUserRoleName($group->creator),
            ] : null,
            'location_rules' => $group->relationLoaded('locationRules')
                ? $group->locationRules->map(fn($rule) => [
                    'id' => $rule->id,
                    'show_if' => $rule->show_if,
                    'match_type' => $rule->match_type,
                    'post_type_id' => $rule->post_type_id,
                    'taxonomy_id' => $rule->taxonomy_id,
                    'taxonomy_term_ids' => $rule->taxonomy_term_ids ?? [],
                ])->values()
                : [],
            'fields' => $group->relationLoaded('fields')
                ? $group->fields->map(fn($field) => [
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
                    'created_by' => $field->created_by,
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at,
                    'options' => $field->relationLoaded('options') ? $field->options->toArray() : [],
                    'repeaters' => $field->relationLoaded('repeaters') ? $field->repeaters->toArray() : [],
                ])->values()
                : [],
            'created_at' => $group->created_at,
            'updated_at' => $group->updated_at,
        ];
    }

    private function formatCreator($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'full_name' => $this->getUserFullName($user),
            'role' => $this->getUserRoleName($user),
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
            } catch (Throwable $e) {
                //
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

        if (isset($user->role_slug) && is_string($user->role_slug)) {
            return $user->role_slug;
        }

        if (isset($user->role_id)) {
            try {
                $role = DB::table('roles')
                    ->where('id', $user->role_id)
                    ->first();

                return $role->name ?? $role->role_name ?? null;
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function fieldTypes(): array
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

    private function repeaterFieldTypes(): array
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
}
