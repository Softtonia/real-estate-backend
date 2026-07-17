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
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
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

    public function formattedCustomFields(PostType $postType, array $taxonomyTermIds = []): Collection
    {
        return $this->availableCustomFields($postType, $taxonomyTermIds)
            ->map(fn ($field) => $this->formatCustomField($field))
            ->values();
    }

    public function availableCustomFields(PostType $postType, array $taxonomyTermIds = []): Collection
    {
        $query = CustomField::query();

        $relations = [];

        if (method_exists(CustomField::class, 'options')) {
            $relations[] = 'options';
        }

        if (method_exists(CustomField::class, 'repeaters')) {
            $relations[] = 'repeaters.options';
        }

        if (method_exists(CustomField::class, 'locationRules')) {
            $relations[] = 'locationRules';
        }

        if (!empty($relations)) {
            $query->with($relations);
        }

        if (Schema::hasColumn('custom_fields', 'status')) {
            $query->where(function ($q) {
                $q->where('status', true)
                    ->orWhere('status', 1)
                    ->orWhere('status', 'active');
            });
        }

        if (Schema::hasColumn('custom_fields', 'sort_order')) {
            $query->orderBy('sort_order', 'asc');
        } else {
            $query->orderBy('id', 'asc');
        }

        return $query->get()
            ->filter(fn ($field) => $this->fieldMatchesPostType($field, $postType, $taxonomyTermIds))
            ->values();
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

            'options' => collect($field->options ?? [])->map(fn ($option) => [
                'id' => (int) $option->id,
                'name' => $option->name ?? null,
                'label' => $option->name ?? null,
                'value' => $option->value ?? null,
                'type' => $option->type ?? null,
                'sort_order' => (int) ($option->sort_order ?? 0),
            ])->values(),

            'repeaters' => collect($field->repeaters ?? [])->map(fn ($repeater) => [
                'id' => (int) $repeater->id,
                'field_label' => $repeater->field_label ?? null,
                'field_name_slug' => $repeater->field_name_slug ?? null,
                'field_type' => $repeater->field_type ?? null,
                'required' => (bool) ($repeater->required ?? false),
                'sort_order' => (int) ($repeater->sort_order ?? 0),
                'options' => collect($repeater->options ?? [])->map(fn ($option) => [
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

        foreach ($steps as $step) {
            foreach (($step['custom_field_ids'] ?? []) as $customFieldId) {
                if ($customFieldId) {
                    $submittedFieldIds[] = (int) $customFieldId;
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
                $stepKey = $stepPayload['step_key'];
                $step = $stepsByKey->get($stepKey);

                if (!$step) {
                    continue;
                }

                foreach (($stepPayload['custom_field_ids'] ?? []) as $index => $customFieldId) {
                    if (empty($customFieldId)) {
                        continue;
                    }

                    DynamicPostFormStepField::create([
                        'post_type_id' => (int) $postType->id,
                        'dynamic_post_form_step_id' => (int) $step->id,
                        'custom_field_id' => (int) $customFieldId,
                        'sort_order' => $index + 1,
                        'is_active' => true,
                    ]);
                }
            }
        });
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

            'step-4' => [
                [
                    'key' => 'featured_image_id',
                    'field_name_slug' => 'featured_image_id',
                    'request_key' => 'featured_image_id',
                    'field_label' => 'Featured Image',
                    'label' => 'Featured Image',
                    'field_type' => 'media',
                    'type' => 'media',
                    'is_base_field' => true,
                ],
                [
                    'key' => 'gallery_image_ids',
                    'field_name_slug' => 'gallery_image_ids',
                    'request_key' => 'gallery_image_ids',
                    'field_label' => 'Gallery',
                    'label' => 'Gallery',
                    'field_type' => 'gallery',
                    'type' => 'gallery',
                    'multiple' => true,
                    'is_base_field' => true,
                ],
            ],
        ];

        return $baseFields[$stepKey] ?? [];
    }
}