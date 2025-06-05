<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CustomField;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;
use Log;
use App\Models\Media;
use App\Models\Groupname;
use App\Models\Purpose;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Status;
use App\Models\Amenity;
use App\Models\AmenitiesCategory;
use Illuminate\Validation\Rule;
use App\Models\ModelType;
use App\Models\Location;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldUniqueCode;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;


class CustomFieldController extends Controller
{

    // this is for listing of fields
    public function index(Request $request)
    {
        try {
            $groupIdArr = [];
            $data = CustomField::with(['groupname', 'options', 'repeaterFields.repeaterOptions'])->get();
            $customFields = [];

            foreach ($data as $field) {
                if (!in_array($field->group_id, $groupIdArr)) {
                    array_push($groupIdArr, $field->group_id);

                    $countOfFields = CustomField::where('group_id', $field->group_id)->count();
                    $modelConditionName = $this->getModelConditionName($field->model, $field->condition);

                    $modelNameNew = ucwords(str_replace('_', ' ', $field->model));

                    $customFields[] = [
                        'group_data' => $field->groupname,
                        'custom_field_counts' => $countOfFields,
                    ];
                }
            }

            return response()->json($customFields, 200);
        } catch (\Exception $e) {
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }
    public function GetCustomFields(Request $request)
    {
        try {
            // Get the current page, default to 1
            $currentPage = $request->input('page', 1);

            // Fetch paginated custom fields with 10 items per page
            $customFields = CustomField::with('repeaters') // Load repeaters for each field
                ->latest('created_at')
                ->paginate(10, ['*'], 'page', $currentPage);

            // Check if a specific custom field ID is provided
            if ($request->has('custom_field_counts_id')) {
                $custom_field_id = (int) $request->custom_field_counts_id;

                // Fetch the custom field with repeaters
                $customField = CustomField::with('repeaters')->where('id', $custom_field_id)->first();

                // Check if the field exists
                if (!$customField) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Custom Field not found',
                        'debug' => [
                            'requested_id' => $custom_field_id,
                            'available_ids' => CustomField::pluck('id')->toArray(),
                        ]
                    ], 404);
                }

                // Add `group_name`
                $customField->group_name = Groupname::where('id', $customField->group_id)->value('group_name');

                // Decode `model_fields` JSON data
                $customField->model_fields = json_decode($customField->model_fields, true) ?? [];

                // Extract model conditions from `model_fields`
                $conditionData = [];
                if (is_array($customField->model_fields)) {
                    foreach ($customField->model_fields as $modelField) {
                        if (isset($modelField['model'])) {
                            $conditionData[] = $this->getModelConditionData($modelField['model']);
                        }
                    }
                }
                $customField->model_condition = $conditionData;

                // Fetch options with timestamps
                $customFieldOptions = CustomFieldOption::where('custom_field_id', $customField->id)->get()->map(function ($option) {
                    return [
                        'label' => $option->name,
                        'value' => $option->value,
                        'created_at' => $option->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $option->updated_at->format('Y-m-d H:i:s'),
                    ];
                });

                return response()->json([
                    'status' => true,
                    'message' => 'Get Custom Field Successfully',
                    'data' => [
                        [
                            'id' => $customField->id,
                            'group_id' => $customField->group_id,
                            'field_label' => $customField->field_label,
                            'field_type' => $customField->field_type,
                            'post_type' => $customField->post_type,
                            'required' => $customField->required,
                            'field_name_slug' => $customField->field_name_slug,
                            'field_placeholder' => $customField->field_placeholder,
                            'created_at' => $customField->created_at->format('Y-m-d H:i:s'),
                            'updated_at' => $customField->updated_at->format('Y-m-d H:i:s'),
                            'group_name' => $customField->group_name,
                            'media_limit' => $customField->media_limit,
                            'media_size' => $customField->media_size,
                            'media_format' => $customField->media_format,
                            'model_fields' => $customField->model_fields,
                            'model_condition' => $customField->model_condition,
                            'options' => $customFieldOptions,
                            'checkbox_type' => $customField->checkbox_type,
                            'select_visible' => $customField->field_type === 'select',
                            'radio_visible' => $customField->field_type === 'radio',
                            'checkbox_visible' => $customField->field_type === 'checkbox',
                            'media_visible' => $customField->field_type === 'media',
                            'file_visible' => $customField->field_type === 'file',
                            'repeater_visible' => $customField->field_type === 'repeater',
                            'repeater' => $customField->repeaters->map(function ($repeater) {
                                return [
                                    'id' => $repeater->id,
                                    'fieldName' => $repeater->field_label,
                                    'field_name_slug' => $repeater->field_name_slug,
                                    'fieldType' => $repeater->field_type,
                                    'fieldPlaceholder' => $repeater->field_placeholder,
                                    'fieldMediaLimit' => $repeater->media_limit,
                                    'fieldMediaSize' => $repeater->media_size,
                                    'fieldMediaFormat' => $repeater->media_format,
                                ];
                            }),
                        ]
                    ]
                ]);
            }

            // Process multiple records
            $customFields->transform(function ($field) {
                return [
                    'id' => $field->id,
                    'group_id' => $field->group_id,
                    'field_label' => $field->field_label,
                    'field_type' => $field->field_type,
                    'post_type' => $field->post_type,
                    'created_at' => $field->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $field->updated_at->format('Y-m-d H:i:s'),
                    'group_name' => Groupname::where('id', $field->group_id)->value('group_name'),
                    'model_fields' => json_decode($field->model_fields, true) ?? [],
                    'options' => CustomFieldOption::where('custom_field_id', $field->id)->get()->map(function ($option) {
                        return [
                            'label' => $option->name,
                            'value' => $option->value,
                            'created_at' => $option->created_at->format('Y-m-d H:i:s'),
                            'updated_at' => $option->updated_at->format('Y-m-d H:i:s'),
                        ];
                    }),
                    'select_visible' => $field->field_type === 'select',
                    'radio_visible' => $field->field_type === 'radio',
                    'checkbox_visible' => $field->field_type === 'checkbox',
                    'media_visible' => $field->field_type === 'media',
                    'file_visible' => $field->field_type === 'file',
                    'repeater_visible' => $field->field_type === 'repeater',
                    'repeater_fields' => $field->repeaters->map(function ($repeater) {
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
                    }),
                ];
            });

            // Return response with pagination
            return response()->json([
                'status' => true,
                'message' => 'Get Custom Field Successfully',
                'data' => $customFields->items(),
                "pagination" => [
                    "current_page" => $customFields->currentPage(),
                    "last_page" => $customFields->lastPage(),
                    "per_page" => $customFields->perPage(),
                    "total" => $customFields->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching custom fields',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    public function updateCustomField(Request $request, $id)
    {
        return $request;
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'group_name' => 'required|string',
                'fields.*.field_label' => 'string|max:255',
                'fields.*.field_name' => [
                    //'required',
                    'string',
                    'max:255',
                    // Rule::unique('custom_fields', 'field_name')->where(function ($query) use ($request) {
                    //     return $query->whereIn('model', $request->input('fields.*.model'));
                    // }),
                ],
                'fields.*.field_placeholder' => 'nullable|string|max:255',
                'fields.*.field_type' => 'required|string|in:text,textarea,checkbox,radio,select,repeater,media,file',
                'fields.*.model' => 'required|string|max:255',
                'fields.*.condition' => 'required|array',
                'fields.*.condition.*' => 'required|max:255',
                'fields.*.required' => 'required|string|in:yes,no',
                'fields.*.post_type' => 'required|string|max:255',
                'fields.*.media_limit' => 'nullable|integer',
                'fields.*.media_size' => 'nullable|string',
                'fields.*.media_format' => 'nullable|array',
                'fields.*.options' => 'nullable|array',
                'fields.*.options.*.name' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
                'fields.*.options.*.value' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
                'fields.*.repeater' => 'nullable|array',
                'fields.*.repeater.*.fieldType' => 'required_if:fields.*.field_type,repeater|string|max:255',
                'fields.*.repeater.*.fieldName' => 'required_if:fields.*.field_type,repeater|string|max:255',
                'fields.*.repeater.*.fieldPlaceholder' => 'nullable|string|max:255',
                'fields.*.repeater.*.fieldMediaFormat' => 'nullable|max:255',
                'fields.*.repeater.*.fieldMediaLimit' => 'nullable|max:255',
                'fields.*.repeater.*.fieldMediaSize' => 'nullable|max:255',
                'fields.*.repeater.*.fieldOptions' => 'nullable|array',
                'fields.*.repeater.*.fieldOptions.*.name' => 'required_if:fields.*.repeater.*.fieldType,checkbox|string|max:255',
                'fields.*.repeater.*.fieldOptions.*.value' => 'required_if:fields.*.repeater.*.fieldType,checkbox|string|max:255',
            ]);

            // Begin transaction to ensure data integrity
            DB::beginTransaction();

            $CF = DB::table('custom_fields')->where('id', $id)->first();
            // Update the Groupname
            $groupData = DB::table('group_name')->where('id', $CF->group_id)->update([
                'group_name' => $validatedData['group_name'],
            ]);

            // Clear existing custom fields
            //$groupData->customFields()->delete();

            foreach ($validatedData['fields'] as $fieldData) {
                $mediaFormat = isset($fieldData['media_format']) ? implode(',', $fieldData['media_format']) : null;

                // Update or create non-repeater fields
                $field = CustomField::create([
                    'group_id' => $request->group_id ?? $request->group_name,
                    'field_label' => $fieldData['field_label'],

                    'field_placeholder' => $fieldData['field_placeholder'] ?? null,
                    'field_type' => $fieldData['field_type'],
                    'model' => $fieldData['model'],
                    'condition' => json_encode($fieldData['condition']),
                    'required' => $fieldData['required'],
                    'post_type' => $fieldData['post_type'],
                    'media_limit' => $fieldData['media_limit'] ?? null,
                    'media_size' => $fieldData['media_size'] ?? null,
                    'media_format' => $mediaFormat,
                ]);


                if (in_array($fieldData['field_type'], ['select', 'checkbox']) && isset($fieldData['options'])) {
                    foreach ($fieldData['options'] as $option) {
                        CustomFieldOption::create([
                            'custom_field_id' => $field->id,
                            'type' => $fieldData['field_type'],
                            'name' => $option['name'],
                            'value' => $option['value'],
                        ]);
                    }
                }

                if ($fieldData['field_type'] === 'repeater' && isset($fieldData['repeater'])) {
                    foreach ($fieldData['repeater'] as $repeaterItem) {
                        $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat']) ? implode(',', $repeaterItem['fieldMediaFormat']) : null;

                        $repeaterField = CustomFieldRepeater::create([
                            'group_id' => $groupData->id,
                            'custom_field_id' => $field->id,
                            'field_name' => $repeaterItem['fieldName'],
                            'field_type' => $repeaterItem['fieldType'],
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
            }

            // Commit transaction
            DB::commit();

            return response()->json(['message' => 'Custom fields updated successfully'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function getModelConditionName($model, $condition)
    {
        switch ($model) {
            case 'purpose':
                return Purpose::where('id', $condition)->first();
            case 'property':
                return Property::where('id', $condition)->first();
            case 'property_type':
                return PropertyType::where('id', $condition)->first();
            case 'property_status':
                return Status::where('id', $condition)->first();
            case 'amenities':
                return Amenity::where('id', $condition)->first();
            case 'amenities_categories':
                return AmenitiesCategory::where('id', $condition)->first();
            default:
                return null;
        }
    }




    // public function store(Request $request)
// {
//     // dd($request->all());  // This will dump the entire request data

    //     try {
//         // Validate the request data
//         $validatedData = $request->validate([
//             'group_name' => 'required|unique:group_name,group_name|string',
//             'fields.*.field_label' => 'required|string|max:255',
//             'fields.*.checkbox_type' => 'string',
//             'fields.*.field_name_slug' => 'required|string|max:255|unique:custom_fields,field_name_slug',
//             'fields.*.field_placeholder' => 'nullable|string|max:255',
//             'fields.*.field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file',
//             'fields.*.required' => 'required|string|in:yes,no',
//             'fields.*.post_type' => 'required|string|max:255',
//             'fields.*.media_limit' => 'nullable|integer',
//             'fields.*.media_size' => 'nullable|string',
//             'fields.*.media_format' => 'nullable|array',
//             'fields.*.options' => 'nullable|array',
//             'fields.*.modelFields' => 'nullable|array',  // Validate modelFields if it's provided
//             'fields.*.modelFields.*.model' => 'nullable|string',
//             'fields.*.modelFields.*.condition' => 'nullable|array',
//             'fields.*.options.*.label' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
//             'fields.*.options.*.value' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
//         ]);

    //         // Begin transaction to ensure data integrity
//         DB::beginTransaction();

    //         // Insert into Groupname table
//         $groupData = Groupname::create([
//             'group_name' => $validatedData['group_name'],
//         ]);

    //         $customFields = [];
//         $modelFields = []; // Initialize the modelFields array

    //         foreach ($validatedData['fields'] as $fieldData) {
//             // Process media_format array to store as comma-separated string
//             $mediaFormat = isset($fieldData['media_format']) ? implode(',', $fieldData['media_format']) : null;

    //             // Create model fields array
//             $modelFieldsData = [];

    //             if (isset($fieldData['modelFields']) && is_array($fieldData['modelFields'])) {
//                 foreach ($fieldData['modelFields'] as $modelField) {
//                     $modelFieldsData[] = [
//                         'model' => $modelField['model'],
//                         'condition' => $modelField['condition'],
//                     ];
//                 }
//             } else {
//                 // Optionally log or handle the case where modelFields is missing
//                 $modelFieldsData = []; // Default to empty if not provided
//             }

    //             // dd($modelFieldsData);  // Check what's inside



    //             // Insert the custom field into the custom_fields table, with model_fields as JSON
//             $field = CustomField::create([
//                 'group_id' => $groupData->id,
//                 'field_label' => $fieldData['field_label'],
//                 'field_name_slug' => $fieldData['field_name_slug'],
//                 'field_placeholder' => $fieldData['field_placeholder'] ?? null,
//                 'field_type' => $fieldData['field_type'],
//                 'required' => $fieldData['required'],
//                 'checkbox_type' => $fieldData['checkbox_type'] ?? null,
//                 'post_type' => $fieldData['post_type'],
//                 'media_limit' => $fieldData['media_limit'] ?? null,
//                 'media_size' => $fieldData['media_size'] ?? null,
//                 'media_format' => $mediaFormat,
//                 'model_fields' => json_encode($modelFieldsData), // Store model fields as JSON
//             ]);

    //             // Insert options for select and checkbox types if needed
//             if (in_array($fieldData['field_type'], ['select', 'checkbox', 'radio']) && isset($fieldData['options'])) {
//                 foreach ($fieldData['options'] as $option) {
//                     CustomFieldOption::create([
//                         'custom_field_id' => $field->id,
//                         'type' => $fieldData['field_type'],
//                         'name' => $option['label'],
//                         'value' => $option['value'],
//                     ]);
//                 }
//             }

    //             // Handle repeater field type separately
//             if ($fieldData['field_type'] === 'repeater' && isset($fieldData['repeater'])) {
//                 foreach ($fieldData['repeater'] as $repeaterItem) {
//                     $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat']) ? implode(',', $repeaterItem['fieldMediaFormat']) : null;

    //                     $repeaterField = CustomFieldRepeater::create([
//                         'group_id' => $groupData->id,
//                         'custom_field_id' => $field->id,
//                         'field_name' => $repeaterItem['fieldName'],
//                         'field_type' => $repeaterItem['fieldType'],
//                         'field_placeholder' => $repeaterItem['fieldPlaceholder'] ?? null,
//                         'media_format' => $fieldMediaFormat,
//                         'media_limit' => $repeaterItem['fieldMediaLimit'] ?? null,
//                         'media_size' => $repeaterItem['fieldMediaSize'] ?? null,
//                     ]);

    //                     // Insert options into custom_field_repeater_options if needed
//                     if (isset($repeaterItem['fieldOptions'])) {
//                         foreach ($repeaterItem['fieldOptions'] as $option) {
//                             CustomFieldRepeaterOption::create([
//                                 'custom_field_repeater_id' => $repeaterField->id,
//                                 'type' => $repeaterItem['fieldType'],
//                                 'name' => $option['name'],
//                                 'value' => $option['value'],
//                             ]);
//                         }
//                     }
//                 }
//             }

    //             $customFields[] = $field->toArray();
//         }

    //         // Commit transaction
//         DB::commit();

    //         // Return the newly created custom fields as JSON response
//         return response()->json([
//             'message' => 'Added successfully',
//             'fields' => $customFields,
//         ], 201);
//     } catch (\Illuminate\Validation\ValidationException $e) {
//         // Rollback the transaction on validation exception
//         DB::rollBack();
//         return response()->json(['error' => $e->errors()], 422);
//     } catch (\Exception $e) {
//         // Rollback the transaction on generic exception
//         DB::rollBack();
//         return response()->json(['error' => $e->getMessage()], 500);
//     }
// }

    public function store(Request $request)
    {
        try {
            // Debug the request to check if field_label exists
            // dd($request->all());

            // Validate the request data
            $validatedData = $request->validate([
                'group_name' => 'required|unique:group_name,group_name|string',
                'fields' => 'required|array', // Ensure 'fields' is an array
                'fields.*.field_label' => 'required|string|max:255',
                'fields.*.checkbox_type' => 'nullable|string',
                'fields.*.field_name_slug' => 'required|string|max:255|unique:custom_fields,field_name_slug',
                'fields.*.field_placeholder' => 'nullable|string|max:255',
                'fields.*.field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file',
                'fields.*.required' => 'required|string|in:yes,no',
                'fields.*.post_type' => 'required|string|max:255',
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

            // Begin transaction
            DB::beginTransaction();

            // Insert into Groupname table
            $groupData = Groupname::create([
                'group_name' => $validatedData['group_name'],
            ]);

            $customFields = [];

            foreach ($validatedData['fields'] as $fieldData) {
                // Ensure 'field_label' exists to avoid errors
                if (!isset($fieldData['field_label'])) {
                    return response()->json(['error' => 'Missing field_label in one or more fields'], 422);
                }

                // Convert media_format array to string
                $mediaFormat = isset($fieldData['media_format']) ? implode(',', $fieldData['media_format']) : null;

                // Process modelFields array
                $modelFieldsData = [];
                if (isset($fieldData['modelFields']) && is_array($fieldData['modelFields'])) {
                    foreach ($fieldData['modelFields'] as $modelField) {
                        $modelFieldsData[] = [
                            'model' => $modelField['model'],
                            'condition' => $modelField['condition'],
                        ];
                    }
                }

                // Insert into custom_fields table
                $field = CustomField::create([
                    'group_id' => $groupData->id,
                    'field_label' => $fieldData['field_label'],
                    'field_name_slug' => $fieldData['field_name_slug'],
                    'field_placeholder' => $fieldData['field_placeholder'] ?? null,
                    'field_type' => $fieldData['field_type'],
                    'required' => $fieldData['required'],
                    'checkbox_type' => $fieldData['checkbox_type'] ?? null,
                    'post_type' => $fieldData['post_type'],
                    'media_limit' => $fieldData['media_limit'] ?? null,
                    'media_size' => $fieldData['media_size'] ?? null,
                    'media_format' => $mediaFormat,
                    'model_fields' => json_encode($modelFieldsData),
                ]);

                // Insert options for select, checkbox, and radio fields
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

                // Handle repeater fields
                if ($fieldData['field_type'] === 'repeater' && isset($fieldData['repeater'])) {
                    foreach ($fieldData['repeater'] as $repeaterItem) {
                        if (!isset($repeaterItem['fieldName'])) {
                            return response()->json(['error' => 'Missing fieldName in one or more repeater fields'], 422);
                        }

                        // Convert media format array to string
                        // $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat']) ? implode(',', $repeaterItem['fieldMediaFormat']) : null;
                        $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat'])
                            ? (is_array($repeaterItem['fieldMediaFormat'])
                                ? implode(',', $repeaterItem['fieldMediaFormat'])
                                : $repeaterItem['fieldMediaFormat'])
                            : null;
                        // Insert into custom_field_repeaters table
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

                        // Insert repeater field options if available
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

            // Commit transaction
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



    public function update(Request $request)
    {
        // dd($request->all());
        try {
            $group_id = $request->group_id;

            // Validate the request data
            $validatedData = $request->validate([
                'group_id' => 'required|numeric',
                'group_name' => 'required|string',
                'fields.*.id' => 'nullable|numeric',
                'fields.*.field_label' => 'required|string|max:255',
                'fields.*.field_name_slug' => 'required|string|max:255|unique:custom_fields,field_name_slug,' . $request->group_id . ',group_id',
                'fields.*.field_placeholder' => 'nullable|string|max:255',
                'fields.*.field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file',
                'fields.*.required' => 'required|string|in:yes,no',
                'fields.*.post_type' => 'required|string|max:255',
                'fields.*.media_limit' => 'nullable|integer',
                'fields.*.media_size' => 'nullable|string',
                'fields.*.media_format' => 'nullable|array',
                'fields.*.options' => 'nullable|array',
                'fields.*.options.*.label' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
                'fields.*.options.*.value' => 'required_if:fields.*.field_type,in:select,checkbox|string|max:255',
                'fields.*.repeater' => 'nullable|array',
                'fields.*.model_fields' => 'nullable|array',
                'fields.*.model_fields.*.model' => 'nullable|string',
                'fields.*.model_fields.*.condition' => 'nullable|array',
            ]);

            // Begin transaction to ensure data integrity
            DB::beginTransaction();

            // Update group name
            $group = Groupname::findOrFail($group_id);
            $group->update([
                'group_name' => $validatedData['group_name'],
            ]);

            // Get all field IDs in the request
            $fieldIds = collect($validatedData['fields'])->pluck('id')->filter()->toArray();

            // Delete fields that are not present in the updated data
            CustomField::where('group_id', $group_id)->whereNotIn('id', $fieldIds)->delete();

            foreach ($validatedData['fields'] as $fieldData) {
                // dd($fieldData);

                $mediaFormat = isset($fieldData['media_format']) ? implode(',', $fieldData['media_format']) : null;

                // Process modelFields array
                $modelFieldsData = [];
                if (isset($fieldData['model_fields']) && is_array($fieldData['model_fields'])) {
                    foreach ($fieldData['model_fields'] as $modelField) {
                        $modelFieldsData[] = [
                            'model' => $modelField['model'],
                            'condition' => $modelField['condition'],
                        ];
                    }
                }

                // Update or create custom field
                $field = CustomField::updateOrCreate(
                    ['id' => $fieldData['id']],
                    [
                        'group_id' => $group_id,
                        'field_label' => $fieldData['field_label'],
                        'field_name_slug' => $fieldData['field_name_slug'],
                        'field_placeholder' => $fieldData['field_placeholder'] ?? null,
                        'field_type' => $fieldData['field_type'],
                        'required' => $fieldData['required'],
                        'post_type' => $fieldData['post_type'],
                        'media_limit' => $fieldData['media_limit'] ?? null,
                        'media_size' => $fieldData['media_size'] ?? null,
                        'media_format' => $mediaFormat,
                        'model_fields' => json_encode($modelFieldsData),
                    ]
                );

                // Handle options for select and checkbox fields
                if (isset($fieldData['options']) && is_array($fieldData['options'])) {
                    $existingOptions = $field->options ? $field->options->pluck('id')->toArray() : [];
                    $newOptions = [];

                    foreach ($fieldData['options'] as $optionData) {
                        if (isset($optionData['id'])) {
                            CustomFieldOption::where('id', $optionData['id'])->update([
                                'name' => $optionData['label'],
                                'value' => $optionData['value'],
                            ]);
                            $newOptions[] = $optionData['id'];
                        } else {
                            $newOption = CustomFieldOption::create([
                                'custom_field_id' => $field->id,
                                'type' => $fieldData['field_type'],
                                'name' => $optionData['label'],
                                'value' => $optionData['value'],
                            ]);
                            $newOptions[] = $newOption->id;
                        }
                    }

                    // Delete options that were removed
                    CustomFieldOption::where('custom_field_id', $field->id)->whereNotIn('id', $newOptions)->delete();
                }

                // Handle repeater fields
                if ($fieldData['field_type'] === 'repeater' && isset($fieldData['repeater'])) {

                    $repeaterIds = [];
                    foreach ($fieldData['repeater'] as $repeaterItem) {
                        // dd(gettype($repeaterItem['fieldMediaFormat']), $repeaterItem['fieldMediaFormat']);

                        // $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat']) ? implode(',', $repeaterItem['fieldMediaFormat']) : null;
                        $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat'])
                            ? (is_array($repeaterItem['fieldMediaFormat'])
                                ? implode(',', $repeaterItem['fieldMediaFormat'])
                                : $repeaterItem['fieldMediaFormat'])
                            : null;


                        // dd($fieldMediaFormat);



                        // Update or create repeater fields
                        $repeaterField = CustomFieldRepeater::updateOrCreate(
                            ['field_name_slug' => Str::slug($repeaterItem['field_name_slug'])], // ✅ Use slug for uniqueness
                            [
                                'group_id' => $group_id,
                                'custom_field_id' => $field->id,
                                'field_label' => $repeaterItem['fieldName'], // ✅ Use slug instead of name
                                'field_type' => $repeaterItem['fieldType'],
                                'field_name_slug' => $repeaterItem['field_name_slug'],
                                'field_placeholder' => $repeaterItem['fieldPlaceholder'] ?? null,
                                'media_format' => $fieldMediaFormat,
                                'media_limit' => $repeaterItem['fieldMediaLimit'] ?? null,
                                'media_size' => $repeaterItem['fieldMediaSize'] ?? null,
                            ]
                        );

                        $repeaterIds[] = $repeaterField->id;

                        // Handle options inside repeater fields
                        if (isset($repeaterItem['fieldOptions'])) {
                            $existingRepeaterOptions = $repeaterField->options ? $repeaterField->options->pluck('id')->toArray() : [];
                            $newRepeaterOptions = [];

                            foreach ($repeaterItem['fieldOptions'] as $option) {
                                if (isset($option['id'])) {
                                    CustomFieldRepeaterOption::where('id', $option['id'])->update([
                                        'name' => $option['name'],
                                        'value' => $option['value'],
                                    ]);
                                    $newRepeaterOptions[] = $option['id'];
                                } else {
                                    $newOption = CustomFieldRepeaterOption::create([
                                        'custom_field_repeater_id' => $repeaterField->id,
                                        'type' => $repeaterItem['fieldType'],
                                        'name' => $option['name'],
                                        'value' => $option['value'],
                                    ]);
                                    $newRepeaterOptions[] = $newOption->id;
                                }
                            }

                            // Delete removed repeater options
                            CustomFieldRepeaterOption::where('custom_field_repeater_id', $repeaterField->id)->whereNotIn('id', $newRepeaterOptions)->delete();
                        }
                    }

                    // Delete removed repeater fields
                    CustomFieldRepeater::where('custom_field_id', $field->id)->whereNotIn('id', $repeaterIds)->delete();
                }
            }

            // Commit transaction
            DB::commit();

            return response()->json(['message' => 'Custom fields updated successfully'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollback();
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'An error occurred while updating custom fields', 'error' => $e->getMessage()], 500);
        }
    }



    // this is for delete custom field
    public function delete(Request $request)
    {

        try {
            // Validate the request data
            $request->validate([
                'group_id' => 'required|integer'
            ]);

            $groupId = $request->group_id;

            $group = GroupName::where('id', $groupId)->first();

            if (!$group) {
                return response()->json(['error' => 'Invalid Group Id'], 401);
            }

            // Retrieve CustomField IDs associated with the given group ID
            $customFieldIds = CustomField::where('group_id', $groupId)->pluck('id');

            if ($customFieldIds->isEmpty()) {
                return response()->json(['error' => 'No CustomFields found for the given Group ID'], 404);
            }

            // Retrieve CustomFieldRepeater IDs associated with the CustomFields
            $customFieldRepeaterIds = CustomFieldRepeater::whereIn('custom_field_id', $customFieldIds)->pluck('id');

            // Delete CustomFieldRepeaterOptions associated with the CustomFieldRepeaters
            CustomFieldRepeaterOption::whereIn('custom_field_repeater_id', $customFieldRepeaterIds)->delete();

            // Delete CustomFieldRepeaters associated with the CustomFields
            CustomFieldRepeater::whereIn('custom_field_id', $customFieldIds)->delete();

            // Delete CustomFieldOptions associated with the CustomFields
            CustomFieldOption::whereIn('custom_field_id', $customFieldIds)->delete();

            // Delete the CustomFields associated with the given group ID
            CustomField::whereIn('id', $customFieldIds)->delete();

            // Delete the GroupName associated with the given group ID
            GroupName::where('id', $groupId)->delete();

            // Return a success message
            return response()->json(['message' => 'CustomFields and related data deleted successfully'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return validation error response
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Return error response if the CustomFields or GroupName were not found
            return response()->json(['error' => 'CustomFields or GroupName not found'], 404);
        } catch (\Exception $e) {
            // Return generic error response
            \Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }



    // this  id for show of fields
    public function show(Request $request)
    {
        try {
            // Fetch the custom fields along with their groupname, options, and repeater fields with values
            $data = CustomField::where('group_id', $request->group_id)
                ->with(['groupname', 'options', 'repeaterFields.repeaterOptions'])
                ->get();

            if (count($data) < 1) {
                return response()->json($data, 200);
            }

            // Fetch the group data
            $groupData = Groupname::where('id', $request->group_id)->first();

            // Prepare the response data
            $customFields = [];
            $customFields['group_data'] = [
                'group_name' => $groupData->group_name,
                'group_id' => $groupData->id
            ];

            $customFields['data'] = [];

            foreach ($data as $field) {
                $modelNameNew = ucwords(str_replace('_', ' ', $field->model));

                // Fetch the model condition data
                $conditionData = $this->getModelConditionData($field->model);

                // Decode the condition string to convert it to an array
                $conditionArray = json_decode($field->condition);

                // Transform repeater fields to camelCase and add prefix to specific columns
                $repeaterFields = [];
                foreach ($field->repeaterFields as $repeaterField) {
                    $repeaterFieldArray = $repeaterField->toArray();
                    $camelCasedRepeaterField = [];
                    foreach ($repeaterFieldArray as $key => $value) {
                        $newKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
                        if (in_array($key, ['media_format', 'media_limit', 'media_size'])) {
                            $newKey = 'field' . ucfirst($newKey);
                        }
                        $camelCasedRepeaterField[$newKey] = $value;
                    }

                    // Transform repeater options to camelCase
                    $repeaterOptions = [];
                    foreach ($repeaterField->repeaterOptions as $repeaterOption) {
                        $repeaterOptionArray = $repeaterOption->toArray();
                        $camelCasedRepeaterOption = array_combine(
                            array_map(function ($key) {
                                return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
                            }, array_keys($repeaterOptionArray)),
                            $repeaterOptionArray
                        );
                        $repeaterOptions[] = $camelCasedRepeaterOption;
                    }
                    $camelCasedRepeaterField['repeaterOptions'] = $repeaterOptions;
                    $repeaterFields[] = $camelCasedRepeaterField;
                }

                // Include the new columns in the response
                $customFields['data'][] = [
                    'id' => $field->id,
                    'post_type' => $field->post_type,
                    'field_label' => $field->field_label,
                    'field_name' => $field->field_name,
                    'field_placeholder' => $field->field_placeholder,
                    // 'field_name_description' => $field->field_name_description,
                    'checkbox_visible' => $field->field_type == 'checkbox' ? true : false,
                    'media_visible' => $field->field_type == 'media' ? true : false,
                    'file_visible' => $field->field_type == 'file' ? true : false,
                    'select_visible' => $field->field_type == 'select' ? true : false,
                    'radio_visible' => $field->field_type == 'radio' ? true : false,
                    'repeater_visible' => $field->field_type == 'repeater' ? true : false,
                    'field_type' => $field->field_type,
                    'options' => $field->options,
                    'repeater_fields' => $repeaterFields,
                    'model' => $field->model,
                    'model_' => $modelNameNew,
                    'model_condition' => $conditionData,
                    'condition' => $conditionArray,
                    'required' => $field->required,
                    'media_limit' => $field->media_limit,
                    'media_size' => $field->media_size,
                    'media_format' => explode(',', $field->media_format),
                    'checkbox_type' => $request->checkbox_type ?? 'manually' ?? 'import_from_aminities',
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at
                ];
            }

            // Return the array of custom field data as JSON response
            return response()->json($customFields, 200);
        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }

    private function getModelConditionData($model)
    {
        switch ($model) {
            case 'purpose':
                return Purpose::all();
            case 'property':
                return Property::all();
            case 'property_type':
                return PropertyType::all();
            case 'property_status':
                return Status::all();
            case 'amenities':
                return Amenity::all();
            case 'amenities_categories':
                return AmenitiesCategory::all();
            default:
                return [];
        }
    }






    // this is for listing of custom fields by type
    public function customFieldListingByType(Request $request)
    {
        try {

            if (isset($request->post_type) && $request->post_type == 'project') {
                $post_type = 'project';
            } elseif (isset($request->post_type) && $request->post_type == 'developer_list') {
                $post_type = 'developer_list';
            } else {
                $post_type = 'property_list';
            }

            $groupIdArr = [];
            // Retrieve all CustomField records with their associated Groupname
            $data = CustomField::with('groupname', 'options')->where('post_type', $post_type)->get();

            $customFields = [];


            foreach ($data as $field) {
                array_push($groupIdArr, $field->group_id);

                switch ($field->model) {
                    case 'purpose':
                        $modelConditionName = Purpose::whereIn('id', json_decode($field->condition))->get();
                        break;
                    case 'property':
                        $modelConditionName = Property::whereIn('id', json_decode($field->condition))->get();
                        break;
                    case 'property_type':
                        $modelConditionName = PropertyType::whereIn('id', json_decode($field->condition))->get();
                        break;
                    case 'property_status':
                        $modelConditionName = Status::whereIn('id', json_decode($field->condition))->get();
                        break;
                    case 'amenities':
                        $modelConditionName = Amenity::whereIn('id', json_decode($field->condition))->get();
                        break;
                    case 'amenities_categories':
                        $modelConditionName = AmenitiesCategory::whereIn('id', json_decode($field->condition))->get();
                        break;
                    default:
                        $modelConditionName = null;
                        break;
                }


                $modelNameNew = ucwords(str_replace('_', ' ', $field->model));


                $customFields[] = [
                    'id' => $field->id,
                    'post_type' => $field->post_type,
                    'field_label' => $field->field_label,
                    'field_name' => $field->field_name,
                    'field_placeholder' => $field->field_placeholder,
                    //'field_name_description' => $field->field_name_description,
                    'field_type' => $field->field_type,
                    'options' => $field->options,
                    'model' => $field->model,
                    'model_show' => $modelNameNew,
                    'condition' => $modelConditionName,
                    'group_data' => $field->groupname,
                    'required' => $field->required,
                    'media_limit' => $field->media_limit,
                    'media_size' => $field->media_size,
                    'media_format' => explode(',', $field->media_format),
                    'post_type' => $field->post_type,
                    'checkbox_type' => $field->checkbox_type,
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at
                ];
            }


            // Return the array of custom field data as JSON response
            return response()->json($customFields, 200);
        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }


    // this  id for listing of models
    public function modelListing()
    {
        try {
            // Retrieve all CustomField records with their associated Groupname
            $modeldata = ModelType::where('status', 1)->get();

            $data = [];

            if (count($modeldata) > 0) {
                foreach ($modeldata as $model) {
                    // Modify the name field
                    $name = ucwords(str_replace('_', ' ', $model->name));

                    $data[] = [
                        'id' => $model->id,
                        'show' => $name,
                        'name' => $model->name,
                        'slug' => $model->slug,
                        'created_at' => date('d-m-Y', strtotime($model->created_at)),
                        'updated_at' => date('d-m-Y', strtotime($model->updated_at)),
                        'status' => $model->status,
                    ];
                }
                $arr['data'] = $data;
                return response()->json($arr, 200);
            } else {
                return response()->json(['success' => 'No data found'], 200);
            }

        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }


    // this  id for listing of condition by model name
    public function conditionListing(Request $request)
    {
        try {

            $model = $request->model;

            $conditionData = null;

            if ($model == 'purpose') {
                $conditionData = Purpose::get();
            } else if ($model == 'property') {
                $conditionData = Property::get();
            } else if ($model == 'property_type') {
                $conditionData = PropertyType::get();
            } else if ($model == 'property_status') {
                $conditionData = Status::get();
            } else if ($model == 'amenities') {
                $conditionData = Amenity::get();
            } else if ($model == 'amenities_categories') {
                $conditionData = AmenitiesCategory::get();
            }

            $data = [];

            if (count($conditionData) > 0) {
                foreach ($conditionData as $condition) {
                    $data[] = [
                        'id' => $condition->id,
                        'name' => $condition->name,
                    ];
                }
                $arr['data'] = $data;
                return response()->json($arr, 200);
            } else {
                return response()->json(['success' => 'No data found'], 200);
            }

        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }

    //Un-used Function
    // this  id for listing of locations
    //     public function locationListing()
    // {
    //     try {
    //     // Retrieve all CustomField records with their associated Groupname
    //     $locationdata = Location::get();

    //     $data = [];

    //     if(count($locationdata) > 0){
    //     foreach ($locationdata as $location) {
    //     $data[] = [
    //         'id' => $location->id,
    //         'name' => $location->name,
    //         'slug' => $location->slug
    //         ];
    //     }
    //     $arr['data'] = $data;
    //     return response()->json($arr, 200);
    //     }else{
    //     return response()->json(['success' => 'No data found'], 200);
    //     }

    //     } catch (\Exception $e) {
    //     // Log and return generic error response
    //     Log::error('Error: ' . $e->getMessage());
    //     return response()->json(['error' => 'Something went wrong.' .$e->getMessage()], 500);
    //     }
    // }


    // this  id for listing of custom fields by model name
    public function customFieldListing(Request $request)
    {
        try {

            $model = $request->model;

            $cusData = CustomField::where('model', $model)->with('groupname', 'options')->where('post_type', 'property_list')->get();

            $data = [];

            if (count($cusData) > 0) {
                foreach ($cusData as $row) {

                    if ($row->model == 'purpose') {
                        $modelConditionName = Purpose::where('id', $row->condition)->first();
                    } else if ($row->model == 'property') {
                        $modelConditionName = Property::where('id', $row->condition)->first();
                    } else if ($row->model == 'property_type') {
                        $modelConditionName = PropertyType::where('id', $row->condition)->first();
                    } else if ($row->model == 'property_status') {
                        $modelConditionName = Status::where('id', $row->condition)->first();
                    } else if ($row->model == 'amenities') {
                        $modelConditionName = Amenity::where('id', $row->condition)->first();
                    } else if ($row->model == 'amenities_categories') {
                        $modelConditionName = AmenitiesCategory::where('id', $row->condition)->first();
                    }

                    $data[] = [
                        'id' => $row->id,
                        'group_data' => $row->groupname,
                        'field_label' => $row->field_label,
                        'field_name' => $row->field_name,
                        'field_placeholder' => $row->field_placeholder,
                        // 'field_name_description' => $row->field_name_description,
                        'field_type' => $row->field_type,
                        'options' => $row->options,
                        'model' => $row->model,
                        'condition' => $modelConditionName,
                    ];
                }
                $arr['data'] = $data;
                return response()->json($arr, 200);
            } else {
                return response()->json(['success' => 'No data found'], 200);
            }

        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }



    // this  id for listing of custom fields by model & condition id name
    // public function customFieldListingByModelConditionId(Request $request)
    // {
    //     try {
    //         // Validate request
    //         $validatedData = $request->validate([
    //             'model_fields' => 'required',  // 'model_fields' is expected to be an array
    //             'post_type' => 'nullable|string|in:project,developer_list,property_list',
    //         ]);

    //         // Set post_type (default: 'property_list')
    //         $postType = $request->post_type ?? 'property_list';

    //         // Retrieve custom fields that match the post_type
    //         $customFields = CustomField::where('post_type', $postType)
    //             ->with(['groupname', 'options', 'repeaterFields.repeaterFieldsOptions'])
    //             ->get()
    //             ->map(function ($customField) use ($validatedData) {
    //                 // Decode model_fields JSON safely
    //                 $modelFields = json_decode($customField->model_fields, true);

    //                 if (!is_array($modelFields)) {
    //                     return null; // Skip invalid JSON
    //                 }

    //                 // Filter to keep only matching model and condition_id in model_fields
    //                // Filter to keep only matching model and condition in model_fields
    //                 $filteredModelFields = array_values(array_filter($modelFields, function ($entry) use ($validatedData) {
    //                     foreach ($validatedData['model_fields'] as $modelField) {
    //                         if (isset($entry['model']) && $entry['model'] === $modelField['model']) {
    //                             return !empty($entry['condition']) && count(array_intersect($entry['condition'], $modelField['condition'])) > 0;
    //                         }
    //                     }
    //                     return false;
    //                 }));


    //                 // Skip fields where no matching condition exists
    //                 if (empty($filteredModelFields)) {
    //                     return null;
    //                 }

    //                 // Return only relevant fields
    //                 return [
    //                     'id' => $customField->id,
    //                     'group_data' => $customField->groupname ?? null,
    //                     'field_label' => $customField->field_label,
    //                     'field_name_slug' => $customField->field_name_slug,
    //                     'field_placeholder' => $customField->field_placeholder,
    //                     'field_type' => $customField->field_type,
    //                     'post_type' => $customField->post_type,
    //                     'media_limit' => $customField->media_limit,
    //                     'media_size' => $customField->media_size,
    //                     'media_format' => $customField->media_format,
    //                     'checkbox_type' => $customField->checkbox_type,
    //                     'required' => $customField->required,
    //                     'options' => $customField->options ?? null,
    //                     'model_fields' => $filteredModelFields, // Only return the filtered model_fields
    //                     'repeater_fields' => $customField->repeaterFields ?? [],
    //                 ];
    //             })
    //             ->filter(); // Remove null values (fields that didn't match)

    //         return response()->json(['data' => $customFields->values()], 200);
    //     } catch (\Exception $e) {
    //         // Log error for debugging
    //         Log::error('Error in customFieldListingByModelConditionId: ' . $e->getMessage());
    //         return response()->json(['error' => 'Something went wrong.', 'details' => $e->getMessage()], 500);
    //     }
    // }

    public function customFieldListingByModelConditionId(Request $request)
    {
        try {
            // Validate request
            $validatedData = $request->validate([
                'model_fields' => 'required',  // 'model_fields' is expected to be an array
                'post_type' => 'nullable|string|in:project,developer_list,property_list',
            ]);

            // Set post_type (default: 'property_list')
            $postType = $request->post_type ?? 'property_list';

            // Retrieve custom fields that match the post_type
            $customFields = CustomField::where('post_type', $postType)
                ->with(['groupname', 'options', 'repeaterFields.repeaterFieldsOptions'])
                ->get()
                ->map(function ($customField) use ($validatedData) {
                    // Decode model_fields JSON safely
                    $modelFields = json_decode($customField->model_fields, true);

                    if (!is_array($modelFields)) {
                        return null; // Skip invalid JSON
                    }

                    // Filter to keep only matching model and condition_id in model_fields
                    $filteredModelFields = array_values(array_filter($modelFields, function ($entry) use ($validatedData) {
                        foreach ($validatedData['model_fields'] as $modelField) {
                            if (isset($entry['model']) && $entry['model'] === $modelField['model']) {
                                return !empty($entry['condition']) && count(array_intersect($entry['condition'], $modelField['condition'])) > 0;
                            }
                        }
                        return false;
                    }));

                    // Skip fields where no matching condition exists
                    if (empty($filteredModelFields)) {
                        return null;
                    }

                    // Return only relevant fields, filtering out null values
                    return collect([
                        'id' => $customField->id,
                        'group_data' => $customField->groupname,
                        'field_label' => $customField->field_label,
                        'field_name_slug' => $customField->field_name_slug,
                        'field_placeholder' => $customField->field_placeholder,
                        'field_type' => $customField->field_type,
                        'post_type' => $customField->post_type,
                        'media_limit' => $customField->media_limit,
                        'media_size' => $customField->media_size,
                        'media_format' => $customField->media_format,
                        'checkbox_type' => $customField->checkbox_type,
                        'required' => $customField->required,
                        'options' => $customField->options,
                        'model_fields' => $filteredModelFields, // Only return the filtered model_fields
                        'repeater_fields' => $customField->repeaterFields,
                    ])->filter(function ($value) {
                        return !is_null($value) && $value !== '';
                    });
                })
                ->filter(); // Remove null values (fields that didn't match)

            return response()->json(['data' => $customFields->values()], 200);
        } catch (\Exception $e) {
            // Log error for debugging
            Log::error('Error in customFieldListingByModelConditionId: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.', 'details' => $e->getMessage()], 500);
        }
    }

    private function fetchModelConditionName($model, $conditionId)
    {
        // Fetch condition name based on model and condition_id
        switch ($model) {
            case 'purpose':
                return Purpose::where('id', $conditionId)->first();
            case 'property':
                return Property::where('id', $conditionId)->first();
            case 'property_type':
                return PropertyType::where('id', $conditionId)->first();
            case 'property_status':
                return Status::where('id', $conditionId)->first();
            case 'amenities':
                return Amenity::where('id', $conditionId)->first();
            case 'amenities_categories':
                return AmenitiesCategory::where('id', $conditionId)->first();
            default:
                return null;
        }
    }


    // this  id for listing of property type by property id.
    public function propertyTypeListingByPropertyId(Request $request)
    {
        try {

            $conditionData = PropertyType::where('property_id', $request->property_id)->get();


            $data = [];

            if (count($conditionData) > 0) {
                foreach ($conditionData as $condition) {
                    $data[] = [
                        'id' => $condition->id,
                        'name' => $condition->name,
                    ];
                }
                $arr['data'] = $data;
                return response()->json($arr, 200);
            } else {
                return response()->json(['success' => 'No data found'], 200);
            }

        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }


    // this  id for listing of property type by property id.
    public function propertyStatusListingByPropertyType(Request $request)
    {
        try {

            $conditionData = Status::where('property_type_id', $request->property_type_id)->get();


            $data = [];

            if (count($conditionData) > 0) {
                foreach ($conditionData as $condition) {
                    $data[] = [
                        'id' => $condition->id,
                        'name' => $condition->name,
                    ];
                }
                $arr['data'] = $data;
                return response()->json($arr, 200);
            } else {
                return response()->json(['success' => 'No data found'], 200);
            }

        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }



    // this  id for listing of models
    public function customFieldUniqueCode(Request $request)
    {
        try {
            // Check if 'name' exists in the request and ensure its uniqueness
            $name = $request->input('name');

            // Check if name already exists in the CustomFieldUniqueCode table
            $existingField = CustomFieldUniqueCode::where('slug', $name)->first();

            if ($existingField) {
                return response()->json(['error' => 'The name is already taken.'], 422);
            }

            if (isset($request->post_type) && $request->post_type == 'project') {
                $post_type = 'project';
            } elseif (isset($request->post_type) && $request->post_type == 'developer_list') {
                $post_type = 'developer_list';
            } else {
                $post_type = 'property_list';
            }

            // Retrieve all CustomField records with their associated Groupname
            $Codedata = CustomFieldUniqueCode::where('status', 1)->get();

            $data = [];

            if (count($Codedata) > 0) {
                foreach ($Codedata as $model) {
                    // Modify the name field
                    $name = ucwords(str_replace('_', ' ', $model->name));

                    $data[] = [
                        'id' => $model->id,
                        'show' => $name,
                        'name' => $model->slug,
                        'type' => $model->type,
                    ];
                }
                $arr['data'] = $data;
                return response()->json($arr, 200);
            } else {
                return response()->json(['success' => 'No data found'], 200);
            }

        } catch (\Exception $e) {
            // Log and return generic error response
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }


    // this is for listing of amenities
    public function GetAmenitiesData()
    {
        try {
            $amenitiesCategories = AmenitiesCategory::with('mediaIcon', 'amenities')->get();

            // Modify the response structure to include the appropriate media_icon for each amenity
            $amenitiesCategories->transform(function ($category) {
                $category->amenities->transform(function ($amenity) {
                    $amenityMediaIcon = Media::find($amenity->media_id); // Fetch the appropriate media_icon
                    $amenity->media_icon = $amenityMediaIcon;
                    return $amenity;
                });
                return $category;
            });

            return response()->json($amenitiesCategories);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function searchAndFilter(Request $request)
    {
        try {
            // Extract input parameters
            $dropdownValue = $request->input('dropdown_value'); // This will be the group_name
            $searchQuery = $request->input('search');
            $modelValue = $request->input('model_value'); // Value for the second dropdown (model)
            $field = $request->input('field');
            $condition = $request->input('condition');
            $sortField = $request->input('sort_field'); // Field to sort by
            $sortOrder = $request->input('sort_order'); // Sort order: 'asc' or 'desc'
            $perPage = $request->input('per_page', 10);
            $fieldType = $request->input('field_type');
            // Start the query
            $query = CustomField::query();

            // Retrieve the group_id based on the group_name (dropdownValue)
            $groupId = DB::table('group_name')->where('group_name', $dropdownValue)->value('id');

            // Filter by group_id if dropdownValue exists
            if ($dropdownValue && $groupId) {
                $query->where('group_id', $groupId);
            }

            // If model_value is provided, filter records by the model
            if ($modelValue) {
                $query->where('model', $modelValue);
            }

            if ($fieldType) {
                $query->where('field_type', $fieldType);
            }

            // Apply search filtering
            if ($searchQuery) {
                // Join the group_name table to access group_name for searching
                $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
                    ->where(function ($q) use ($searchQuery) {
                        $q->where('field_label', 'LIKE', "%{$searchQuery}%")
                            ->orWhere('field_name', 'LIKE', "%{$searchQuery}%")
                            ->orWhere('model', 'LIKE', "%{$searchQuery}%")
                            ->orWhere('field_type', 'LIKE', "%{$searchQuery}%")
                            ->orWhere('group_name.group_name', 'LIKE', "%{$searchQuery}%"); // Search in group_name
                    })
                    ->select('custom_fields.*', 'group_name.group_name'); // Ensure selected fields are correct
            }


            // Apply specific field filtering
            if ($field && $condition) {
                if ($field === 'condition') {
                    $query->whereJsonContains($field, json_decode($condition));
                } else {
                    $query->where($field, $condition);
                }
            }
            // Apply sorting by specific fields
            if ($sortField && in_array($sortField, ['model', 'field_type', 'group_name', 'field_label'])) {
                if ($sortField === 'group_name') {
                    // For group_name, join with the group_name table to sort
                    $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
                        ->orderBy('group_name.group_name', $sortOrder)
                        ->select('custom_fields.*', 'group_name.group_name');
                } else {
                    $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
                        ->orderBy($sortField, $sortOrder)
                        ->select('custom_fields.*', 'group_name.group_name');
                }
            }

            // Fetch the filtered results
            $results = $query->paginate($perPage);

            // Return the results as a JSON response
            return response()->json([
                'message' => 'Filtered results retrieved successfully',
                'data' => $results->items(),
                'pagination' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                ],
            ], 200);
        } catch (\Exception $e) {
            // Return error response in case of failure
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteCustomField(Request $request)
    {
        try {
            $field = CustomField::where('id', $request->id)->first();

            if (!$field) {
                return response()->json([
                    'status' => false,
                    'message' => 'Custom field not found.',
                ], 404);
            }
            $field->update([
                'deleted_at' => Carbon::now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Custom field deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function slugUniquesCheck(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'field_name_slug' => 'required|unique:custom_fields,field_name_slug',
        ]);

        // If validation fails, return error response
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'error' => 'Field key already exists'
            ], 400);
        }

        // Check if the slug exists in the database
        $field = CustomField::where('field_name_slug', $request->field_name_slug)->first();

        if ($field) {
            return response()->json([
                'status' => false,
                'error' => 'Field name slug must be unique.'
            ], 400);
        }

        // Return success if validation passes and no duplicates are found
        return response()->json([
            'status' => true,
            'message' => 'Slug is unique and valid.'
        ], 200);
    }

    public function getAllModelConditionRecords()
    {
        try {
            // Define the model mappings
            $models = [
                'Purposes' => Property::all(),
                'Properties' => Property::all(),
                'Property Types' => PropertyType::all(),
                'Property Statuses' => Status::all(),
                //'Amenities' => Amenity::all(),
                //'Amenities Categories' => AmenitiesCategory::all(),
            ];

            // Build the response data dynamically
            $data = [];
            foreach ($models as $name => $dataset) {
                $options = $dataset->map(function ($item) {
                    return [
                        'value' => $item->id,
                        'label' => $item->name, // Assuming the model has `id` and `name` fields
                    ];
                });

                $data[] = [
                    'label' => $name,
                    'options' => $options,
                ];
            }

            // Return the formatted data as a JSON response
            return response()->json([
                'message' => 'All data retrieved successfully.',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            // Handle errors and return a response
            return response()->json([
                'message' => 'An error occurred while fetching data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
