<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TemplateDynamicFieldController extends Controller
{
    public function index(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'post_type_id' => 'nullable|integer|exists:post_types,id',
            'post_type' => 'nullable|string',
            'post_id' => 'nullable|integer',
            'dynamic_post_id' => 'nullable|integer',
            'entity_id' => 'nullable|integer',
            'taxonomy_term_ids' => 'nullable|array',
            'taxonomy_term_ids.*' => 'integer',
        ]);

        $validator->after(function ($validator) use ($payload) {
            if (empty($payload['post_type_id']) && empty($payload['post_type'])) {
                $validator->errors()->add(
                    'post_type',
                    'Post type id or post type slug is required.'
                );
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $postTypeRecord = $this->getPostTypeRecord($payload);

        if (! $postTypeRecord) {
            return response()->json([
                'status' => false,
                'message' => 'Post type not found or inactive.',
            ], 404);
        }

        $entityId = $payload['entity_id']
            ?? $payload['dynamic_post_id']
            ?? $payload['post_id']
            ?? null;

        $entityId = $entityId ? (int) $entityId : null;

        $postPreviewData = $this->getPostPreviewData($entityId, $postTypeRecord);

        $selectedTermIds = $this->resolveSelectedTermIds($payload, $entityId);

        $basicWidgets = $this->getBasicWidgets();

        $systemFields = $this->getSystemFields($postPreviewData);

        $customFields = $this->getDynamicCustomFields(
            $postTypeRecord,
            $entityId,
            $selectedTermIds
        );

        $taxonomyFields = $this->getTaxonomyFields(
            $postTypeRecord,
            $selectedTermIds
        );

        $builderItems = array_merge(
            $basicWidgets,
            $systemFields,
            $customFields,
            $taxonomyFields
        );

        return response()->json([
            'status' => true,
            'message' => 'Builder dynamic fields fetched successfully.',
            'data' => [
                'post_type_id' => $postTypeRecord->id,
                'post_type' => $postTypeRecord->slug,
                'post_type_name' => $postTypeRecord->name,

                'entity_id' => $entityId,
                'selected_taxonomy_term_ids' => $selectedTermIds,

                'basic_widgets' => $basicWidgets,

                'dynamic_fields' => [
                    'system' => $systemFields,
                    'custom' => $customFields,
                    'taxonomies' => $taxonomyFields,
                ],

                'dynamic_custom_fields' => $customFields,

                'builder_items' => $builderItems,

                /*
                 * React team requested this key.
                 * Same data as builder_items, but every item has field_value.
                 */
                'builder_value' => $builderItems,

                'preview_values' => $entityId
                    ? $this->previewValues($systemFields, $customFields, $taxonomyFields)
                    : null,
            ],
        ]);
    }

    private function getPostTypeRecord(array $payload): ?object
    {
        if (! Schema::hasTable('post_types')) {
            return null;
        }

        $query = DB::table('post_types');

        if (! empty($payload['post_type_id'])) {
            $query->where('id', $payload['post_type_id']);
        } elseif (! empty($payload['post_type'])) {
            $query->where('slug', $payload['post_type']);
        }

        if (Schema::hasColumn('post_types', 'status')) {
            $query->where(function ($q) {
                $q->where('status', true)
                    ->orWhere('status', 1)
                    ->orWhere('status', '1')
                    ->orWhere('status', 'active');
            });
        }

        return $query->first();
    }

    private function getPostPreviewData(?int $entityId, object $postTypeRecord): array
    {
        if (! $entityId || ! Schema::hasTable('dynamic_posts')) {
            return [];
        }

        $query = DB::table('dynamic_posts')->where('id', $entityId);

        if (Schema::hasColumn('dynamic_posts', 'post_type_id')) {
            $query->where('post_type_id', $postTypeRecord->id);
        }

        if (Schema::hasColumn('dynamic_posts', 'post_type_slug')) {
            $query->where('post_type_slug', $postTypeRecord->slug);
        }

        if (Schema::hasColumn('dynamic_posts', 'post_type')) {
            $query->where('post_type', $postTypeRecord->slug);
        }

        $post = $query->first();

        if (! $post) {
            return [];
        }

        $row = $this->rowToArray($post);

        return [
            'id' => $row['id'] ?? null,
            'title' => $row['title']
                ?? $row['post_title']
                ?? $row['name']
                ?? $row['property_title']
                ?? $row['project_name']
                ?? $row['developer_name']
                ?? null,
            'slug' => $row['slug'] ?? $row['post_slug'] ?? null,
            'content' => $row['content']
                ?? $row['description']
                ?? $row['post_content']
                ?? null,
            'excerpt' => $row['excerpt']
                ?? $row['short_description']
                ?? null,
            'featured_image' => $row['featured_image']
                ?? $row['thumbnail']
                ?? $row['image']
                ?? $row['banner_image']
                ?? null,
            'status' => $row['status'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function getDynamicCustomFields(object $postTypeRecord, ?int $entityId, array $selectedTermIds): array
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
            ->filter(function ($field) use ($postTypeRecord, $selectedTermIds) {
                return $this->fieldMatchesLocationRules(
                    $field,
                    $postTypeRecord,
                    $selectedTermIds
                );
            })
            ->map(function ($field) use ($entityId) {
                $fieldKey = $field->field_name_slug
                    ?? $field->field_name
                    ?? $field->slug
                    ?? $field->name
                    ?? null;

                if (! $fieldKey) {
                    return null;
                }

                $fieldType = strtolower((string) ($field->field_type ?? $field->type ?? 'text'));

                $fieldValue = null;

                if ($entityId) {
                    if ($fieldType === 'repeater') {
                        $fieldValue = $this->getRepeaterValue(
                            $entityId,
                            (int) $field->id
                        );
                    } else {
                        $fieldValue = $this->getCustomFieldValue(
                            $entityId,
                            (int) $field->id
                        );
                    }
                }

                return [
                    'id' => $field->id,
                    'custom_field_group_id' => $field->custom_field_group_id ?? null,
                    'group_name' => $field->group_name ?? null,
                    'group_slug' => $field->group_slug ?? null,

                    'label' => $field->field_label
                        ?? $field->label
                        ?? $field->name
                        ?? $fieldKey,

                    'key' => $fieldKey,
                    'field_key' => $fieldKey,
                    'field_path' => 'custom.' . $fieldKey,

                    'source' => 'custom_field',
                    'type' => $fieldType,
                    'component_key' => $this->mapFieldTypeToComponent($fieldType),

                    'binding' => [
                        'source' => 'custom_field',
                        'field_id' => $field->id,
                        'field_key' => $fieldKey,
                        'path' => 'custom.' . $fieldKey,
                    ],

                    'settings' => [
                        'source' => 'dynamic',
                        'field' => 'custom.' . $fieldKey,
                    ],

                    'options' => $this->getFieldOptions((int) $field->id),
                    'repeaters' => $this->getFieldRepeaters((int) $field->id),

                    /*
                     * Main value for React.
                     */
                    'field_value' => $fieldValue,

                    /*
                     * Backward compatibility.
                     */
                    'value' => $fieldValue,
                    'has_value' => $entityId ? $this->hasRealValue($fieldValue) : false,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function fieldMatchesLocationRules(object $field, object $postTypeRecord, array $selectedTermIds): bool
    {
        $fieldRules = $this->getFieldLocationRules((int) $field->id);

        if (! empty($fieldRules)) {
            return $this->evaluateLocationRules(
                $fieldRules,
                $postTypeRecord,
                $selectedTermIds
            );
        }

        $groupId = $field->custom_field_group_id ?? null;

        if ($groupId) {
            $groupRules = $this->getGroupLocationRules((int) $groupId);

            if (! empty($groupRules)) {
                return $this->evaluateLocationRules(
                    $groupRules,
                    $postTypeRecord,
                    $selectedTermIds
                );
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

    private function evaluateLocationRules(array $rules, object $postTypeRecord, array $selectedTermIds): bool
    {
        if (empty($rules)) {
            return true;
        }

        $groups = [];

        foreach ($rules as $rule) {
            $group = $rule->rule_group ?? 1;
            $groups[$group][] = $rule;
        }

        foreach ($groups as $groupRules) {
            $groupMatched = true;

            foreach ($groupRules as $rule) {
                if (! $this->singleRuleMatches($rule, $postTypeRecord, $selectedTermIds)) {
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

    private function singleRuleMatches(object $rule, object $postTypeRecord, array $selectedTermIds): bool
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
                && (int) $rule->post_type_id === (int) $postTypeRecord->id;
        }

        if ($showIf === 'taxonomy') {
            $ruleTermIds = $this->normalizeIds($rule->taxonomy_term_ids ?? []);

            /*
             * When only post_type_id is passed, show taxonomy-based fields in builder.
             * When post_id is passed, match with selected listing terms.
             */
            if (empty($selectedTermIds)) {
                $matches = true;
            } elseif (empty($ruleTermIds)) {
                $matches = true;
            } else {
                $matches = ! empty(array_intersect($ruleTermIds, $selectedTermIds));
            }
        }

        return $operator === 'is_not_equal_to' ? ! $matches : $matches;
    }

    private function getCustomFieldValue(int $entityId, int $customFieldId): mixed
    {
        if (! Schema::hasTable('custom_field_values')) {
            return null;
        }

        $query = DB::table('custom_field_values')
            ->where('entity_id', $entityId)
            ->where('custom_field_id', $customFieldId);

        if (Schema::hasColumn('custom_field_values', 'entity_type')) {
            $query->where('entity_type', 'post');
        }

        $row = $query->orderByDesc('id')->first();

        if (! $row) {
            return null;
        }

        return $this->extractStoredValue($row);
    }

    private function getRepeaterValue(int $entityId, int $customFieldId): array
    {
        if (! Schema::hasTable('custom_field_repeater_values')) {
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

            $value = $this->extractStoredValue($row);

            if (! empty($row->repeater_option_value)) {
                $value = $row->repeater_option_value;
            }

            if (! isset($formatted[$rowIndex])) {
                $formatted[$rowIndex] = [];
            }

            $formatted[$rowIndex][$key] = $value;
        }

        return array_values($formatted);
    }

    private function extractStoredValue(object $row): mixed
    {
        /*
         * Your price is stored in value_json, so check value_json first.
         */
        if (property_exists($row, 'value_json') && $row->value_json !== null) {
            $value = $this->decodeMaybeJson($row->value_json);

            if (is_array($value) && array_key_exists('value', $value)) {
                return $value['value'];
            }

            if (is_array($value) && array_key_exists('amount', $value)) {
                return $value['amount'];
            }

            if (is_array($value) && array_key_exists('price', $value)) {
                return $value['price'];
            }

            if (is_array($value) && count($value) === 1) {
                $first = reset($value);

                if (! is_array($first) && ! is_object($first)) {
                    return $first;
                }
            }

            return $value;
        }

        if (property_exists($row, 'value_number') && $row->value_number !== null) {
            return is_numeric($row->value_number)
                ? (str_contains((string) $row->value_number, '.')
                    ? (float) $row->value_number
                    : (int) $row->value_number)
                : $row->value_number;
        }

        if (property_exists($row, 'value_string') && $row->value_string !== null) {
            return $row->value_string;
        }

        if (property_exists($row, 'value_text') && $row->value_text !== null) {
            return $row->value_text;
        }

        if (property_exists($row, 'value_date') && $row->value_date !== null) {
            return $row->value_date;
        }

        if (property_exists($row, 'value_datetime') && $row->value_datetime !== null) {
            return $row->value_datetime;
        }

        if (property_exists($row, 'field_meta_value') && $row->field_meta_value !== null) {
            return $this->decodeMaybeJson($row->field_meta_value);
        }

        return null;
    }

    private function getFieldOptions(int $customFieldId): array
    {
        if (! Schema::hasTable('custom_field_options')) {
            return [];
        }

        return DB::table('custom_field_options')
            ->where('custom_field_id', $customFieldId)
            ->when(Schema::hasColumn('custom_field_options', 'status'), function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', true)
                        ->orWhere('status', 1)
                        ->orWhere('status', '1')
                        ->orWhere('status', 'active');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($option) {
                return [
                    'id' => $option->id,
                    'name' => $option->name ?? null,
                    'value' => $option->value ?? null,
                    'type' => $option->type ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function getFieldRepeaters(int $customFieldId): array
    {
        if (! Schema::hasTable('custom_field_repeaters')) {
            return [];
        }

        $repeaters = DB::table('custom_field_repeaters')
            ->where('custom_field_id', $customFieldId)
            ->when(Schema::hasColumn('custom_field_repeaters', 'status'), function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', true)
                        ->orWhere('status', 1)
                        ->orWhere('status', '1')
                        ->orWhere('status', 'active');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $repeaters
            ->map(function ($repeater) {
                return [
                    'id' => $repeater->id,
                    'label' => $repeater->field_label ?? null,
                    'key' => $repeater->field_name_slug ?? null,
                    'type' => $repeater->field_type ?? 'text',
                    'options' => $this->getRepeaterOptions((int) $repeater->id),
                ];
            })
            ->values()
            ->all();
    }

    private function getRepeaterOptions(int $repeaterId): array
    {
        if (! Schema::hasTable('custom_field_repeater_options')) {
            return [];
        }

        return DB::table('custom_field_repeater_options')
            ->where('custom_field_repeater_id', $repeaterId)
            ->when(Schema::hasColumn('custom_field_repeater_options', 'status'), function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', true)
                        ->orWhere('status', 1)
                        ->orWhere('status', '1')
                        ->orWhere('status', 'active');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($option) {
                return [
                    'id' => $option->id,
                    'name' => $option->name ?? null,
                    'value' => $option->value ?? null,
                    'type' => $option->type ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function getSystemFields(array $postPreviewData = []): array
    {
        return [
            [
                'label' => 'Title',
                'key' => 'title',
                'field_path' => 'system.title',
                'source' => 'system',
                'type' => 'text',
                'component_key' => 'heading',
                'field_value' => $postPreviewData['title'] ?? null,
                'value' => $postPreviewData['title'] ?? null,
                'has_value' => $this->hasRealValue($postPreviewData['title'] ?? null),
                'settings' => [
                    'source' => 'dynamic',
                    'field' => 'system.title',
                    'tag' => 'h1',
                ],
            ],
            [
                'label' => 'Content',
                'key' => 'content',
                'field_path' => 'system.content',
                'source' => 'system',
                'type' => 'texteditor',
                'component_key' => 'text',
                'field_value' => $postPreviewData['content'] ?? null,
                'value' => $postPreviewData['content'] ?? null,
                'has_value' => $this->hasRealValue($postPreviewData['content'] ?? null),
                'settings' => [
                    'source' => 'dynamic',
                    'field' => 'system.content',
                ],
            ],
            [
                'label' => 'Featured Image',
                'key' => 'featured_image',
                'field_path' => 'system.featured_image',
                'source' => 'system',
                'type' => 'image',
                'component_key' => 'image',
                'field_value' => $postPreviewData['featured_image'] ?? null,
                'value' => $postPreviewData['featured_image'] ?? null,
                'has_value' => $this->hasRealValue($postPreviewData['featured_image'] ?? null),
                'settings' => [
                    'source' => 'dynamic',
                    'field' => 'system.featured_image',
                ],
            ],
        ];
    }

    private function getTaxonomyFields(object $postTypeRecord, array $selectedTermIds = []): array
    {
        if (! Schema::hasTable('taxonomies')) {
            return [];
        }

        $query = DB::table('taxonomies');

        if (Schema::hasColumn('taxonomies', 'post_type_id')) {
            $query->where('post_type_id', $postTypeRecord->id);
        }

        if (Schema::hasColumn('taxonomies', 'post_type_slug')) {
            $query->where('post_type_slug', $postTypeRecord->slug);
        }

        if (Schema::hasColumn('taxonomies', 'status')) {
            $query->where(function ($q) {
                $q->where('status', true)
                    ->orWhere('status', 1)
                    ->orWhere('status', '1')
                    ->orWhere('status', 'active');
            });
        }

        return $query
            ->orderBy('id')
            ->get()
            ->map(function ($taxonomy) use ($selectedTermIds) {
                $slug = $taxonomy->slug ?? ('taxonomy_' . $taxonomy->id);

                $terms = $this->getTaxonomyTerms((int) $taxonomy->id);

                $selectedTerms = collect($terms)
                    ->filter(function ($term) use ($selectedTermIds) {
                        return in_array((int) $term['id'], $selectedTermIds, true);
                    })
                    ->values()
                    ->all();

                return [
                    'id' => $taxonomy->id,
                    'label' => $taxonomy->name ?? $slug,
                    'key' => $slug,
                    'field_path' => 'taxonomies.' . $slug . '.terms',
                    'source' => 'taxonomy',
                    'type' => 'taxonomy_terms',
                    'component_key' => 'taxonomy_terms',
                    'terms' => $terms,

                    /*
                     * Selected listing taxonomy value.
                     */
                    'field_value' => $selectedTerms,
                    'value' => $selectedTerms,
                    'has_value' => ! empty($selectedTerms),

                    'settings' => [
                        'source' => 'dynamic',
                        'field' => 'taxonomies.' . $slug . '.terms',
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function getTaxonomyTerms(int $taxonomyId): array
    {
        if (! Schema::hasTable('taxonomy_terms')) {
            return [];
        }

        return DB::table('taxonomy_terms')
            ->where('taxonomy_id', $taxonomyId)
            ->when(Schema::hasColumn('taxonomy_terms', 'status'), function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', true)
                        ->orWhere('status', 1)
                        ->orWhere('status', '1')
                        ->orWhere('status', 'active');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($term) {
                return [
                    'id' => $term->id,
                    'name' => $term->name ?? null,
                    'slug' => $term->slug ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function resolveSelectedTermIds(array $payload, ?int $entityId): array
    {
        $termIds = $this->normalizeIds($payload['taxonomy_term_ids'] ?? []);

        if (! $entityId || ! Schema::hasTable('post_taxonomy_terms')) {
            return $termIds;
        }

        $postColumn = $this->firstExistingColumn('post_taxonomy_terms', [
            'dynamic_post_id',
            'post_id',
            'entity_id',
            'content_id',
            'object_id',
        ]);

        $termColumn = $this->firstExistingColumn('post_taxonomy_terms', [
            'taxonomy_term_id',
            'term_id',
        ]);

        if (! $postColumn || ! $termColumn) {
            return $termIds;
        }

        $query = DB::table('post_taxonomy_terms')
            ->where($postColumn, $entityId);

        if (Schema::hasColumn('post_taxonomy_terms', 'entity_type')) {
            $query->where('entity_type', 'post');
        }

        $storedTermIds = $query->pluck($termColumn)->all();

        return array_values(array_unique(array_merge(
            $termIds,
            $this->normalizeIds($storedTermIds)
        )));
    }

    private function previewValues(array $systemFields, array $customFields, array $taxonomyFields): array
    {
        $system = [];
        $custom = [];
        $taxonomies = [];

        foreach ($systemFields as $field) {
            if (! isset($field['key'])) {
                continue;
            }

            $system[$field['key']] = $field['field_value'] ?? null;
        }

        foreach ($customFields as $field) {
            if (! isset($field['key'])) {
                continue;
            }

            $custom[$field['key']] = $field['field_value'] ?? null;
        }

        foreach ($taxonomyFields as $field) {
            if (! isset($field['key'])) {
                continue;
            }

            $taxonomies[$field['key']] = $field['field_value'] ?? [];
        }

        return [
            'system' => $system,
            'custom' => $custom,
            'taxonomies' => $taxonomies,
        ];
    }

    private function getBasicWidgets(): array
    {
        return [
            [
                'label' => 'Heading',
                'key' => 'heading',
                'source' => 'basic_widget',
                'type' => 'heading',
                'component_key' => 'heading',
                'field_value' => '',
                'value' => '',
                'has_value' => false,
                'settings' => [
                    'text' => '',
                    'tag' => 'h2',
                    'alignment' => 'left',
                ],
            ],
            [
                'label' => 'Text Editor',
                'key' => 'text',
                'source' => 'basic_widget',
                'type' => 'text',
                'component_key' => 'text',
                'field_value' => '',
                'value' => '',
                'has_value' => false,
                'settings' => [
                    'content' => '',
                ],
            ],
            [
                'label' => 'Image',
                'key' => 'image',
                'source' => 'basic_widget',
                'type' => 'image',
                'component_key' => 'image',
                'field_value' => '',
                'value' => '',
                'has_value' => false,
                'settings' => [
                    'url' => '',
                    'alt' => '',
                ],
            ],
            [
                'label' => 'Button',
                'key' => 'button',
                'source' => 'basic_widget',
                'type' => 'button',
                'component_key' => 'button',
                'field_value' => '',
                'value' => '',
                'has_value' => false,
                'settings' => [
                    'text' => 'Click Here',
                    'url' => '',
                    'target' => '_self',
                ],
            ],
        ];
    }

    private function mapFieldTypeToComponent(string $type): string
    {
        return match (strtolower($type)) {
            'image', 'media', 'file' => 'image',
            'gallery' => 'gallery',
            'repeater' => 'repeater',
            'textarea', 'texteditor', 'editor', 'wysiwyg' => 'text',
            'url', 'link' => 'button',
            default => 'text',
        };
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

    private function hasRealValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if ($value === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }

    private function rowToArray(object $row): array
    {
        return json_decode(json_encode($row), true) ?: [];
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function getPayload(Request $request): array
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            $payload = $request->all();
        }

        if (empty($payload) && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return is_array($payload) ? $payload : [];
    }
}