<?php

namespace App\Http\Controllers\CustomField;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Groupname;

use Illuminate\Support\Facades\DB;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterOption;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\CustomFieldUniqueCode;

class CustomFieldExportImportController extends Controller
{



public function importFromCsv(Request $request)
{
    try {
        \Log::info('Custom Fields CSV Import Started', [
            'request_data' => $request->except(['csv_file']),
            'has_file' => $request->hasFile('csv_file'),
        ]);

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt',
        ]);

        $file = $request->file('csv_file');

        \Log::info('Custom Fields CSV File Details', [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
        ]);

        DB::beginTransaction();

        $excelData = Excel::toArray([], $file);

        if (empty($excelData) || empty($excelData[0])) {
            \Log::error('Custom Fields CSV Import Failed: Empty CSV');

            return response()->json([
                'status' => false,
                'message' => 'CSV file is empty.',
            ], 422);
        }

        $rows = $excelData[0];

        $header = $rows[0] ?? [];

        \Log::info('Custom Fields CSV Header', [
            'header' => $header,
            'header_count' => count($header),
        ]);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $rowNumber = $index + 2;

            \Log::info('Custom Fields CSV Row Reading', [
                'row_number' => $rowNumber,
                'row_count' => count($row),
                'row_data' => $row,
            ]);

            // Skip fully empty rows
            if (count(array_filter($row, function ($value) {
                return trim((string) $value) !== '';
            })) === 0) {
                $skipped++;

                \Log::warning('Custom Fields CSV Row Skipped: Empty row', [
                    'row_number' => $rowNumber,
                ]);

                continue;
            }

            // Your export has 16 columns from index 0 to 15
            $expectedColumnCount = 16;

            if (count($row) < $expectedColumnCount) {
                \Log::warning('Custom Fields CSV Row Has Less Columns', [
                    'row_number' => $rowNumber,
                    'expected_columns' => $expectedColumnCount,
                    'actual_columns' => count($row),
                    'row_before_fix' => $row,
                ]);

                $row = array_pad($row, $expectedColumnCount, null);
            }

            if (count($row) > $expectedColumnCount) {
                \Log::warning('Custom Fields CSV Row Has Extra Columns', [
                    'row_number' => $rowNumber,
                    'expected_columns' => $expectedColumnCount,
                    'actual_columns' => count($row),
                    'row_before_fix' => $row,
                ]);

                $row = array_slice($row, 0, $expectedColumnCount);
            }

            $groupId = $row[0] ?? null;
            $groupName = trim((string) ($row[1] ?? ''));
            $fieldLabel = trim((string) ($row[2] ?? ''));
            $fieldSlug = trim((string) ($row[3] ?? ''));
            $fieldPlaceholder = $row[4] ?? null;
            $fieldType = trim((string) ($row[5] ?? ''));
            $required = trim((string) ($row[6] ?? ''));
            $postType = trim((string) ($row[7] ?? ''));
            $templateId = $row[8] ?? null;

$templateId = !empty($templateId) && CustomFieldUniqueCode::where('id', $templateId)->exists()
    ? $templateId
    : null;
            $mediaLimit = $row[9] ?? null;
            $mediaSize = $row[10] ?? null;
            $mediaFormat = $row[11] ?? null;
            $optionsJson = $row[12] ?? null;
            $repeatersJson = $row[13] ?? null;
            $modelFields = $row[14] ?? null;
            $checkboxType = $row[15] ?? null;

            if ($groupName === '' || $fieldLabel === '' || $fieldSlug === '' || $fieldType === '' || $required === '' || $postType === '') {
                $skipped++;

                $errors[] = [
                    'row_number' => $rowNumber,
                    'message' => 'Required data missing. Required: group_name, field_label, field_name_slug, field_type, required, post_type.',
                    'row_data' => $row,
                ];

                \Log::warning('Custom Fields CSV Row Skipped: Required data missing', [
                    'row_number' => $rowNumber,
                    'row_data' => $row,
                ]);

                continue;
            }

            \Log::info('Custom Fields CSV Row Processing', [
                'row_number' => $rowNumber,
                'group_id' => $groupId,
                'group_name' => $groupName,
                'field_label' => $fieldLabel,
                'field_name_slug' => $fieldSlug,
                'field_type' => $fieldType,
                'post_type' => $postType,
            ]);

            // Create/Find Group
            if (!empty($groupId)) {
                $group = Groupname::find($groupId);

                if (!$group) {
                    $group = Groupname::firstOrCreate([
                        'group_name' => $groupName,
                    ]);
                }
            } else {
                $group = Groupname::firstOrCreate([
                    'group_name' => $groupName,
                ]);
            }

            if (!isset($groupSortOrders[$group->id])) {
                $groupSortOrders[$group->id] = 1;
            } else {
                $groupSortOrders[$group->id]++;
            }
            $autoSortOrder = $groupSortOrders[$group->id];

            $existingField = CustomField::where('field_name_slug', $fieldSlug)->first();

            // Insert/Update Custom Field
            $field = CustomField::updateOrCreate(
                [
                    'field_name_slug' => $fieldSlug,
                ],
                [
                    'group_id' => $group->id,
                    'field_label' => $fieldLabel,
                    'field_placeholder' => $fieldPlaceholder ?: null,
                    'field_type' => $fieldType,
                    'required' => $required,
                    'post_type' => $postType,
                    'template_id' => $templateId,
                    'media_limit' => $mediaLimit ?: null,
                    'media_size' => $mediaSize ?: null,
                    'media_format' => $mediaFormat ?: null,
                    'model_fields' => $modelFields ?: null,
                    'checkbox_type' => $checkboxType ?: null,
                    'sort_order' => $autoSortOrder,
                ]
            );

            if ($existingField) {
                $updated++;
            } else {
                $created++;
            }

            // Options
            $options = json_decode($optionsJson, true);

            if (json_last_error() !== JSON_ERROR_NONE && !empty($optionsJson)) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'message' => 'Invalid options JSON: ' . json_last_error_msg(),
                ];

                \Log::warning('Custom Fields CSV Invalid Options JSON', [
                    'row_number' => $rowNumber,
                    'options_json' => $optionsJson,
                    'json_error' => json_last_error_msg(),
                ]);
            }

            if (is_array($options)) {
                CustomFieldOption::where('custom_field_id', $field->id)->delete();

                foreach ($options as $opt) {
                    CustomFieldOption::create([
                        'custom_field_id' => $field->id,
                        'type' => $fieldType,
                        'name' => $opt['label'] ?? $opt['name'] ?? null,
                        'value' => $opt['value'] ?? null,
                    ]);
                }
            }

            // Repeaters
            $repeaters = json_decode($repeatersJson, true);

            if (json_last_error() !== JSON_ERROR_NONE && !empty($repeatersJson)) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'message' => 'Invalid repeater JSON: ' . json_last_error_msg(),
                ];

                \Log::warning('Custom Fields CSV Invalid Repeater JSON', [
                    'row_number' => $rowNumber,
                    'repeater_json' => $repeatersJson,
                    'json_error' => json_last_error_msg(),
                ]);
            }

            if (is_array($repeaters)) {
                foreach ($repeaters as $rep) {
                    if (empty($rep['field_name_slug'])) {
                        $errors[] = [
                            'row_number' => $rowNumber,
                            'message' => 'Repeater field_name_slug missing.',
                            'repeater_data' => $rep,
                        ];

                        \Log::warning('Custom Fields CSV Repeater Skipped: field_name_slug missing', [
                            'row_number' => $rowNumber,
                            'repeater_data' => $rep,
                        ]);

                        continue;
                    }

                    $repeater = CustomFieldRepeater::updateOrCreate(
                        [
                            'field_name_slug' => $rep['field_name_slug'],
                        ],
                        [
                            'group_id' => $group->id,
                            'custom_field_id' => $field->id,
                            'field_label' => $rep['fieldName'] ?? null,
                            'field_type' => $rep['fieldType'] ?? null,
                            'field_placeholder' => $rep['fieldPlaceholder'] ?? null,
                            'media_format' => isset($rep['fieldMediaFormat'])
                                ? (is_array($rep['fieldMediaFormat']) ? implode(',', $rep['fieldMediaFormat']) : $rep['fieldMediaFormat'])
                                : null,
                            'media_limit' => $rep['fieldMediaLimit'] ?? null,
                            'media_size' => $rep['fieldMediaSize'] ?? null,
                        ]
                    );

                    if (isset($rep['fieldOptions']) && is_array($rep['fieldOptions'])) {
                        CustomFieldRepeaterOption::where('custom_field_repeater_id', $repeater->id)->delete();

                        foreach ($rep['fieldOptions'] as $opt) {
                            CustomFieldRepeaterOption::create([
                                'custom_field_repeater_id' => $repeater->id,
                                'type' => $rep['fieldType'] ?? null,
                                'name' => $opt['name'] ?? $opt['label'] ?? null,
                                'value' => $opt['value'] ?? null,
                            ]);
                        }
                    }
                }
            }

            \Log::info('Custom Fields CSV Row Imported Successfully', [
                'row_number' => $rowNumber,
                'field_id' => $field->id,
            ]);
        }

        DB::commit();

        \Log::info('Custom Fields CSV Import Completed', [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'CSV imported successfully!',
            'summary' => [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ],
            'errors' => $errors,
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        \Log::error('Custom Fields CSV Import Validation Error', [
            'errors' => $e->errors(),
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $e->errors(),
        ], 422);

    } catch (\Exception $e) {
        DB::rollBack();

        \Log::error('Custom Fields CSV Import Exception', [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'status' => false,
            'message' => 'CSV import failed.',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
}



public function exportToCsv()
{
    try {
        // Fetch all groups with fields, options, repeater + repeaterOptions
        $groups = Groupname::with(['customFields.options', 'customFields.repeaters.repeaterOptions'])->get();

        $rows = [];

        foreach ($groups as $group) {
            foreach ($group->customFields as $field) {
                // Options (select, checkbox, radio)
                $options = $field->options
                    ? $field->options->map(function ($opt) {
                        return [
                            "label" => $opt->name,
                            "value" => $opt->value
                        ];
                    })->values()->toArray()
                    : [];

                // Repeaters
                $repeaters = $field->repeaters
                    ? $field->repeaters->map(function ($rep) {
                        $repOptions = $rep->repeaterOptions
                            ? $rep->repeaterOptions->map(function ($opt) {
                                return [
                                    "name" => $opt->name,
                                    "value" => $opt->value
                                ];
                            })->values()->toArray()
                            : [];

                        return [
                            "fieldName" => $rep->field_label,
                            "fieldType" => $rep->field_type,
                            "field_name_slug" => $rep->field_name_slug,
                            "fieldPlaceholder" => $rep->field_placeholder,
                            "fieldMediaFormat" => $rep->media_format ? explode(',', $rep->media_format) : [],
                            "fieldMediaLimit" => $rep->media_limit,
                            "fieldMediaSize" => $rep->media_size,
                            "fieldOptions" => $repOptions
                        ];
                    })->values()->toArray()
                    : [];

                $rows[] = [
                    "group_id" => $group->id,
                    "group_name" => $group->group_name,
                    "field_label" => $field->field_label,
                    "field_name_slug" => $field->field_name_slug,
                    // "field_name" => $field->field_name_slug, // adjust if DB has separate field_name column
                    "field_placeholder" => $field->field_placeholder,
                    "field_type" => $field->field_type,
                    "required" => $field->required,
                    "post_type" => $field->post_type,
                    "template_id" => $field->template_id,
                    "media_limit" => $field->media_limit,
                    "media_size" => $field->media_size,
                    "media_format" => $field->media_format,
                    "options" => json_encode($options, JSON_UNESCAPED_UNICODE),
                    "repeater" => json_encode($repeaters, JSON_UNESCAPED_UNICODE),
                    "modelFields" => $field->model_fields,
                    "checkbox_type" => $field->checkbox_type,
                ];
            }
        }

        // Save CSV
        $fileName = "customfields_export_" . date('Ymd_His') . ".csv";
        $filePath = storage_path("app/public/customFields/" . $fileName);

        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        $df = fopen($filePath, 'w');
        fputcsv($df, array_keys($rows[0])); // header
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
