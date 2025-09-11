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

class CustomFieldExportImportController extends Controller
{



public function importFromCsv(Request $request)
{
    $request->validate([
        'csv_file' => 'required|file|mimes:csv,txt',
    ]);

    try {
        DB::beginTransaction();

        // Load CSV data
        $rows = Excel::toArray([], $request->file('csv_file'))[0];

        // Skip header row
        foreach (array_slice($rows, 1) as $row) {
            $groupId = $row[0];
            $groupName = $row[1];

            // 1️⃣ Create/Find Group (unique by group_name)
            if (!empty($groupId)) {
                $group = Groupname::find($groupId);
                if (!$group) {
                    $group = Groupname::firstOrCreate(['group_name' => $groupName]);
                }
            } else {
                $group = Groupname::firstOrCreate(['group_name' => $groupName]);
            }

            // 2️⃣ Insert/Update Custom Field (unique by slug only)
            $field = CustomField::updateOrCreate(
                [
                    'field_name_slug' => $row[3],
                ],
                [
                    'group_id' => $group->id,
                    'field_label' => $row[2],
                    'field_placeholder' => $row[5],
                    'field_type' => $row[6],
                    'required' => $row[7],
                    'post_type' => $row[8],
                    'template_id' => $row[9] ?: null,
                    'media_limit' => $row[10] ?: null,
                    'media_size' => $row[11] ?: null,
                    'media_format' => $row[12] ?: null,
                    'model_fields' => $row[15] ?: null,
                ]
            );

            // 3️⃣ Delete Old Options + Insert New Options
            $options = json_decode($row[13], true);
            if (is_array($options)) {
                // delete
                CustomFieldOption::where('custom_field_id', $field->id)->delete();

                //  fresh create
                foreach ($options as $opt) {
                    CustomFieldOption::create([
                        'custom_field_id' => $field->id,
                        'type' => $row[6],
                        'name' => $opt['label'] ?? $opt['name'],
                        'value' => $opt['value'],
                    ]);
                }
            }

            // 4️⃣ Insert/Update Repeaters (unique by slug only)
            $repeaters = json_decode($row[14], true);
            if (is_array($repeaters)) {
                foreach ($repeaters as $rep) {
                    $repeater = CustomFieldRepeater::updateOrCreate(
                        [
                            'field_name_slug' => $rep['field_name_slug'],
                        ],
                        [
                            'group_id' => $group->id,
                            'custom_field_id' => $field->id,
                            'field_label' => $rep['fieldName'],
                            'field_type' => $rep['fieldType'],
                            'field_placeholder' => $rep['fieldPlaceholder'] ?? null,
                            'media_format' => isset($rep['fieldMediaFormat'])
                                ? (is_array($rep['fieldMediaFormat']) ? implode(',', $rep['fieldMediaFormat']) : $rep['fieldMediaFormat'])
                                : null,
                            'media_limit' => $rep['fieldMediaLimit'] ?? null,
                            'media_size' => $rep['fieldMediaSize'] ?? null,
                        ]
                    );

                    // 5️⃣ Delete Old Repeater Options + Insert New
                    if (isset($rep['fieldOptions']) && is_array($rep['fieldOptions'])) {
                        CustomFieldRepeaterOption::where('custom_field_repeater_id', $repeater->id)->delete();

                        foreach ($rep['fieldOptions'] as $opt) {
                            CustomFieldRepeaterOption::create([
                                'custom_field_repeater_id' => $repeater->id,
                                'type' => $rep['fieldType'],
                                'name' => $opt['name'],
                                'value' => $opt['value'],
                            ]);
                        }
                    }
                }
            }
        }

        DB::commit();
        return response()->json(['message' => 'CSV imported successfully!'], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['error' => $e->getMessage()], 500);
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
                    "field_name" => $field->field_name_slug, // adjust if DB has separate field_name column
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
