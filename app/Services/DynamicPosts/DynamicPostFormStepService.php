<?php

namespace App\Services\DynamicPosts;

use App\Models\CustomField;
use App\Models\DynamicPostFormStep;
use App\Models\DynamicPostFormStepField;
use App\Models\PostType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Models\TaxonomyTerm;

class DynamicPostFormStepService
{
    public function resolvePostType(int|string $postType): ?PostType
    {
        return PostType::query()
            ->where(function ($query) use ($postType) {
                if (is_numeric($postType)) {
                    $query->where('id', (int) $postType);
                }

                $query->orWhere('slug', (string) $postType)
                    ->orWhere('name', (string) $postType);
            })
            ->first();
    }

    public function normalizeIds(mixed $ids): array
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

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return collect($ids)
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    public function ensureDefaultSteps(PostType $postType): void
    {
        $existingCount = DynamicPostFormStep::query()
            ->where('post_type_id', $postType->id)
            ->count();

        if ($existingCount > 0) {
            return;
        }

        $steps = [
            [
                'step_key' => 'step-1',
                'step_label' => 'Basic Details',
                'description' => 'Title and description.',
                'sort_order' => 1,
            ],
            [
                'step_key' => 'step-2',
                'step_label' => 'Location Details',
                'description' => 'Country, state, city and locality.',
                'sort_order' => 2,
            ],
            [
                'step_key' => 'step-3',
                'step_label' => 'Property Details',
                'description' => 'Property custom fields.',
                'sort_order' => 3,
            ],
            [
                'step_key' => 'step-4',
                'step_label' => 'Media & Gallery',
                'description' => 'Featured image and gallery.',
                'sort_order' => 4,
            ],
            [
                'step_key' => 'step-5',
                'step_label' => 'Review & Submit',
                'description' => 'Review and submit listing.',
                'sort_order' => 5,
            ],
        ];

        foreach ($steps as $step) {
            DynamicPostFormStep::create([
                'post_type_id' => (int) $postType->id,
                'step_key' => $step['step_key'],
                'step_label' => $step['step_label'],
                'description' => $step['description'],
                'sort_order' => $step['sort_order'],
                'is_active' => true,
            ]);
        }
    }

    public function saveSteps(PostType $postType, array $steps): Collection
    {
        return DB::transaction(function () use ($postType, $steps) {
            $submittedStepKeys = collect($steps)
                ->pluck('step_key')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            DynamicPostFormStepField::query()
                ->where('post_type_id', (int) $postType->id)
                ->whereHas('step', function ($query) use ($postType, $submittedStepKeys) {
                    $query->where('post_type_id', (int) $postType->id)
                        ->whereNotIn('step_key', $submittedStepKeys);
                })
                ->delete();

            DynamicPostFormStep::query()
                ->where('post_type_id', (int) $postType->id)
                ->whereNotIn('step_key', $submittedStepKeys)
                ->delete();

            foreach ($steps as $index => $step) {
                DynamicPostFormStep::updateOrCreate(
                    [
                        'post_type_id' => (int) $postType->id,
                        'step_key' => $step['step_key'],
                    ],
                    [
                        'step_label' => $step['step_label'],
                        'description' => $step['description'] ?? null,
                        'sort_order' => $step['sort_order'] ?? ($index + 1),
                        'is_active' => $step['is_active'] ?? true,
                    ]
                );
            }

            return $this->steps($postType);
        });
    }

    public function steps(PostType $postType): Collection
    {
        return DynamicPostFormStep::query()
            ->where('post_type_id', $postType->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function activeSteps(PostType $postType): Collection
    {
        return DynamicPostFormStep::query()
            ->where('post_type_id', $postType->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function formattedCustomFields(
        PostType $postType,
        array $taxonomyTermIds = [],
        bool $applyTaxonomyConditions = false
    ): Collection {
        return $this->availableCustomFields($postType, $taxonomyTermIds, $applyTaxonomyConditions)
            ->map(fn($field) => $this->formatCustomField($field))
            ->values();
    }
    public function availableCustomFields(
        PostType $postType,
        array $taxonomyTermIds = [],
        bool $applyTaxonomyConditions = false
    ): Collection {
        $selectedTaxonomyIds = $this->selectedTaxonomyIdsFromTerms($taxonomyTermIds);

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
            ->filter(function ($field) use (
                $postType,
                $taxonomyTermIds,
                $selectedTaxonomyIds,
                $applyTaxonomyConditions
            ) {
                return $this->fieldLocationRulesMatch(
                    $field,
                    (int) $postType->id,
                    $selectedTaxonomyIds,
                    $taxonomyTermIds
                )
                    && $this->fieldConditionsMatch(
                        $field,
                        $taxonomyTermIds,
                        $applyTaxonomyConditions
                    );
            })
            ->values();
    }
    private function selectedTaxonomyIdsFromTerms(array $selectedTermIds): array
    {
        if (empty($selectedTermIds)) {
            return [];
        }

        return TaxonomyTerm::query()
            ->whereIn('id', $selectedTermIds)
            ->pluck('taxonomy_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function fieldLocationRulesMatch(
        $field,
        int $postTypeId,
        array $selectedTaxonomyIds,
        array $selectedTermIds
    ): bool {
        $groupRules = $field->group?->locationRules ?? collect();
        $fieldRules = $field->locationRules ?? collect();

        $groupMatched = $groupRules->isEmpty()
            ? true
            : $this->locationRuleCollectionMatches(
                $groupRules,
                $postTypeId,
                $selectedTaxonomyIds,
                $selectedTermIds
            );

        $fieldMatched = $fieldRules->isEmpty()
            ? true
            : $this->locationRuleCollectionMatches(
                $fieldRules,
                $postTypeId,
                $selectedTaxonomyIds,
                $selectedTermIds
            );

        return $groupMatched && $fieldMatched;
    }

    private function locationRuleCollectionMatches(
        $rules,
        int $postTypeId,
        array $selectedTaxonomyIds,
        array $selectedTermIds
    ): bool {
        if ($rules->isEmpty()) {
            return true;
        }

        $groupedRules = $rules->groupBy(fn($rule) => $rule->rule_group ?? 1);

        foreach ($groupedRules as $groupRules) {
            $groupMatched = true;

            foreach ($groupRules as $rule) {
                if (!$this->singleLocationRuleMatches(
                    $rule,
                    $postTypeId,
                    $selectedTaxonomyIds,
                    $selectedTermIds
                )) {
                    $groupMatched = false;
                    break;
                }
            }

            if ($groupMatched) {
                return true;
            }
        }

        return false;
    }

    private function singleLocationRuleMatches(
        $rule,
        int $postTypeId,
        array $selectedTaxonomyIds,
        array $selectedTermIds
    ): bool {
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

    private function fieldConditionsMatch(
        $field,
        array $selectedTermIds,
        bool $applyTaxonomyConditions = false
    ): bool {
        if (!$applyTaxonomyConditions) {
            return true;
        }

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
    private function fieldMatchesPostType($field, PostType $postType, array $selectedTermIds = []): bool
    {
        if (
            Schema::hasColumn('custom_fields', 'post_type_id')
            && !empty($field->post_type_id)
        ) {
            return (int) $field->post_type_id === (int) $postType->id;
        }

        if (method_exists($field, 'locationRules')) {
            $rules = $field->relationLoaded('locationRules')
                ? $field->locationRules
                : $field->locationRules()->get();

            if ($rules->isEmpty()) {
                return false;
            }

            foreach ($rules as $rule) {
                if (isset($rule->status) && !$rule->status) {
                    continue;
                }

                $operator = $rule->operator ?: 'is_equal_to';
                $matchType = $rule->match_type ?: 'specific';

                if (($rule->show_if ?? null) === 'post_type') {
                    $matched = $matchType === 'all'
                        ? true
                        : (int) ($rule->post_type_id ?? 0) === (int) $postType->id;

                    return $operator === 'is_not_equal_to' ? !$matched : $matched;
                }

                if (($rule->show_if ?? null) === 'taxonomy') {
                    $ruleTermIds = $this->normalizeIds($rule->taxonomy_term_ids ?? []);

                    if (empty($ruleTermIds)) {
                        continue;
                    }

                    $matched = count(array_intersect($ruleTermIds, $selectedTermIds)) > 0;

                    return $operator === 'is_not_equal_to' ? !$matched : $matched;
                }
            }
        }

        return false;
    }

    public function formatCustomField($field): array
    {
        return [
            'id' => (int) $field->id,
            'custom_field_group_id' => $field->custom_field_group_id ?? null,

            'field_label' => $field->field_label ?? null,
            'label' => $field->field_label ?? null,

            'field_name_slug' => $field->field_name_slug ?? null,
            'request_key' => $field->field_name_slug ?? null,

            'field_placeholder' => $field->field_placeholder ?? null,

            'field_type' => $field->field_type ?? null,
            'type' => $field->field_type ?? null,

            'required' => (bool) ($field->required ?? false),
            'checkbox_type' => $field->checkbox_type ?? null,

            'default_value' => $field->default_value ?? null,
            'validation_rules' => $field->validation_rules ?? null,
            'conditional_rules' => $field->conditional_rules ?? null,

            'media_limit' => $field->media_limit ?? null,
            'media_size' => $field->media_size ?? null,
            'media_format' => $field->media_format ?? null,

            'sort_order' => (int) ($field->sort_order ?? 0),
            'status' => $field->status ?? null,

            'options' => collect($field->options ?? [])->map(fn($option) => [
                'id' => (int) $option->id,
                'name' => $option->name ?? null,
                'label' => $option->name ?? null,
                'value' => $option->value ?? null,
                'type' => $option->type ?? null,
                'sort_order' => (int) ($option->sort_order ?? 0),
            ])->values(),

            'repeaters' => collect($field->repeaters ?? [])->map(fn($repeater) => [
                'id' => (int) $repeater->id,
                'field_label' => $repeater->field_label ?? null,
                'field_name_slug' => $repeater->field_name_slug ?? null,
                'field_type' => $repeater->field_type ?? null,
                'required' => (bool) ($repeater->required ?? false),
                'sort_order' => (int) ($repeater->sort_order ?? 0),
                'options' => collect($repeater->options ?? [])->map(fn($option) => [
                    'id' => (int) $option->id,
                    'name' => $option->name ?? null,
                    'value' => $option->value ?? null,
                    'sort_order' => (int) ($option->sort_order ?? 0),
                ])->values(),
            ])->values(),
        ];
    }

    public function syncMapping(PostType $postType, array $steps, array $allowedCustomFieldIds): void
    {
        $submittedFieldIds = [];

        foreach ($steps as $stepPayload) {
            foreach ($this->mappingFieldsFromStepPayload($stepPayload) as $fieldPayload) {
                if (!empty($fieldPayload['custom_field_id'])) {
                    $submittedFieldIds[] = (int) $fieldPayload['custom_field_id'];
                }
            }
        }

        $duplicates = collect($submittedFieldIds)
            ->duplicates()
            ->values()
            ->toArray();

        if (!empty($duplicates)) {
            throw ValidationException::withMessages([
                'custom_field_ids' => [
                    'Same custom field cannot be mapped in multiple steps: ' . implode(', ', $duplicates),
                ],
            ]);
        }

        $invalidIds = array_values(array_diff($submittedFieldIds, $allowedCustomFieldIds));

        if (!empty($invalidIds)) {
            throw ValidationException::withMessages([
                'custom_field_ids' => [
                    'These custom fields are not allowed for this post type or condition: ' . implode(', ', $invalidIds),
                ],
            ]);
        }

        DB::transaction(function () use ($postType, $steps) {
            DynamicPostFormStepField::query()
                ->where('post_type_id', $postType->id)
                ->delete();

            $stepsByKey = $this->steps($postType)->keyBy('step_key');

            foreach ($steps as $stepPayload) {
                $stepKey = $stepPayload['step_key'] ?? null;

                if (!$stepKey) {
                    continue;
                }

                $step = $stepsByKey->get($stepKey);

                if (!$step) {
                    continue;
                }

                foreach ($this->mappingFieldsFromStepPayload($stepPayload) as $index => $fieldPayload) {
                    $customFieldId = (int) ($fieldPayload['custom_field_id'] ?? 0);

                    if (empty($customFieldId)) {
                        continue;
                    }

                    $fieldWidth = (int) ($fieldPayload['field_width'] ?? 100);
                    $fieldWidth = max(1, min($fieldWidth, 100));

                    $sortOrder = isset($fieldPayload['sort_order'])
                        ? (int) $fieldPayload['sort_order']
                        : ($index + 1);

                    $createPayload = [
                        'post_type_id' => (int) $postType->id,
                        'dynamic_post_form_step_id' => (int) $step->id,
                        'custom_field_id' => $customFieldId,
                        'sort_order' => $sortOrder,
                        'is_active' => true,
                    ];

                    if (Schema::hasColumn('dynamic_post_form_step_fields', 'field_width')) {
                        $createPayload['field_width'] = $fieldWidth;
                    }

                    DynamicPostFormStepField::create($createPayload);
                }
            }
        });
    }

    private function mappingFieldsFromStepPayload(array $stepPayload): array
    {
        if (!empty($stepPayload['custom_fields']) && is_array($stepPayload['custom_fields'])) {
            return collect($stepPayload['custom_fields'])
                ->filter(fn($field) => is_array($field) && !empty($field['custom_field_id']))
                ->map(function ($field, $index) {
                    return [
                        'custom_field_id' => (int) $field['custom_field_id'],
                        'field_width' => isset($field['field_width'])
                            ? (int) $field['field_width']
                            : 100,
                        'sort_order' => isset($field['sort_order'])
                            ? (int) $field['sort_order']
                            : ($index + 1),
                    ];
                })
                ->values()
                ->toArray();
        }

        return collect($stepPayload['custom_field_ids'] ?? [])
            ->filter(fn($id) => !empty($id))
            ->map(function ($customFieldId, $index) {
                return [
                    'custom_field_id' => (int) $customFieldId,
                    'field_width' => 100,
                    'sort_order' => $index + 1,
                ];
            })
            ->values()
            ->toArray();
    }

    public function mappedFieldRows(PostType $postType): Collection
    {
        return DynamicPostFormStepField::query()
            ->where('post_type_id', $postType->id)
            ->where('is_active', true)
            ->with('step')
            ->orderBy('dynamic_post_form_step_id')
            ->orderBy('sort_order')
            ->get();
    }

    public function postTypePayload(PostType $postType): array
    {
        return [
            'id' => (int) $postType->id,
            'name' => $postType->name,
            'slug' => $postType->slug,
        ];
    }

    public function baseFieldsForStep(string $stepKey): array
    {
        $baseFields = [
            'step-1' => [
                [
                    'key' => 'title',
                    'field_name_slug' => 'title',
                    'request_key' => 'title',
                    'field_label' => 'Title',
                    'label' => 'Title',
                    'field_type' => 'text',
                    'type' => 'text',
                    'required' => true,
                    'is_base_field' => true,
                ],
                [
                    'key' => 'content',
                    'field_name_slug' => 'content',
                    'request_key' => 'content',
                    'field_label' => 'Content',
                    'label' => 'Content',
                    'field_type' => 'richtext',
                    'type' => 'richtext',
                    'required' => false,
                    'is_base_field' => true,
                ],
                [
                    'key' => 'excerpt',
                    'field_name_slug' => 'excerpt',
                    'request_key' => 'excerpt',
                    'field_label' => 'Excerpt',
                    'label' => 'Excerpt',
                    'field_type' => 'textarea',
                    'type' => 'textarea',
                    'required' => false,
                    'is_base_field' => true,
                ],
            ],

            'step-2' => [
                [
                    'key' => 'country_id',
                    'field_name_slug' => 'country_id',
                    'request_key' => 'country_id',
                    'field_label' => 'Country',
                    'label' => 'Country',
                    'field_type' => 'select',
                    'type' => 'select',
                    'options_api' => 'countries',
                    'is_base_field' => true,
                ],
                [
                    'key' => 'state_id',
                    'field_name_slug' => 'state_id',
                    'request_key' => 'state_id',
                    'field_label' => 'State',
                    'label' => 'State',
                    'field_type' => 'select',
                    'type' => 'select',
                    'depends_on' => 'country_id',
                    'options_api' => 'states/{country_id}',
                    'is_base_field' => true,
                ],
                [
                    'key' => 'city_id',
                    'field_name_slug' => 'city_id',
                    'request_key' => 'city_id',
                    'field_label' => 'City',
                    'label' => 'City',
                    'field_type' => 'select',
                    'type' => 'select',
                    'depends_on' => 'state_id',
                    'options_api' => 'cities/{state_id}',
                    'is_base_field' => true,
                ],
                [
                    'key' => 'area_locality',
                    'field_name_slug' => 'area_locality',
                    'request_key' => 'area_locality',
                    'field_label' => 'Area / Locality',
                    'label' => 'Area / Locality',
                    'field_type' => 'text',
                    'type' => 'text',
                    'is_base_field' => true,
                ],
            ],

            'step-4' => [],
        ];

        return $baseFields[$stepKey] ?? [];
    }
}
