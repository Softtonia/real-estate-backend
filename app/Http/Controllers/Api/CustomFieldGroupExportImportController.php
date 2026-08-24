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

            // Detect header row
            $firstRow = $rows[0] ?? [];
            $header = array_map(fn($h) => strtolower(trim((string) $h)), $firstRow);

            $hasHeader = in_array('group_name', $header, true) || in_array('field_label', $header, true);

            $headerMap = [];
            if ($hasHeader) {
                foreach ($header as $colIdx => $colName) {
                    $headerMap[$colName] = $colIdx;
                }
            }

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
                    $getValue = function ($key, $default = null) use ($row, $headerMap) {
                        if (isset($headerMap[$key]) && array_key_exists($headerMap[$key], $row)) {
                            return trim((string) ($row[$headerMap[$key]] ?? ''));
                        }
                        return $default;
                    };

                    $groupName = $getValue('group_name', '');
                    $fieldLabel = $getValue('field_label', '');
                    $fieldSlug = $getValue('field_name_slug', '');
                    $fieldPlaceholder = $getValue('field_placeholder', '');
                    $fieldType = $getValue('field_type', '');
                    $required = $getValue('required', '');
                    $defaultValue = $getValue('default_value', '');
                    $validationRules = $getValue('validation_rules', '');
                    $conditionalRules = $getValue('conditional_rules', '');
                    $mediaLimit = $getValue('media_limit');
                    $mediaSize = $getValue('media_size');
                    $mediaFormat = $getValue('media_format', '');
                    $status = $getValue('status', '');
                    $postTypeSlugs = $getValue('post_type_slugs', '');
                    $locationRulesJson = $getValue('location_rules') ?: $getValue('location_rules_json');
                    $optionsJson = $getValue('options') ?: $getValue('options_json');
                    $repeatersJson = $getValue('repeaters') ?: $getValue('repeaters_json');
                } else {
                    // Positional fallback
                    $is17Cols = count($row) >= 17;

                    $groupName = trim((string) ($row[0] ?? ''));
                    $fieldLabel = trim((string) ($row[1] ?? ''));
                    $fieldSlug = trim((string) ($row[2] ?? ''));
                    $fieldPlaceholder = trim((string) ($row[3] ?? ''));
                    $fieldType = trim((string) ($row[4] ?? ''));
                    $required = trim((string) ($row[5] ?? ''));
                    $defaultValue = trim((string) ($row[6] ?? ''));
                    $validationRules = trim((string) ($row[7] ?? ''));
                    $conditionalRules = trim((string) ($row[8] ?? ''));
                    $mediaLimit = $row[9] ?? null;
                    $mediaSize = $row[10] ?? null;
                    $mediaFormat = trim((string) ($row[11] ?? ''));

                    if ($is17Cols) {
                        // 17 cols with location_rules at index 14
                        $status = trim((string) ($row[12] ?? ''));
                        $postTypeSlugs = trim((string) ($row[13] ?? ''));
                        $locationRulesJson = $row[14] ?? null;
                        $optionsJson = $row[15] ?? null;
                        $repeatersJson = $row[16] ?? null;
                    } else {
                        // Standard 16 cols without location_rules
                        $status = trim((string) ($row[12] ?? ''));
                        $postTypeSlugs = trim((string) ($row[13] ?? ''));
                        $locationRulesJson = null;
                        $optionsJson = $row[14] ?? null;
                        $repeatersJson = $row[15] ?? null;
                    }
                }

                if (empty($groupName) || empty($fieldLabel) || empty($fieldSlug) || empty($fieldType)) {
                    $skipped++;
                    $errors[] = [
                        'row_number' => $rowNumber,
                        'message' => 'Required fields missing: group_name, field_label, field_name_slug, field_type.',
                    ];
                    continue;
                }

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

                $field = CustomField::updateOrCreate(
                    ['field_name_slug' => $fieldSlug],
                    [
                        'custom_field_group_id' => $group->id,
                        'field_label' => $fieldLabel,
                        'field_placeholder' => $fieldPlaceholder ?: null,
                        'field_type' => $fieldType,
                        'required' => !empty($required) ? $required : 'no',
                        'default_value' => $defaultValue ?: null,
                        'validation_rules' => !empty($validationRules) ? json_decode($validationRules, true) : null,
                        'conditional_rules' => !empty($conditionalRules) ? json_decode($conditionalRules, true) : null,
                        'media_limit' => $mediaLimit ?: null,
                        'media_size' => $mediaSize ?: null,
                        'media_format' => $mediaFormat ?: null,
                        'sort_order' => $autoSortOrder,
                        'status' => filter_var($status, FILTER_VALIDATE_BOOLEAN) ?? true,
                        'created_by' => Auth::id(),
                    ]
                );

                // Handle Options
                $options = json_decode($optionsJson, true);
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
                $repeaters = json_decode($repeatersJson, true);
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
                                'media_limit' => $rep['fieldMediaLimit'] ?? $rep['media_limit'] ?? null,
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
}