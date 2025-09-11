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




    public function ExportCustomFieldsCsv(Request $request)
    {
        try {

            $customFields = CustomField::with(['repeaters', 'templateValue'])->latest('created_at')->get();

            $response = new StreamedResponse(function () use ($customFields) {
                $handle = fopen('php://output', 'w');

                // CSV Header
                fputcsv($handle, [
                    'ID',
                    'Group ID',
                    'Group Name',
                    'Field Label',
                    'Field Type',
                    'Post Type',
                    'Required',
                    'Field Slug',
                    'Placeholder',
                    'Media Limit',
                    'Media Size',
                    'Media Format',
                    'Template ID',
                    'Template Value',
                    'Options',
                    'Repeaters',
                    'Created At',
                    'Updated At'
                ]);

                foreach ($customFields as $field) {
                    // Options
                    $options = CustomFieldOption::where('custom_field_id', $field->id)->get()->map(function ($option) {
                        return [
                            'label' => $option->name,
                            'value' => $option->value,
                            'created_at' => $option->created_at->format('Y-m-d H:i:s'),
                            'updated_at' => $option->updated_at->format('Y-m-d H:i:s'),
                        ];
                    })->toArray();

                    // Repeaters
                    $repeaters = $field->repeaters->map(function ($repeater) {
                        return [
                            'id' => $repeater->id,
                            'field_label' => $repeater->field_label,
                            'field_name_slug' => $repeater->field_name_slug,
                            'field_type' => $repeater->field_type,
                            'field_placeholder' => $repeater->field_placeholder,
                            'media_limit' => $repeater->media_limit,
                            'media_size' => $repeater->media_size,
                            'media_format' => $repeater->media_format,
                            'created_at' => $repeater->created_at->format('Y-m-d H:i:s'),
                            'updated_at' => $repeater->updated_at->format('Y-m-d H:i:s'),
                        ];
                    })->toArray();

                    fputcsv($handle, [
                        $field->id,
                        $field->group_id,
                        Groupname::where('id', $field->group_id)->value('group_name'),
                        $field->field_label,
                        $field->field_type,
                        $field->post_type,
                        $field->required,
                        $field->field_name_slug,
                        $field->field_placeholder,
                        $field->media_limit,
                        $field->media_size,
                        $field->media_format,
                        $field->template_id,
                        $field->templateValue ? json_encode($field->templateValue) : null,
                        json_encode($options, JSON_UNESCAPED_UNICODE),
                        json_encode($repeaters, JSON_UNESCAPED_UNICODE),
                        $field->created_at->format('Y-m-d H:i:s'),
                        $field->updated_at->format('Y-m-d H:i:s'),
                    ]);
                }

                fclose($handle);
            });

            $fileName = 'custom_fields_export_' . date('Y_m_d_H_i_s') . '.csv';

            $response->headers->set('Content-Type', 'text/csv');
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$fileName.'"');

            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'CSV export failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Agar CSV file upload ki gayi hai to usko parse karo aur fields banalo
            if ($request->hasFile('csv_file')) {
                $request->validate([
                    'csv_file' => 'required|file|mimes:csv,txt',
                ]);

                $file = fopen($request->file('csv_file')->getRealPath(), 'r');
                $header = fgetcsv($file);

                $fields = [];
                while (($row = fgetcsv($file)) !== false) {
                    $record = array_combine($header, $row);

                    // Convert media_format
                    $mediaFormat = !empty($record['media_format'])
                        ? explode('|', $record['media_format'])
                        : [];

                    // Convert options
                    $options = [];
                    if (!empty($record['options'])) {
                        $optionPairs = explode(';', $record['options']);
                        foreach ($optionPairs as $pair) {
                            $parts = explode('|', $pair);
                            $label = str_replace('label:', '', $parts[0] ?? '');
                            $value = str_replace('value:', '', $parts[1] ?? '');
                            $options[] = ['label' => $label, 'value' => $value];
                        }
                    }

                    // Convert repeater (if JSON string)
                    $repeater = !empty($record['repeater']) ? json_decode($record['repeater'], true) : [];

                    // Convert modelFields (if JSON string)
                    $modelFields = !empty($record['modelFields']) ? json_decode($record['modelFields'], true) : [];

                    $fields[] = [
                        'group_name' => $record['group_name'] ?? null,
                        'group_id' => $record['group_id'] ?? null,
                        'field_label' => $record['field_label'],
                        'field_name_slug' => $record['field_name_slug'],
                        'field_placeholder' => $record['field_placeholder'] ?? null,
                        'field_type' => $record['field_type'],
                        'required' => $record['required'],
                        'post_type' => $record['post_type'],
                        'template_id' => $record['template_id'] ?? null,
                        'media_limit' => $record['media_limit'] ?? null,
                        'media_size' => $record['media_size'] ?? null,
                        'media_format' => $mediaFormat,
                        'checkbox_type' => $record['checkbox_type'] ?? null,
                        'options' => $options,
                        'repeater' => $repeater,
                        'modelFields' => $modelFields,
                    ];
                }
                fclose($file);

                // merge fields into request
                $request->merge([
                    'fields' => $fields,
                    'group_id' => $fields[0]['group_id'],
                    'group_name' => $fields[0]['group_name'],
                ]);
            }

            // Ab yaha se aapka purana wala code run karega jaisa hai waisa hi
            $validatedData = $request->validate([
                'group_id' => 'nullable|integer|exists:group_name,id',
                'group_name' => 'nullable|string|max:255',

                'fields' => 'required|array',
                'fields.*.field_label' => 'required|string|max:255',
                'fields.*.checkbox_type' => 'nullable|string',
                'fields.*.field_name_slug' => 'required|string|max:255|unique:custom_fields,field_name_slug',
                'fields.*.field_placeholder' => 'nullable|string|max:255',
                'fields.*.field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file,number',
                'fields.*.required' => 'required|string|in:yes,no',
                'fields.*.post_type' => 'required|string|max:255',
                'fields.*.template_id' => 'nullable|integer|exists:custom_field_unique_codes,id',
                'fields.*.media_limit' => 'nullable|integer',
                'fields.*.media_size' => 'nullable|string',
                'fields.*.media_format' => 'nullable|array',
                'fields.*.options' => 'nullable|array',
                'fields.*.repeater' => 'nullable|array',
                'fields.*.modelFields' => 'nullable|array',
                'fields.*.modelFields.*.model' => 'nullable|string',
                'fields.*.modelFields.*.condition' => 'nullable|array',
                'fields.*.options.*.label' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
                'fields.*.options.*.value' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
            ]);

            // yaha se pura wahi aapka DB transaction aur insert ka logic chalega jaisa pehle tha...
            DB::beginTransaction();

            if (empty($validatedData['group_id']) && empty($validatedData['group_name'])) {
                return response()->json(['error' => 'Either group_id or group_name must be provided'], 422);
            }

            if (!empty($validatedData['group_id'])) {
                $groupData = Groupname::find($validatedData['group_id']);
            } else {
                $existingGroup = Groupname::where('group_name', $validatedData['group_name'])->first();
                if ($existingGroup) {
                    return response()->json(['error' => 'Group name already exists.'], 422);
                }
                $groupData = Groupname::create([
                    'group_name' => $validatedData['group_name'],
                ]);
            }

            $customFields = [];
            foreach ($validatedData['fields'] as $fieldData) {
                if (!isset($fieldData['field_label'])) {
                    return response()->json(['error' => 'Missing field_label in one or more fields'], 422);
                }

                $mediaFormat = isset($fieldData['media_format']) ? implode(',', $fieldData['media_format']) : null;

                $modelFieldsData = [];
                if (isset($fieldData['modelFields']) && is_array($fieldData['modelFields'])) {
                    foreach ($fieldData['modelFields'] as $modelField) {
                        $modelFieldsData[] = [
                            'model' => $modelField['model'],
                            'condition' => $modelField['condition'],
                        ];
                    }
                }

                $field = CustomField::create([
                    'group_id' => $groupData->id,
                    'field_label' => $fieldData['field_label'],
                    'field_name_slug' => $fieldData['field_name_slug'],
                    'field_placeholder' => $fieldData['field_placeholder'] ?? null,
                    'field_type' => $fieldData['field_type'],
                    'required' => $fieldData['required'],
                    'checkbox_type' => $fieldData['checkbox_type'] ?? null,
                    'post_type' => $fieldData['post_type'],
                    'template_id' => $fieldData['template_id'] ?? null,
                    'media_limit' => $fieldData['media_limit'] ?? null,
                    'media_size' => $fieldData['media_size'] ?? null,
                    'media_format' => $mediaFormat,
                    'model_fields' => json_encode($modelFieldsData),
                ]);

                if (in_array($fieldData['field_type'], ['select', 'checkbox', 'radio']) && isset($fieldData['options'])) {
                    foreach ($fieldData['options'] as $option) {
                        CustomFieldOption::create([
                            'custom_field_id' => $field->id,
                            'type' => $fieldData['field_type'],
                            'name' => $option['label'],
                            'value' => $option['value'],
                        ]);
                    }
                }

                if ($fieldData['field_type'] === 'repeater' && isset($fieldData['repeater'])) {
                    foreach ($fieldData['repeater'] as $repeaterItem) {
                        if (!isset($repeaterItem['fieldName'])) {
                            return response()->json(['error' => 'Missing fieldName in one or more repeater fields'], 422);
                        }

                        $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat'])
                            ? (is_array($repeaterItem['fieldMediaFormat'])
                                ? implode(',', $repeaterItem['fieldMediaFormat'])
                                : $repeaterItem['fieldMediaFormat'])
                            : null;

                        $repeaterField = CustomFieldRepeater::create([
                            'group_id' => $groupData->id,
                            'custom_field_id' => $field->id,
                            'field_label' => $repeaterItem['fieldName'],
                            'field_type' => $repeaterItem['fieldType'],
                            'field_name_slug' => $repeaterItem['field_name_slug'],
                            'field_placeholder' => $repeaterItem['fieldPlaceholder'] ?? null,
                            'media_format' => $fieldMediaFormat,
                            'media_limit' => $repeaterItem['fieldMediaLimit'] ?? null,
                            'media_size' => $repeaterItem['fieldMediaSize'] ?? null,
                        ]);

                        if (isset($repeaterItem['fieldOptions'])) {
                            foreach ($repeaterItem['fieldOptions'] as $option) {
                                CustomFieldRepeaterOption::create([
                                    'custom_field_repeater_id' => $repeaterField->id,
                                    'type' => $repeaterItem['fieldType'],
                                    'name' => $option['name'],
                                    'value' => $option['value'],
                                ]);
                            }
                        }
                    }
                }

                $customFields[] = $field->toArray();
            }

            DB::commit();

            return response()->json([
                'message' => 'Added successfully',
                'fields' => $customFields,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }






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

            // 1️⃣ Create/Find Group
            if (!empty($groupId)) {
                $group = Groupname::find($groupId);
            } else {
                $group = Groupname::firstOrCreate(['group_name' => $groupName]);
            }

            // 2️⃣ Insert Custom Field
            $field = CustomField::create([
                'group_id' => $group->id,
                'field_label' => $row[2],
                'field_name_slug' => $row[3],
                'field_placeholder' => $row[5],
                'field_type' => $row[6],
                'required' => $row[7],
                'post_type' => $row[8],
                'template_id' => $row[9] ?: null,
                'media_limit' => $row[10] ?: null,
                'media_size' => $row[11] ?: null,
                'media_format' => $row[12] ?: null,
                'model_fields' => $row[15] ?: null,
            ]);

            // 3️⃣ Insert Options (checkbox, radio, select)
            $options = json_decode($row[13], true);
            if (is_array($options)) {
                foreach ($options as $opt) {
                    CustomFieldOption::create([
                        'custom_field_id' => $field->id,
                        'type' => $row[6],
                        'name' => $opt['label'] ?? $opt['name'],
                        'value' => $opt['value'],
                    ]);
                }
            }

            // 4️⃣ Insert Repeaters
            $repeaters = json_decode($row[14], true);
            if (is_array($repeaters)) {
                foreach ($repeaters as $rep) {
                    $repeater = CustomFieldRepeater::create([
                        'group_id' => $group->id,
                        'custom_field_id' => $field->id,
                        'field_label' => $rep['fieldName'],
                        'field_type' => $rep['fieldType'],
                        'field_name_slug' => $rep['field_name_slug'],
                        'field_placeholder' => $rep['fieldPlaceholder'] ?? null,
                        'media_format' => isset($rep['fieldMediaFormat']) ? (is_array($rep['fieldMediaFormat']) ? implode(',', $rep['fieldMediaFormat']) : $rep['fieldMediaFormat']) : null,
                        'media_limit' => $rep['fieldMediaLimit'] ?? null,
                        'media_size' => $rep['fieldMediaSize'] ?? null,
                    ]);

                    // 5️⃣ Insert Repeater Options
                    if (isset($rep['fieldOptions']) && is_array($rep['fieldOptions'])) {
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
