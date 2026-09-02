<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\PostType;
use App\PageBuilder\Foundation\WidgetContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Throwable;

class DynamicPostDataService
{
    public function loadForResolvePayload(array $payload): array
    {
        $postType = $this->resolvePostType($payload);

        $post = $this->findDynamicPost($payload, $postType);

        if (! $post) {
            return [];
        }

        if (! $postType) {
            $postType = $this->resolvePostTypeFromPost($post);
        }

        if (! $postType) {
            return [];
        }

        $system = $this->systemData($post, $postType);
        $taxonomyData = $this->taxonomyData($post);

        $customResult = $this->customData($post, $postType, $taxonomyData);

        $custom = $customResult['values'] ?? [];
        $customMeta = $customResult['meta'] ?? [];

        return [
            'id' => $system['id'] ?? null,
            'title' => $system['title'] ?? null,
            'slug' => $system['slug'] ?? null,
            'status' => $system['status'] ?? null,

            'post' => $this->rowToArray($post),

            'post_type_id' => $postType->id,
            'post_type' => $postType->slug,
            'post_type_name' => $postType->name ?? null,

            'content_data' => [
                'system' => $system,
                'custom' => $custom,
                'custom_meta' => $customMeta,
                'taxonomies' => $taxonomyData['taxonomies'],
            ],

            'fields' => [
                'system' => $system,
                'custom' => $custom,
                'custom_meta' => $customMeta,
                'taxonomies' => $taxonomyData['taxonomies'],
            ],

            'taxonomy_term_ids' => $taxonomyData['taxonomy_term_ids'],
            'taxonomy_terms' => $taxonomyData['taxonomy_terms'],
            'taxonomies' => $taxonomyData['taxonomies'],
            'terms' => $taxonomyData['terms'],
            'assigned_terms' => $taxonomyData['terms'],
        ];
    }

    public function contextFromPayload(array $payload, string $mode = 'frontend'): WidgetContext
    {
        $loaded = $this->loadForResolvePayload($payload);

        $fields = $loaded['fields']
            ?? $loaded['content_data']
            ?? [];

        if (! is_array($fields)) {
            $fields = [];
        }

        $manualFields = $payload['content_data']
            ?? $payload['fields']
            ?? [];

        if (is_array($manualFields)) {
            $fields = array_replace_recursive($fields, $manualFields);
        }

        $post = isset($loaded['post']) && is_array($loaded['post'])
            ? (object) $loaded['post']
            : null;

        $postType = null;

        if (! empty($loaded['post_type_id']) || ! empty($loaded['post_type'])) {
            $postType = (object) [
                'id' => $loaded['post_type_id'] ?? null,
                'slug' => $loaded['post_type'] ?? null,
                'name' => $loaded['post_type_name'] ?? null,
            ];
        }

        return new WidgetContext(
            post: $post,
            postType: $postType,
            postTypeId: ! empty($loaded['post_type_id']) ? (int) $loaded['post_type_id'] : null,
            fields: $fields,
            taxonomies: $loaded['taxonomies'] ?? [],
            terms: $loaded['terms'] ?? [],
            requestData: $payload,
            mode: $mode
        );
    }

    public function previewContext(array $payload): WidgetContext
    {
        return $this->contextFromPayload($payload, 'preview');
    }

    public function frontendContext(array $payload): WidgetContext
    {
        return $this->contextFromPayload($payload, 'frontend');
    }

    public function resolve(array $payload): array
    {
        return $this->loadForResolvePayload($payload);
    }

    private function resolvePostType(array $payload): ?PostType
    {
        if (! empty($payload['post_type_id'])) {
            return PostType::query()
                ->where('id', $payload['post_type_id'])
                ->first();
        }

        if (! empty($payload['post_type'])) {
            return PostType::query()
                ->where('slug', $payload['post_type'])
                ->first();
        }

        return null;
    }

    private function resolvePostTypeFromPost(stdClass $post): ?PostType
    {
        $row = $this->rowToArray($post);

        if (! empty($row['post_type_id'])) {
            return PostType::query()
                ->where('id', $row['post_type_id'])
                ->first();
        }

        $slug = $row['post_type_slug']
            ?? $row['post_type']
            ?? null;

        if ($slug) {
            return PostType::query()
                ->where('slug', $slug)
                ->first();
        }

        return null;
    }

    private function findDynamicPost(array $payload, ?PostType $postType = null): ?stdClass
    {
        if (! Schema::hasTable('dynamic_posts')) {
            return null;
        }

        $query = DB::table('dynamic_posts');

        $id = $payload['dynamic_post_id']
            ?? $payload['post_id']
            ?? $payload['entity_id']
            ?? $payload['id']
            ?? null;

        if ($id) {
            $query->where('id', $id);
        } elseif (! empty($payload['slug']) && Schema::hasColumn('dynamic_posts', 'slug')) {
            $query->where('slug', $payload['slug']);
        } else {
            return null;
        }

        if ($postType) {
            if (Schema::hasColumn('dynamic_posts', 'post_type_id')) {
                $query->where('post_type_id', $postType->id);
            }

            if (Schema::hasColumn('dynamic_posts', 'post_type_slug')) {
                $query->where('post_type_slug', $postType->slug);
            }

            if (Schema::hasColumn('dynamic_posts', 'post_type')) {
                $query->where('post_type', $postType->slug);
            }
        }

        return $query->first();
    }

    private function systemData(stdClass $post, PostType $postType): array
    {
        $row = $this->rowToArray($post);
        $entityId = (int) ($row['id'] ?? 0);

        $featuredMedia = null;
        $featuredUrl = $row['featured_image']
            ?? $row['thumbnail']
            ?? $row['image']
            ?? $row['banner_image']
            ?? null;

        if (!$featuredUrl && $entityId && Schema::hasTable('custom_field_values')) {
            $cfvRows = DB::table('custom_field_values as cfv')
                ->leftJoin('custom_fields as cf', 'cf.id', '=', 'cfv.custom_field_id')
                ->where('cfv.entity_id', $entityId)
                ->where(function ($q) {
                    $q->whereIn('cf.field_type', ['media', 'gallery', 'image', 'file'])
                        ->orWhereNotNull('cfv.value_json');
                })
                ->orderBy('cfv.id', 'desc')
                ->get(['cfv.value_json', 'cfv.value_text', 'cfv.value_string']);

            foreach ($cfvRows as $cfvRow) {
                $raw = $cfvRow->value_json ?? $cfvRow->value_text ?? $cfvRow->value_string ?? null;
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                if (is_array($decoded)) {
                    $items = $decoded['media'] ?? $decoded['images'] ?? (isset($decoded[0]) ? $decoded : [$decoded]);
                    foreach ($items as $item) {
                        if (is_array($item) && !empty($item['is_featured'])) {
                            $featuredMedia = $item;
                            $featuredUrl = $item['url'] ?? $item['path'] ?? null;
                            break 2;
                        }
                    }
                    if (!$featuredMedia && !empty($items[0]) && is_array($items[0])) {
                        $featuredMedia = $items[0];
                        $featuredUrl = $items[0]['url'] ?? $items[0]['path'] ?? null;
                    }
                }
            }
        }

        return [
            'id' => $row['id'] ?? null,
            'title' => $row['title']
                ?? $row['post_title']
                ?? $row['name']
                ?? $row['project_name']
                ?? $row['property_title']
                ?? $row['developer_name']
                ?? null,
            'slug' => $row['slug'] ?? $row['post_slug'] ?? null,
            'status' => $row['status'] ?? null,
            'content' => $row['content']
                ?? $row['description']
                ?? $row['post_content']
                ?? null,
            'excerpt' => $row['excerpt']
                ?? $row['short_description']
                ?? null,
            'featured_image' => $featuredUrl,
            'featured_media' => $featuredMedia,
            'post_type_id' => $postType->id,
            'post_type_slug' => $postType->slug,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function customData(stdClass $post, PostType $postType, array $taxonomyData): array
    {
        $row = $this->rowToArray($post);
        $entityId = (int) ($row['id'] ?? 0);

        $custom = $this->customFallbackDataFromPostRow($row);
        $customMeta = [];

        $selectedTermIds = collect($taxonomyData['taxonomy_term_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $savedValues = $this->customFieldValuesByFieldId($entityId);
        $applicableFields = $this->applicableCustomFields($postType, $selectedTermIds);

        foreach ($applicableFields as $field) {
            $fieldId = (int) ($field->id ?? 0);
            $fieldKey = $this->fieldKey($field);

            if (! $fieldId || $fieldKey === '') {
                continue;
            }

            $fieldType = strtolower((string) ($field->field_type ?? 'text'));

            if ($fieldType === 'repeater') {
                $value = $this->customRepeaterValues($entityId, $fieldId);
            } elseif (isset($savedValues[$fieldId])) {
                $value = $this->extractCustomFieldStoredValue($savedValues[$fieldId]);
            } elseif (array_key_exists($fieldKey, $custom)) {
                $value = $custom[$fieldKey];
            } else {
                $value = null;
            }

            $custom[$fieldKey] = $this->normalizeCustomValue($value, $fieldType);

            $customMeta[$fieldKey] = [
                'custom_field_id' => $fieldId,
                'field_label' => $field->field_label ?? $fieldKey,
                'field_name_slug' => $fieldKey,
                'field_type' => $fieldType,
                'field_placeholder' => $field->field_placeholder ?? null,
                'value' => $custom[$fieldKey],
            ];
        }

        /*
         * Safety fallback:
         * If a value exists but field location rules did not return the field,
         * still expose it so old saved data does not disappear.
         */
        foreach ($savedValues as $fieldId => $valueRow) {
            $fieldKey = $valueRow->field_name_slug
                ?? ('custom_field_' . $fieldId);

            if (array_key_exists($fieldKey, $custom)) {
                continue;
            }

            $fieldType = strtolower((string) ($valueRow->field_type ?? 'text'));

            if ($fieldType === 'repeater') {
                $value = $this->customRepeaterValues($entityId, (int) $fieldId);
            } else {
                $value = $this->extractCustomFieldStoredValue($valueRow);
            }

            $custom[$fieldKey] = $this->normalizeCustomValue($value, $fieldType);

            $customMeta[$fieldKey] = [
                'custom_field_id' => (int) $fieldId,
                'field_label' => $valueRow->field_label ?? $fieldKey,
                'field_name_slug' => $fieldKey,
                'field_type' => $fieldType,
                'field_placeholder' => $valueRow->field_placeholder ?? null,
                'value' => $custom[$fieldKey],
            ];
        }

        return [
            'values' => $custom,
            'meta' => $customMeta,
        ];
    }

    private function customFallbackDataFromPostRow(array $row): array
    {
        $custom = [];

        foreach ([
            'content_data',
            'custom_fields',
            'field_values',
            'fields',
            'meta',
            'metadata',
            'data',
            'json_data',
        ] as $jsonColumn) {
            if (! array_key_exists($jsonColumn, $row)) {
                continue;
            }

            $decoded = $this->decodeMaybeJson($row[$jsonColumn]);

            if (! is_array($decoded)) {
                continue;
            }

            if (isset($decoded['custom']) && is_array($decoded['custom'])) {
                $custom = array_replace_recursive($custom, $decoded['custom']);
            } elseif (isset($decoded['fields']['custom']) && is_array($decoded['fields']['custom'])) {
                $custom = array_replace_recursive($custom, $decoded['fields']['custom']);
            } else {
                $custom = array_replace_recursive($custom, $decoded);
            }
        }

        $ignoreColumns = [
            'id',
            'user_id',
            'created_by',
            'company_id',
            'developer_id',
            'post_type_id',
            'post_type',
            'post_type_slug',
            'title',
            'post_title',
            'name',
            'project_name',
            'property_title',
            'developer_name',
            'slug',
            'post_slug',
            'status',
            'content',
            'description',
            'post_content',
            'excerpt',
            'short_description',
            'featured_image',
            'thumbnail',
            'image',
            'banner_image',
            'taxonomy_term_ids',
            'taxonomy_terms',
            'term_ids',
            'content_data',
            'custom_fields',
            'field_values',
            'fields',
            'meta',
            'metadata',
            'data',
            'json_data',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        foreach ($row as $key => $value) {
            if (in_array($key, $ignoreColumns, true)) {
                continue;
            }

            $custom[$key] = $this->decodeMaybeJson($value);
        }

        return $custom;
    }

    private function applicableCustomFields(PostType $postType, array $selectedTermIds): array
    {
        if (! Schema::hasTable('custom_fields')) {
            return [];
        }

        $query = DB::table('custom_fields as cf');

        if (Schema::hasTable('custom_field_groups')) {
            $query->leftJoin(
                'custom_field_groups as cfg',
                'cfg.id',
                '=',
                'cf.custom_field_group_id'
            );

            $query->addSelect([
                'cfg.group_name',
                'cfg.group_slug',
            ]);
        }

        $query->addSelect('cf.*');

        if (Schema::hasColumn('custom_fields', 'status')) {
            $query->where(function ($q) {
                $q->where('cf.status', true)
                    ->orWhere('cf.status', 1)
                    ->orWhere('cf.status', '1')
                    ->orWhere('cf.status', 'active');
            });
        }

        if (Schema::hasColumn('custom_fields', 'sort_order')) {
            $query->orderBy('cf.sort_order');
        }

        $query->orderBy('cf.id');

        return $query->get()
            ->filter(function ($field) use ($postType, $selectedTermIds) {
                return $this->fieldMatchesLocationRules($field, $postType, $selectedTermIds);
            })
            ->values()
            ->all();
    }

    private function fieldMatchesLocationRules(object $field, PostType $postType, array $selectedTermIds): bool
    {
        $fieldRules = $this->getFieldLocationRules((int) $field->id);

        if (! empty($fieldRules)) {
            return $this->evaluateLocationRules($fieldRules, $postType, $selectedTermIds);
        }

        $groupId = $field->custom_field_group_id ?? null;

        if ($groupId) {
            $groupRules = $this->getGroupLocationRules((int) $groupId);

            if (! empty($groupRules)) {
                return $this->evaluateLocationRules($groupRules, $postType, $selectedTermIds);
            }
        }

        return true;
    }

    private function getFieldLocationRules(int $fieldId): array
    {
        if (! Schema::hasTable('custom_field_group_location_rules')) {
            return [];
        }

        $query = DB::table('custom_field_group_location_rules')
            ->where('custom_field_id', $fieldId);

        if (Schema::hasColumn('custom_field_group_location_rules', 'status')) {
            $query->where(function ($q) {
                $q->where('status', true)
                    ->orWhere('status', 1)
                    ->orWhere('status', '1')
                    ->orWhere('status', 'active');
            });
        }

        return $query
            ->orderBy('rule_group')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function getGroupLocationRules(int $groupId): array
    {
        if (! Schema::hasTable('custom_field_group_location_rules')) {
            return [];
        }

        $query = DB::table('custom_field_group_location_rules')
            ->where('custom_field_group_id', $groupId)
            ->whereNull('custom_field_id');

        if (Schema::hasColumn('custom_field_group_location_rules', 'status')) {
            $query->where(function ($q) {
                $q->where('status', true)
                    ->orWhere('status', 1)
                    ->orWhere('status', '1')
                    ->orWhere('status', 'active');
            });
        }

        return $query
            ->orderBy('rule_group')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function evaluateLocationRules(array $rules, PostType $postType, array $selectedTermIds): bool
    {
        if (empty($rules)) {
            return true;
        }

        $groups = [];

        foreach ($rules as $rule) {
            $group = (int) ($rule->rule_group ?? 1);
            $groups[$group][] = $rule;
        }

        foreach ($groups as $groupRules) {
            $groupMatched = true;

            foreach ($groupRules as $rule) {
                if (! $this->singleLocationRuleMatches($rule, $postType, $selectedTermIds)) {
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

    private function singleLocationRuleMatches(object $rule, PostType $postType, array $selectedTermIds): bool
    {
        $showIf = $rule->show_if ?? null;
        $operator = $rule->operator ?? 'is_equal_to';
        $matchType = $rule->match_type ?? 'specific';

        if ($matchType === 'all') {
            return $operator === 'is_not_equal_to' ? false : true;
        }

        $matches = false;

        if ($showIf === 'post_type') {
            $matches = ! empty($rule->post_type_id)
                && (int) $rule->post_type_id === (int) $postType->id;
        }

        if ($showIf === 'taxonomy') {
            $ruleTermIds = $this->normalizeIds($rule->taxonomy_term_ids ?? []);

            if (empty($ruleTermIds)) {
                $matches = true;
            } elseif (empty($selectedTermIds)) {
                $matches = false;
            } else {
                $matches = ! empty(array_intersect($ruleTermIds, $selectedTermIds));
            }
        }

        return $operator === 'is_not_equal_to' ? ! $matches : $matches;
    }

    private function customFieldValuesByFieldId(int $entityId): array
    {
        if (
            ! $entityId
            || ! Schema::hasTable('custom_field_values')
            || ! Schema::hasTable('custom_fields')
        ) {
            return [];
        }

        $query = DB::table('custom_field_values as cfv')
            ->join('custom_fields as cf', 'cf.id', '=', 'cfv.custom_field_id')
            ->where('cfv.entity_id', $entityId);

        if (Schema::hasColumn('custom_field_values', 'entity_type')) {
            $query->where('cfv.entity_type', 'post');
        }

        if (Schema::hasTable('custom_field_options')) {
            $query->leftJoin(
                'custom_field_options as cfo',
                'cfo.id',
                '=',
                'cfv.custom_field_option_id'
            );

            $query->addSelect([
                'cfo.name as option_name',
                'cfo.value as option_value',
            ]);
        }

        $query->addSelect([
            'cfv.*',
            'cf.field_label',
            'cf.field_name_slug',
            'cf.field_type',
            'cf.field_placeholder',
        ]);

        $rows = $query
            ->orderByDesc('cfv.id')
            ->get();

        $values = [];

        foreach ($rows as $row) {
            $fieldId = (int) ($row->custom_field_id ?? 0);

            if (! $fieldId) {
                continue;
            }

            if (! isset($values[$fieldId])) {
                $values[$fieldId] = $row;
            }
        }

        return $values;
    }

    private function customRepeaterValues(int $entityId, int $customFieldId): array
    {
        if (
            ! $entityId
            || ! $customFieldId
            || ! Schema::hasTable('custom_field_repeater_values')
        ) {
            return [];
        }

        $query = DB::table('custom_field_repeater_values as rv')
            ->where('rv.entity_id', $entityId)
            ->where('rv.custom_field_id', $customFieldId);

        if (Schema::hasColumn('custom_field_repeater_values', 'entity_type')) {
            $query->where('rv.entity_type', 'post');
        }

        if (Schema::hasTable('custom_field_repeaters')) {
            $query->leftJoin(
                'custom_field_repeaters as r',
                'r.id',
                '=',
                'rv.custom_field_repeater_id'
            );

            $query->addSelect([
                'r.field_label as repeater_field_label',
                'r.field_name_slug as repeater_field_name_slug',
                'r.field_type as repeater_field_type',
            ]);
        }

        if (Schema::hasTable('custom_field_repeater_options')) {
            $query->leftJoin(
                'custom_field_repeater_options as ro',
                'ro.id',
                '=',
                'rv.custom_field_repeater_option_id'
            );

            $query->addSelect([
                'ro.name as repeater_option_name',
                'ro.value as repeater_option_value',
            ]);
        }

        $rows = $query
            ->addSelect('rv.*')
            ->orderBy('rv.row_index')
            ->orderBy('rv.sort_order')
            ->orderBy('rv.id')
            ->get();

        $formatted = [];

        foreach ($rows as $row) {
            $rowIndex = (int) ($row->row_index ?? 0);

            $key = $row->field_name_slug
                ?? $row->repeater_field_name_slug
                ?? ('field_' . ($row->custom_field_repeater_id ?? $row->id));

            $value = $this->extractCustomFieldStoredValue($row);

            if (! empty($row->repeater_option_value)) {
                $value = $row->repeater_option_value;
            }

            if (! isset($formatted[$rowIndex])) {
                $formatted[$rowIndex] = [];
            }

            $formatted[$rowIndex][$key] = $this->normalizeCustomValue(
                $value,
                (string) ($row->field_type ?? $row->repeater_field_type ?? 'text')
            );
        }

        return array_values($formatted);
    }

    private function extractCustomFieldStoredValue(object $row): mixed
    {
        if (property_exists($row, 'value_json') && $row->value_json !== null) {
            return $this->decodeMaybeJson($row->value_json);
        }

        if (property_exists($row, 'value_datetime') && $row->value_datetime !== null) {
            return $row->value_datetime;
        }

        if (property_exists($row, 'value_date') && $row->value_date !== null) {
            return $row->value_date;
        }

        if (property_exists($row, 'value_number') && $row->value_number !== null) {
            return is_numeric($row->value_number)
                ? (str_contains((string) $row->value_number, '.')
                    ? (float) $row->value_number
                    : (int) $row->value_number)
                : $row->value_number;
        }

        if (property_exists($row, 'option_value') && $row->option_value !== null) {
            return $row->option_value;
        }

        if (property_exists($row, 'value_string') && $row->value_string !== null) {
            return $row->value_string;
        }

        if (property_exists($row, 'value_text') && $row->value_text !== null) {
            return $row->value_text;
        }

        if (property_exists($row, 'field_meta_value') && $row->field_meta_value !== null) {
            return $this->decodeMaybeJson($row->field_meta_value);
        }

        return null;
    }

    private function normalizeCustomValue(mixed $value, string $fieldType): mixed
    {
        $value = $this->decodeMaybeJson($value);
        $fieldType = strtolower($fieldType);

        return match ($fieldType) {
            'boolean' => $this->normalizeBooleanValue($value),
            'number' => is_numeric($value)
                ? (str_contains((string) $value, '.') ? (float) $value : (int) $value)
                : $value,
            'media', 'file', 'image' => $this->normalizeMediaValue($value),
            'checkbox', 'multi_select', 'multiselect' => is_array($value) ? $value : $this->normalizeIds($value),
            default => $value,
        };
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeMediaValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return [
            [
                'url' => (string) $value,
            ],
        ];
    }

    private function taxonomyData(stdClass $post): array
    {
        $row = $this->rowToArray($post);

        $termIds = [];
        $taxonomyTerms = [];
        $taxonomies = [];
        $terms = [];

        foreach (['taxonomy_term_ids', 'term_ids'] as $column) {
            if (! isset($row[$column])) {
                continue;
            }

            $decoded = $this->decodeMaybeJson($row[$column]);

            if (is_array($decoded)) {
                $termIds = array_merge($termIds, $decoded);
            }
        }

        if (isset($row['taxonomy_terms'])) {
            $decoded = $this->decodeMaybeJson($row['taxonomy_terms']);

            if (is_array($decoded)) {
                $taxonomyTerms = $decoded;

                foreach ($decoded as $ids) {
                    if (is_array($ids)) {
                        $termIds = array_merge($termIds, $ids);
                    }
                }
            }
        }

        if (Schema::hasTable('post_taxonomy_terms')) {
            $postColumn = $this->firstExistingColumn('post_taxonomy_terms', [
                'dynamic_post_id',
                'post_id',
                'entity_id',
                'content_id',
                'object_id',
                'model_id',
            ]);

            $termColumn = $this->firstExistingColumn('post_taxonomy_terms', [
                'taxonomy_term_id',
                'term_id',
            ]);

            if ($postColumn && $termColumn) {
                $query = DB::table('post_taxonomy_terms')
                    ->where($postColumn, $row['id']);

                if (Schema::hasColumn('post_taxonomy_terms', 'entity_type')) {
                    $query->where('entity_type', 'post');
                }

                $relationTermIds = $query
                    ->pluck($termColumn)
                    ->all();

                $termIds = array_merge($termIds, $relationTermIds);
            }
        }

        $termIds = collect($termIds)
            ->flatten()
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (! empty($termIds) && Schema::hasTable('taxonomy_terms')) {
            $termRows = DB::table('taxonomy_terms')
                ->whereIn('id', $termIds)
                ->get();

            foreach ($termRows as $term) {
                $termRow = $this->rowToArray($term);

                $taxonomyId = $termRow['taxonomy_id'] ?? null;
                $taxonomy = null;

                if ($taxonomyId && Schema::hasTable('taxonomies')) {
                    $taxonomy = DB::table('taxonomies')
                        ->where('id', $taxonomyId)
                        ->first();
                }

                $taxonomySlug = $taxonomy->slug
                    ?? $termRow['taxonomy_slug']
                    ?? ('taxonomy_' . $taxonomyId);

                $termItem = [
                    'id' => $termRow['id'] ?? null,
                    'name' => $termRow['name'] ?? $termRow['label'] ?? null,
                    'slug' => $termRow['slug'] ?? null,
                    'taxonomy_id' => $taxonomyId,
                    'taxonomy_slug' => $taxonomySlug,
                ];

                $terms[] = $termItem;

                if ($taxonomyId) {
                    $taxonomyTerms[(string) $taxonomyId][] = $termItem['id'];
                }

                if ($taxonomySlug) {
                    $taxonomyTerms[(string) $taxonomySlug][] = $termItem['id'];

                    if (! isset($taxonomies[$taxonomySlug])) {
                        $taxonomies[$taxonomySlug] = [
                            'taxonomy_id' => $taxonomyId,
                            'taxonomy_slug' => $taxonomySlug,
                            'taxonomy_name' => $taxonomy->name ?? null,
                            'terms' => [],
                        ];
                    }

                    $taxonomies[$taxonomySlug]['terms'][] = [
                        'id' => $termItem['id'],
                        'name' => $termItem['name'],
                        'slug' => $termItem['slug'],
                    ];
                }
            }
        }

        foreach ($taxonomyTerms as $key => $ids) {
            $taxonomyTerms[$key] = collect($ids)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return [
            'taxonomy_term_ids' => $termIds,
            'taxonomy_terms' => $taxonomyTerms,
            'taxonomies' => $taxonomies,
            'terms' => $terms,
        ];
    }

    private function fieldKey(object $field): string
    {
        return (string) (
            $field->field_name_slug
            ?? $field->field_name
            ?? $field->slug
            ?? $field->name
            ?? ''
        );
    }

    private function normalizeIds(mixed $value): array
    {
        $value = $this->decodeMaybeJson($value);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatten()
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function decodeMaybeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private function rowToArray(object $row): array
    {
        return json_decode(json_encode($row), true) ?: [];
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            try {
                if (Schema::hasColumn($table, $column)) {
                    return $column;
                }
            } catch (Throwable) {
                //
            }
        }

        return null;
    }
}