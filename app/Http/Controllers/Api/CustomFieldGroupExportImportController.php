<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldGroup;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterOption;
use App\Models\CustomFieldGroupLocationRule;
use App\Models\PostType;
use App\Models\Taxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class CustomFieldGroupExportImportController extends Controller
{
    /**
     * Import Custom Field Groups from CSV
     * CSV Format: group_name, field_label, field_name_slug, field_placeholder, field_type, required, 
     *             default_value, validation_rules, conditional_rules, media_limit, media_size, media_format, 
     *             status, post_type_slugs, location_rules, options, repeaters
     */
    public function importFromCsv(Request $request)
    {
        try {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt',
            ]);

            $file = $request->file('csv_file');
            $excelData = Excel::toArray([], $file);

            if (empty($excelData) || empty($excelData[0])) {
                return response()->json([
                    'status' => false,
                    'message' => 'CSV file is empty.',
                ], 422);
            }

            $rows = $excelData[0];
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            $fieldAliases = [
                'group_name' => ['group_name', 'group_title', 'group', 'groupname', 'field_group', 'field_group_name', 'custom_field_group', 'acf_group'],
                'group_id' => ['group_id', 'custom_field_group_id', 'id'],
                'field_label' => ['field_label', 'label', 'field_title', 'title', 'field_heading'],
                'field_name_slug' => ['field_name_slug', 'field_slug', 'slug', 'name_slug', 'field_name', 'name', 'key'],
                'field_placeholder' => ['field_placeholder', 'placeholder', 'field_help'],
                'field_type' => ['field_type', 'type', 'input_type'],
                'required' => ['required', 'is_required', 'mandatory'],
                'default_value' => ['default_value', 'default', 'initial_value'],
                'validation_rules' => ['validation_rules', 'validation', 'rules'],
                'conditional_rules' => ['conditional_rules', 'conditional_logic', 'conditions'],
                'media_limit' => ['media_limit', 'limit', 'max_media'],
                'media_size' => ['media_size', 'max_size', 'size_limit'],
                'media_format' => ['media_format', 'format', 'allowed_formats', 'formats'],
                'status' => ['status', 'is_active', 'active', 'state'],
                'post_type_slugs' => ['post_type_slugs', 'post_type', 'post_types', 'posttype', 'posttypes'],
                'location_rules' => ['location_rules', 'location_rules_json', 'location_rules_array', 'location_logic'],
                'options' => ['options', 'options_json', 'choices', 'field_options', 'values'],
                'repeaters' => ['repeaters', 'repeaters_json', 'repeater', 'repeater_fields', 'sub_fields'],
                'checkbox_type' => ['checkbox_type', 'checkbox_mode'],
                'has_featured' => ['has_featured', 'featured'],
            ];

            // Detect header row with UTF-8 BOM stripping
            $firstRow = $rows[0] ?? [];
            $normalizedHeaderMap = [];
            $headerRecognizedCount = 0;

            foreach ($firstRow as $colIdx => $colName) {
                $cleanHeader = strtolower(trim((string) $colName));
                $cleanHeader = preg_replace('/^\xEF\xBB\xBF/', '', $cleanHeader); // strip BOM
                $cleanHeader = strtolower(preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-'], '_', $cleanHeader)));
                if ($cleanHeader !== '') {
                    $normalizedHeaderMap[$cleanHeader] = $colIdx;
                    foreach ($fieldAliases as $key => $aliases) {
                        foreach ($aliases as $alias) {
                            $cleanAlias = strtolower(preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-'], '_', $alias)));
                            if ($cleanHeader === $cleanAlias) {
                                $headerRecognizedCount++;
                                break 2;
                            }
                        }
                    }
                }
            }

            $hasHeader = $headerRecognizedCount >= 2 
                || isset($normalizedHeaderMap['group_name']) 
                || isset($normalizedHeaderMap['group'])
                || isset($normalizedHeaderMap['field_label']) 
                || isset($normalizedHeaderMap['field_name_slug'])
                || isset($normalizedHeaderMap['label']);

            $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;

            DB::beginTransaction();

            $groupSortOrders = [];
            $processedGroupLocationRules = [];

            foreach ($dataRows as $index => $row) {
                $rowNumber = $hasHeader ? $index + 2 : $index + 1;

                // Skip empty rows
                if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                    $skipped++;
                    continue;
                }

                if ($hasHeader) {
                    $getValue = function (array $aliases, $default = null) use ($row, $normalizedHeaderMap) {
                        foreach ($aliases as $alias) {
                            $cleanAlias = strtolower(preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-'], '_', $alias)));
                            if (isset($normalizedHeaderMap[$cleanAlias])) {
                                $colIdx = $normalizedHeaderMap[$cleanAlias];
                                if (array_key_exists($colIdx, $row)) {
                                    $val = trim((string) $row[$colIdx]);
                                    if ($val !== '') {
                                        return $val;
                                    }
                                }
                            }
                        }
                        return $default;
                    };

                    $groupName = $getValue($fieldAliases['group_name'], '');
                    $fieldLabel = $getValue($fieldAliases['field_label'], '');
                    $fieldSlug = $getValue($fieldAliases['field_name_slug'], '');
                    $fieldPlaceholder = $getValue($fieldAliases['field_placeholder'], '');
                    $fieldType = $getValue($fieldAliases['field_type'], 'text');
                    $required = $getValue($fieldAliases['required'], 'no');
                    $defaultValue = $getValue($fieldAliases['default_value'], '');
                    $validationRules = $getValue($fieldAliases['validation_rules'], '');
                    $conditionalRules = $getValue($fieldAliases['conditional_rules'], '');
                    $mediaLimit = $getValue($fieldAliases['media_limit']);
                    $mediaSize = $getValue($fieldAliases['media_size']);
                    $mediaFormat = $getValue($fieldAliases['media_format'], '');
                    $status = $getValue($fieldAliases['status'], '1');
                    $postTypeSlugs = $getValue($fieldAliases['post_type_slugs'], '');
                    $locationRulesJson = $getValue($fieldAliases['location_rules']);
                    $optionsJson = $getValue($fieldAliases['options']);
                    $repeatersJson = $getValue($fieldAliases['repeaters']);
                    $checkboxType = $getValue($fieldAliases['checkbox_type']);
                    $hasFeatured = $getValue($fieldAliases['has_featured'], '0');
                } else {
                    // Positional fallback
                    // If col 0 is numeric, col 1 is group name (format with group_id as first column)
                    $isCol0GroupId = is_numeric(trim((string)($row[0] ?? ''))) && !empty($row[1]);
                    $offset = $isCol0GroupId ? 1 : 0;

                    $groupName = trim((string) ($row[$offset + 0] ?? ''));
                    $fieldLabel = trim((string) ($row[$offset + 1] ?? ''));
                    $fieldSlug = trim((string) ($row[$offset + 2] ?? ''));
                    $fieldPlaceholder = trim((string) ($row[$offset + 3] ?? ''));
                    $fieldType = trim((string) ($row[$offset + 4] ?? 'text'));
                    $required = trim((string) ($row[$offset + 5] ?? 'no'));
                    $defaultValue = trim((string) ($row[$offset + 6] ?? ''));
                    $validationRules = trim((string) ($row[$offset + 7] ?? ''));
                    $conditionalRules = trim((string) ($row[$offset + 8] ?? ''));
                    $mediaLimit = $row[$offset + 9] ?? null;
                    $mediaSize = $row[$offset + 10] ?? null;
                    $mediaFormat = trim((string) ($row[$offset + 11] ?? ''));
                    $status = trim((string) ($row[$offset + 12] ?? '1'));
                    $postTypeSlugs = trim((string) ($row[$offset + 13] ?? ''));
                    $locationRulesJson = $row[$offset + 14] ?? null;
                    $optionsJson = $row[$offset + 15] ?? null;
                    $repeatersJson = $row[$offset + 16] ?? null;
                    $checkboxType = null;
                    $hasFeatured = '0';
                }

                if (empty($groupName) || (empty($fieldLabel) && empty($fieldSlug))) {
                    $skipped++;
                    $errors[] = [
                        'row_number' => $rowNumber,
                        'message' => 'Required fields missing: group_name and at least field_label or field_name_slug.',
                    ];
                    continue;
                }

                // If label is missing but slug exists, generate label
                if (empty($fieldLabel) && !empty($fieldSlug)) {
                    $fieldLabel = ucwords(str_replace(['_', '-'], ' ', $fieldSlug));
                }

                // Ensure clean slug
                if (empty($fieldSlug) && !empty($fieldLabel)) {
                    $fieldSlug = \Illuminate\Support\Str::slug($fieldLabel, '_');
                } else {
                    $fieldSlug = \Illuminate\Support\Str::slug($fieldSlug, '_');
                }

                $fieldType = $this->normalizeFieldType($fieldType);

                // Normalize required enum to 'yes' or 'no'
                $cleanRequired = strtolower(trim((string) $required));
                $requiredNormalized = in_array($cleanRequired, ['yes', '1', 'true', 'required', 'y'], true) ? 'yes' : 'no';

                // Normalize status
                $statusVal = strtolower(trim((string) $status));
                $statusNormalized = !in_array($statusVal, ['0', 'false', 'no', 'inactive'], true);

                // Normalize has_featured
                $featuredVal = strtolower(trim((string) $hasFeatured));
                $hasFeaturedNormalized = in_array($featuredVal, ['1', 'true', 'yes'], true);

                // Create/Find Group
                $group = CustomFieldGroup::firstOrCreate(
                    ['group_name' => $groupName]
                );

                // Create Group Location Rules ONCE per group
                if (!isset($processedGroupLocationRules[$group->id])) {
                    $processedGroupLocationRules[$group->id] = true;

                    $rulesArray = !empty($locationRulesJson) ? json_decode($locationRulesJson, true) : null;

                    if (is_array($rulesArray) && !empty($rulesArray)) {
                        CustomFieldGroupLocationRule::where('custom_field_group_id', $group->id)
                            ->whereNull('custom_field_id')
                            ->delete();

                        foreach ($rulesArray as $ruleIdx => $r) {
                            $showIf = $r['show_if'] ?? 'post_type';
                            if (!in_array($showIf, ['post_type', 'taxonomy'], true)) {
                                $showIf = 'post_type';
                            }

                            $postTypeId = null;
                            if ($showIf === 'post_type') {
                                if (!empty($r['post_type_slug'])) {
                                    $postTypeId = PostType::where('slug', $r['post_type_slug'])->value('id');
                                } elseif (!empty($r['post_type_id'])) {
                                    $postTypeId = $r['post_type_id'];
                                }
                            }

                            $taxonomyId = null;
                            if ($showIf === 'taxonomy') {
                                if (!empty($r['taxonomy_slug'])) {
                                    $taxonomyId = Taxonomy::where('slug', $r['taxonomy_slug'])->value('id');
                                } elseif (!empty($r['taxonomy_id'])) {
                                    $taxonomyId = $r['taxonomy_id'];
                                }
                            }

                            CustomFieldGroupLocationRule::create([
                                'custom_field_group_id' => $group->id,
                                'custom_field_id' => null,
                                'logic_operator' => $r['logic_operator'] ?? ($ruleIdx === 0 ? null : 'and'),
                                'rule_group' => $r['rule_group'] ?? 1,
                                'show_if' => $showIf,
                                'operator' => $r['operator'] ?? 'is_equal_to',
                                'match_type' => $r['match_type'] ?? 'specific',
                                'post_type_id' => $postTypeId,
                                'taxonomy_id' => $taxonomyId,
                                'taxonomy_term_ids' => $r['taxonomy_term_ids'] ?? [],
                                'status' => true,
                                'sort_order' => $ruleIdx + 1,
                            ]);
                        }
                    } elseif (!empty($postTypeSlugs)) {
                        CustomFieldGroupLocationRule::where('custom_field_group_id', $group->id)
                            ->whereNull('custom_field_id')
                            ->delete();

                        $slugs = array_map('trim', explode(',', $postTypeSlugs));
                        $postTypes = PostType::whereIn('slug', $slugs)->get();

                        foreach ($postTypes as $ruleIdx => $postType) {
                            CustomFieldGroupLocationRule::create([
                                'custom_field_group_id' => $group->id,
                                'custom_field_id' => null,
                                'logic_operator' => $ruleIdx === 0 ? null : 'and',
                                'rule_group' => 1,
                                'show_if' => 'post_type',
                                'match_type' => 'specific',
                                'operator' => 'is_equal_to',
                                'post_type_id' => $postType->id,
                                'status' => true,
                                'sort_order' => $ruleIdx + 1,
                            ]);
                        }
                    }
                }

                // Auto-increment sort_order sequentially per group
                if (!isset($groupSortOrders[$group->id])) {
                    $groupSortOrders[$group->id] = 1;
                } else {
                    $groupSortOrders[$group->id]++;
                }
                $autoSortOrder = $groupSortOrders[$group->id];

                // Create/Update Field
                $existingField = CustomField::where('custom_field_group_id', $group->id)
                    ->where('field_name_slug', $fieldSlug)
                    ->first();
                if (!$existingField) {
                    $existingField = CustomField::where('field_name_slug', $fieldSlug)->first();
                }

                $valRulesParsed = !empty($validationRules) ? (is_array($validationRules) ? $validationRules : json_decode($validationRules, true)) : null;
                $condRulesParsed = !empty($conditionalRules) ? (is_array($conditionalRules) ? $conditionalRules : json_decode($conditionalRules, true)) : null;

                $fieldPayload = [
                    'custom_field_group_id' => $group->id,
                    'field_label' => $fieldLabel,
                    'field_placeholder' => $fieldPlaceholder ?: null,
                    'field_type' => $fieldType,
                    'required' => $requiredNormalized,
                    'checkbox_type' => $checkboxType ?: null,
                    'default_value' => $defaultValue ?: null,
                    'validation_rules' => is_array($valRulesParsed) ? $valRulesParsed : null,
                    'conditional_rules' => is_array($condRulesParsed) ? $condRulesParsed : null,
                    'media_limit' => is_numeric($mediaLimit) ? (int)$mediaLimit : null,
                    'media_size' => $mediaSize ?: null,
                    'media_format' => $mediaFormat ?: null,
                    'has_featured' => $hasFeaturedNormalized,
                    'sort_order' => $autoSortOrder,
                    'status' => $statusNormalized,
                    'created_by' => Auth::id() ?? 1,
                ];

                $field = CustomField::updateOrCreate(
                    [
                        'custom_field_group_id' => $group->id,
                        'field_name_slug' => $fieldSlug,
                    ],
                    $fieldPayload
                );

                // Handle Options
                $options = !empty($optionsJson) ? (is_array($optionsJson) ? $optionsJson : json_decode($optionsJson, true)) : null;
                if (!is_array($options) && !empty($optionsJson)) {
                    $rawOpts = array_filter(array_map('trim', explode(',', (string)$optionsJson)));
                    if (!empty($rawOpts)) {
                        $options = array_map(fn($opt) => [
                            'label' => $opt,
                            'value' => \Illuminate\Support\Str::slug($opt, '_'),
                        ], $rawOpts);
                    }
                }

                if (is_array($options)) {
                    CustomFieldOption::where('custom_field_id', $field->id)->delete();
                    foreach ($options as $optIndex => $opt) {
                        CustomFieldOption::create([
                            'custom_field_id' => $field->id,
                            'type' => $fieldType,
                            'name' => $opt['label'] ?? $opt['name'] ?? null,
                            'value' => $opt['value'] ?? null,
                            'sort_order' => $optIndex + 1,
                            'status' => true,
                        ]);
                    }
                }

                // Handle Repeaters
                $repeaters = !empty($repeatersJson) ? (is_array($repeatersJson) ? $repeatersJson : json_decode($repeatersJson, true)) : null;
                if (is_array($repeaters)) {
                    foreach ($repeaters as $repIndex => $rep) {
                        if (empty($rep['field_name_slug']))
                            continue;

                        $repeater = CustomFieldRepeater::updateOrCreate(
                            [
                                'custom_field_id' => $field->id,
                                'field_name_slug' => $rep['field_name_slug'],
                            ],
                            [
                                'field_label' => $rep['fieldName'] ?? $rep['field_label'] ?? null,
                                'field_type' => $rep['fieldType'] ?? $rep['field_type'] ?? null,
                                'field_placeholder' => $rep['fieldPlaceholder'] ?? $rep['field_placeholder'] ?? null,
                                'media_format' => isset($rep['fieldMediaFormat'])
                                    ? (is_array($rep['fieldMediaFormat']) ? implode(',', $rep['fieldMediaFormat']) : $rep['fieldMediaFormat'])
                                    : ($rep['media_format'] ?? null),
                                'media_limit' => isset($rep['fieldMediaLimit']) ? (int)$rep['fieldMediaLimit'] : ($rep['media_limit'] ?? null),
                                'media_size' => $rep['fieldMediaSize'] ?? $rep['media_size'] ?? null,
                                'sort_order' => $repIndex + 1,
                                'status' => true,
                            ]
                        );

                        if (isset($rep['fieldOptions']) && is_array($rep['fieldOptions'])) {
                            CustomFieldRepeaterOption::where('custom_field_repeater_id', $repeater->id)->delete();
                            foreach ($rep['fieldOptions'] as $optIndex => $opt) {
                                CustomFieldRepeaterOption::create([
                                    'custom_field_repeater_id' => $repeater->id,
                                    'type' => $rep['fieldType'] ?? $rep['field_type'] ?? null,
                                    'name' => $opt['name'] ?? $opt['label'] ?? null,
                                    'value' => $opt['value'] ?? null,
                                    'sort_order' => $optIndex + 1,
                                    'status' => true,
                                ]);
                            }
                        }
                    }
                }

                if ($existingField) {
                    $updated++;
                } else {
                    $created++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Custom field groups imported successfully!',
                'summary' => [
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                ],
                'errors' => $errors,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export Custom Field Groups to CSV
     */
    public function exportToCsv()
    {
        try {
            $groups = CustomFieldGroup::with([
                'fields' => fn($q) => $q->orderBy('sort_order', 'asc')->orderBy('id', 'asc'),
                'fields.options',
                'fields.repeaters.options',
                'locationRules.postType',
                'locationRules.taxonomy',
            ])->get();

            if ($groups->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No custom field groups found to export.',
                ], 404);
            }

            $rows = [];
            foreach ($groups as $group) {
                // Post type slugs from location rules
                $postTypeSlugs = $group->locationRules
                    ->whereNull('custom_field_id')
                    ->whereNotNull('post_type_id')
                    ->pluck('postType.slug')
                    ->filter()
                    ->implode(', ');

                // Full group-level location rules JSON
                $groupLocationRules = $group->locationRules
                    ->whereNull('custom_field_id')
                    ->sortBy('sort_order')
                    ->values()
                    ->map(function ($rule) {
                        return [
                            'show_if' => $rule->show_if,
                            'logic_operator' => $rule->logic_operator,
                            'operator' => $rule->operator ?? 'is_equal_to',
                            'match_type' => $rule->match_type ?? 'specific',
                            'post_type_slug' => $rule->postType?->slug,
                            'taxonomy_slug' => $rule->taxonomy?->slug,
                            'taxonomy_term_ids' => $rule->taxonomy_term_ids ?? [],
                        ];
                    })->values()->toArray();

                $locationRulesJson = !empty($groupLocationRules) ? json_encode($groupLocationRules, JSON_UNESCAPED_UNICODE) : '';

                foreach ($group->fields as $field) {
                    // Options
                    $options = $field->options
                        ? $field->options->map(fn($opt) => [
                            "label" => $opt->name,
                            "value" => $opt->value
                        ])->values()->toArray()
                        : [];

                    // Repeaters
                    $repeaters = $field->repeaters
                        ? $field->repeaters->map(fn($rep) => [
                            "fieldName" => $rep->field_label,
                            "fieldType" => $rep->field_type,
                            "field_name_slug" => $rep->field_name_slug,
                            "fieldPlaceholder" => $rep->field_placeholder,
                            "fieldMediaFormat" => $rep->media_format ? explode(',', $rep->media_format) : [],
                            "fieldMediaLimit" => $rep->media_limit,
                            "fieldMediaSize" => $rep->media_size,
                            "fieldOptions" => $rep->options->map(fn($opt) => [
                                "name" => $opt->name,
                                "value" => $opt->value
                            ])->values()->toArray()
                        ])->values()->toArray()
                        : [];

                    $rows[] = [
                        'group_name' => $group->group_name,
                        'field_label' => $field->field_label,
                        'field_name_slug' => $field->field_name_slug,
                        'field_placeholder' => $field->field_placeholder,
                        'field_type' => $field->field_type,
                        'required' => $field->required,
                        'default_value' => $field->default_value,
                        'validation_rules' => $field->validation_rules ? json_encode($field->validation_rules) : '',
                        'conditional_rules' => $field->conditional_rules ? json_encode($field->conditional_rules) : '',
                        'media_limit' => $field->media_limit,
                        'media_size' => $field->media_size,
                        'media_format' => $field->media_format,
                        'status' => $field->status,
                        'post_type_slugs' => $postTypeSlugs,
                        'location_rules' => $locationRulesJson,
                        'options' => json_encode($options, JSON_UNESCAPED_UNICODE),
                        'repeaters' => json_encode($repeaters, JSON_UNESCAPED_UNICODE),
                    ];
                }
            }

            $fileName = 'custom_field_groups_export_' . date('Ymd_His') . '.csv';
            $filePath = storage_path("app/public/customFieldGroups/" . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            $df = fopen($filePath, 'w');
            fputcsv($df, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($df, $row);
            }
            fclose($df);

            return response()->download($filePath)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function normalizeFieldType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = str_replace('-', '_', $type);

        $map = [
            'text_editor' => 'texteditor',
            'text-editor' => 'texteditor',
            'wysiwyg' => 'texteditor',
            'editor' => 'texteditor',
            'rich_text' => 'texteditor',
            'richtext' => 'texteditor',
            'text_area' => 'textarea',
            'date_time' => 'datetime',
            'bool' => 'boolean',
            'image' => 'media',
        ];

        $allowed = [
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
            'file'
        ];

        if (isset($map[$type])) {
            return $map[$type];
        }

        if (in_array($type, $allowed, true)) {
            return $type;
        }

        return 'text';
    }
}