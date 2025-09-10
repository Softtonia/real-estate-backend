<?php

namespace App\Http\Controllers\CustomField;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Groupname;

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



}
