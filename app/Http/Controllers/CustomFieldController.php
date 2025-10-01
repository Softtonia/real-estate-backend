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
            $customFields = CustomField::with('repeaters', 'templateValue') // Load repeaters for each field
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
                            'template_id' => $customField->template_id,
                            'template_value' => $customField->templateValue,
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
                    'template_id' => $field->template_id,
                    'template_value' => $field->templateValue,
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
                // 'group_name' => 'required|unique:group_name,group_name|string',
                'group_id' => 'nullable|integer|exists:group_name,id',
                'group_name' => 'nullable|string|max:255',

                'fields' => 'required|array', // Ensure 'fields' is an array
                'fields.*.field_label' => 'required|string|max:255',
                'fields.*.checkbox_type' => 'nullable|string',
                'fields.*.field_name_slug' => 'required|string|max:255|unique:custom_fields,field_name_slug',
                'fields.*.field_placeholder' => 'nullable|string|max:255',
                'fields.*.field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file,number',
                'fields.*.required' => 'required|string|in:yes,no',
                'fields.*.post_type' => 'required|string|max:255',
                'fields.*.template_id' => 'nullable|integer|exists:custom_field_unique_codes,id', // Ensure template_value_id exists in the database
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

            if (empty($validatedData['group_id']) && empty($validatedData['group_name'])) {
                return response()->json(['error' => 'Either group_id or group_name must be provided'], 422);
            }


            if (!empty($validatedData['group_id'])) {
                // Use existing group
                $groupData = Groupname::find($validatedData['group_id']);
            } else {
                // Check if group_name is unique
                $existingGroup = Groupname::where('group_name', $validatedData['group_name'])->first();
                if ($existingGroup) {
                    return response()->json(['error' => 'Group name already exists.'], 422);
                }

                // Create new group
                $groupData = Groupname::create([
                    'group_name' => $validatedData['group_name'],
                ]);
            }



            // Insert into Groupname table
            // $groupData = Groupname::create([
            //     'group_name' => $validatedData['group_name'],
            // ]);

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
                    'template_id' => $fieldData['template_id'] ?? null, // ADD THIS
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



    public function updateCustomFieldByGroupId(Request $request)
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
                'fields.*.field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file,number',
                'fields.*.required' => 'required|string|in:yes,no',
                'fields.*.post_type' => 'required|string|max:255',
                'fields.*.template_id' => 'nullable|integer|exists:custom_field_unique_codes,id',
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
                        'template_id' => $fieldData['template_id'] ?? null, // ADD THIS
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
    // public function show(Request $request)
    // {
    //     try {
    //         // Fetch the custom fields along with their groupname, options, and repeater fields with values
    //         $data = CustomField::where('group_id', $request->group_id)
    //             ->with(['groupname', 'options', 'repeaterFields.repeaterOptions'])
    //             ->get();

    //         if (count($data) < 1) {
    //             return response()->json($data, 200);
    //         }

    //         // Fetch the group data
    //         $groupData = Groupname::where('id', $request->group_id)->first();

    //         // Prepare the response data
    //         $customFields = [];
    //         $customFields['group_data'] = [
    //             'group_name' => $groupData->group_name,
    //             'group_id' => $groupData->id
    //         ];

    //         $customFields['data'] = [];

    //         foreach ($data as $field) {
    //             $modelNameNew = ucwords(str_replace('_', ' ', $field->model));

    //             // Fetch the model condition data
    //             $conditionData = $this->getModelConditionData($field->model);

    //             // Decode the condition string to convert it to an array
    //             $conditionArray = json_decode($field->condition);

    //             // Transform repeater fields to camelCase and add prefix to specific columns
    //             $repeaterFields = [];
    //             foreach ($field->repeaterFields as $repeaterField) {
    //                 $repeaterFieldArray = $repeaterField->toArray();
    //                 $camelCasedRepeaterField = [];
    //                 foreach ($repeaterFieldArray as $key => $value) {
    //                     $newKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    //                     if (in_array($key, ['media_format', 'media_limit', 'media_size'])) {
    //                         $newKey = 'field' . ucfirst($newKey);
    //                     }
    //                     $camelCasedRepeaterField[$newKey] = $value;
    //                 }

    //                 // Transform repeater options to camelCase
    //                 $repeaterOptions = [];
    //                 foreach ($repeaterField->repeaterOptions as $repeaterOption) {
    //                     $repeaterOptionArray = $repeaterOption->toArray();
    //                     $camelCasedRepeaterOption = array_combine(
    //                         array_map(function ($key) {
    //                             return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    //                         }, array_keys($repeaterOptionArray)),
    //                         $repeaterOptionArray
    //                     );
    //                     $repeaterOptions[] = $camelCasedRepeaterOption;
    //                 }
    //                 $camelCasedRepeaterField['repeaterOptions'] = $repeaterOptions;
    //                 $repeaterFields[] = $camelCasedRepeaterField;
    //             }

    //             // Include the new columns in the response
    //             $customFields['data'][] = [
    //                 'id' => $field->id,
    //                 'post_type' => $field->post_type,
    //                 'field_label' => $field->field_label,
    //                 'field_name' => $field->field_name,
    //                 'field_placeholder' => $field->field_placeholder,
    //                 // 'field_name_description' => $field->field_name_description,
    //                 'checkbox_visible' => $field->field_type == 'checkbox' ? true : false,
    //                 'media_visible' => $field->field_type == 'media' ? true : false,
    //                 'file_visible' => $field->field_type == 'file' ? true : false,
    //                 'select_visible' => $field->field_type == 'select' ? true : false,
    //                 'radio_visible' => $field->field_type == 'radio' ? true : false,
    //                 'repeater_visible' => $field->field_type == 'repeater' ? true : false,
    //                 'field_type' => $field->field_type,
    //                 'options' => $field->options,
    //                 'repeater_fields' => $repeaterFields,
    //                 'model' => $field->model,
    //                 'model_' => $modelNameNew,
    //                 'model_condition' => $conditionData,
    //                 'condition' => $conditionArray,
    //                 'required' => $field->required,
    //                 'media_limit' => $field->media_limit,
    //                 'media_size' => $field->media_size,
    //                 'media_format' => explode(',', $field->media_format),
    //                 'checkbox_type' => $request->checkbox_type ?? 'manually' ?? 'import_from_aminities',
    //                 'created_at' => $field->created_at,
    //                 'updated_at' => $field->updated_at
    //             ];
    //         }

    //         // Return the array of custom field data as JSON response
    //         return response()->json($customFields, 200);
    //     } catch (\Exception $e) {
    //         // Log and return generic error response
    //         Log::error('Error: ' . $e->getMessage());
    //         return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
    //     }
    // }

    // private function getModelConditionData($model)
    // {
    //     switch ($model) {
    //         case 'purpose':
    //             return Purpose::all();
    //         case 'property':
    //             return Property::all();
    //         case 'property_type':
    //             return PropertyType::all();
    //         case 'property_status':
    //             return Status::all();
    //         case 'amenities':
    //             return Amenity::all();
    //         case 'amenities_categories':
    //             return AmenitiesCategory::all();
    //         default:
    //             return [];
    //     }
    // }

    ############################## new get custom field by group id function ################################

    public function getCustomFieldByGroupId(Request $request)
    {
        try {
            $data = CustomField::where('group_id', $request->group_id)
                ->with(['groupname', 'options', 'repeaterFields.repeaterOptions', 'templateValue'])
                ->get();

            if ($data->isEmpty()) {
                return response()->json([], 200);
            }

            $groupData = Groupname::find($request->group_id);

            $customFields = [];
            $customFields['group_data'] = [
                'group_name' => $groupData->group_name,
                'group_id' => $groupData->id
            ];

            $customFields['data'] = [];

            foreach ($data as $field) {
                // Decode model_fields JSON
                $modelFields = json_decode($field->model_fields, true) ?? [];

                // Convert model names to readable format
                $modelNames = [];
                foreach ($modelFields as $modelFieldItem) {
                    $modelNames[] = ucwords(str_replace('_', ' ', $modelFieldItem['model'] ?? ''));
                }

                // Collect model_condition data
                $modelConditionData = [];
                foreach ($modelFields as $modelFieldItem) {
                    $singleModel = $modelFieldItem['model'] ?? null;
                    if ($singleModel) {
                        $modelConditionData[$singleModel] = $this->getModelConditionData($singleModel);
                    }
                }

                // Prepare repeater fields
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

                    // Repeater Options in camelCase
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

                $customFields['data'][] = [
                    'id' => $field->id,
                    'post_type' => $field->post_type,
                    'field_label' => $field->field_label,
                    'field_name' => $field->field_name,
                    'field_name_slug' => $field->field_name_slug,
                    'field_placeholder' => $field->field_placeholder,
                    'checkbox_visible' => $field->field_type === 'checkbox',
                    'media_visible' => $field->field_type === 'media',
                    'file_visible' => $field->field_type === 'file',
                    'select_visible' => $field->field_type === 'select',
                    'radio_visible' => $field->field_type === 'radio',
                    'repeater_visible' => $field->field_type === 'repeater',
                    'field_type' => $field->field_type,
                    'options' => $field->options,
                    'repeater_fields' => $repeaterFields,
                    'model_fields' => $modelFields,
                    'model_names' => $modelNames,
                    'model_condition' => $modelConditionData,
                    'required' => $field->required,
                    'media_limit' => $field->media_limit,
                    'media_size' => $field->media_size,
                    'media_format' => explode(',', $field->media_format),
                    'checkbox_type' => $request->checkbox_type ?? 'manually',
                    'template_id' => $field->template_id,
                    'template' => $field->templateValue,
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at
                ];
            }

            return response()->json($customFields, 200);

        } catch (Exception $e) {
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong. ' . $e->getMessage()], 500);
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

    ############################ end get custom field by group id function #############################

    ########################### get custom field by id #############################

    // public function getCustomFieldById($customFieldId)
    // {
    //     try {
    //         $customField = CustomField::with('repeaters', 'templateValue')->find($customFieldId);

    //         if (!$customField) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Custom Field not found',
    //                 'debug' => [
    //                     'requested_id' => $customFieldId,
    //                     'available_ids' => CustomField::pluck('id')->toArray(),
    //                 ]
    //             ], 404);
    //         }

    //         $customField->group_name = Groupname::where('id', $customField->group_id)->value('group_name');
    //         $customField->model_fields = json_decode($customField->model_fields, true) ?? [];

    //         // 🔁 Filtered model_condition only for listed condition IDs
    //         $conditionData = [];
    //         foreach ($customField->model_fields as $modelField) {
    //             if (isset($modelField['model'], $modelField['condition'])) {
    //                 $modelName = $modelField['model'];
    //                 $conditionIds = $modelField['condition'];

    //                 $conditionData[$modelName] = $this->getFilteredModelConditionData($modelName, $conditionIds);
    //             }
    //         }
    //         $customField->model_condition = $conditionData;

    //         $customFieldOptions = CustomFieldOption::where('custom_field_id', $customField->id)->get()->map(function ($option) {
    //             return [
    //                 'label' => $option->name,
    //                 'value' => $option->value,
    //                 'created_at' => $option->created_at->format('Y-m-d H:i:s'),
    //                 'updated_at' => $option->updated_at->format('Y-m-d H:i:s'),
    //             ];
    //         });

    //         $formattedData = [
    //             'id' => $customField->id,
    //             'group_id' => $customField->group_id,
    //             'field_label' => $customField->field_label,
    //             'field_type' => $customField->field_type,
    //             'post_type' => $customField->post_type,
    //             'required' => $customField->required,
    //             'field_name_slug' => $customField->field_name_slug,
    //             'field_placeholder' => $customField->field_placeholder,
    //             'created_at' => $customField->created_at->format('Y-m-d H:i:s'),
    //             'updated_at' => $customField->updated_at->format('Y-m-d H:i:s'),
    //             'group_name' => $customField->group_name,
    //             'model_fields' => $customField->model_fields,
    //             'model_condition' => $customField->model_condition,
    //             'options' => $customFieldOptions,
    //             'checkbox_type' => $customField->checkbox_type,
    //             'select_visible' => $customField->field_type === 'select',
    //             'radio_visible' => $customField->field_type === 'radio',
    //             'checkbox_visible' => $customField->field_type === 'checkbox',
    //             'media_visible' => $customField->field_type === 'media',
    //             'file_visible' => $customField->field_type === 'file',
    //             'repeater_visible' => $customField->field_type === 'repeater',
    //             'repeater' => $customField->repeaters->map(function ($repeater) {
    //                 $data = [
    //                     'id' => $repeater->id,
    //                     'fieldName' => $repeater->field_label,
    //                     'field_name_slug' => $repeater->field_name_slug,
    //                     'fieldType' => $repeater->field_type,
    //                     'fieldPlaceholder' => $repeater->field_placeholder,
    //                 ];

    //                 // ✅ Add media_* fields only if media/file
    //                 if (in_array($repeater->field_type, ['media', 'file'])) {
    //                     $data['fieldMediaLimit'] = $repeater->media_limit;
    //                     $data['fieldMediaSize'] = $repeater->media_size;
    //                     $data['fieldMediaFormat'] = $repeater->media_format;
    //                 }

    //                 return $data;
    //             }),
    //             'template_id' => $customField->template_id,
    //             'template_value' => $customField->templateValue,
    //         ];

    //         // ✅ Add top-level media_* fields only if media/file
    //         if (in_array($customField->field_type, ['media', 'file'])) {
    //             $formattedData['media_limit'] = $customField->media_limit;
    //             $formattedData['media_size'] = $customField->media_size;
    //             $formattedData['media_format'] = $customField->media_format;
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Custom Field fetched successfully',
    //             'data' => [$formattedData]
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Error fetching custom field',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function getCustomFieldById($customFieldId)
    {
        try {
            // Eager load repeater options to optimize DB queries
            $customField = CustomField::with(['repeaters.repeaterOptions', 'templateValue'])->find($customFieldId);

            if (!$customField) {
                return response()->json([
                    'status' => false,
                    'message' => 'Custom Field not found',
                    'debug' => [
                        'requested_id' => $customFieldId,
                        'available_ids' => CustomField::pluck('id')->toArray(),
                    ]
                ], 404);
            }

            $customField->group_name = Groupname::where('id', $customField->group_id)->value('group_name');
            $customField->model_fields = json_decode($customField->model_fields, true) ?? [];

            // ✅ Fetch condition data for each model field
            $conditionData = [];
            foreach ($customField->model_fields as $modelField) {
                if (isset($modelField['model'], $modelField['condition'])) {
                    $modelName = $modelField['model'];
                    $conditionIds = $modelField['condition'];
                    $conditionData[$modelName] = $this->getFilteredModelConditionData($modelName, $conditionIds);
                }
            }
            $customField->model_condition = $conditionData;

            // ✅ Fetch top-level field options
            $customFieldOptions = CustomFieldOption::where('custom_field_id', $customField->id)->get()->map(function ($option) {
                return [
                    'label' => $option->name,
                    'value' => $option->value,
                    'created_at' => $option->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $option->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            // ✅ Construct response
            $formattedData = [
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

                // ✅ Repeater fields with options and media config
                'repeater' => $customField->repeaters->map(function ($repeater) {
                    $data = [
                        'id' => $repeater->id,
                        'fieldName' => $repeater->field_label,
                        'field_name_slug' => $repeater->field_name_slug,
                        'fieldType' => $repeater->field_type,
                        'fieldPlaceholder' => $repeater->field_placeholder,
                    ];

                    if (in_array($repeater->field_type, ['media', 'file'])) {
                        $data['fieldMediaLimit'] = $repeater->media_limit;
                        $data['fieldMediaSize'] = $repeater->media_size;
                        $data['fieldMediaFormat'] = $repeater->media_format;
                    }

                    if (in_array($repeater->field_type, ['select', 'radio', 'checkbox'])) {
                        $data['options'] = $repeater->repeaterOptions->map(function ($option) {
                            return [
                                'label' => $option->name,
                                'value' => $option->value,
                                'created_at' => $option->created_at->format('Y-m-d H:i:s'),
                                'updated_at' => $option->updated_at->format('Y-m-d H:i:s'),
                            ];
                        });
                    }

                    return $data;
                }),

                'template_id' => $customField->template_id,
                'template_value' => $customField->templateValue,
            ];

            // ✅ Top-level media/file config
            if (in_array($customField->field_type, ['media', 'file'])) {
                $formattedData['media_limit'] = $customField->media_limit;
                $formattedData['media_size'] = $customField->media_size;
                $formattedData['media_format'] = $customField->media_format;
            }

            return response()->json([
                'status' => true,
                'message' => 'Custom Field fetched successfully',
                'data' => [$formattedData]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching custom field',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    private function getFilteredModelConditionData($model, array $conditionIds)
    {
        switch ($model) {
            case 'purpose':
                return Purpose::whereIn('id', $conditionIds)->get();

            case 'property':
                return Property::whereIn('id', $conditionIds)->get();

            case 'property_type':
                return PropertyType::whereIn('id', $conditionIds)->get();

            case 'property_status':
                return Status::whereIn('id', $conditionIds)->get();

            case 'amenities':
                return Amenity::whereIn('id', $conditionIds)->get();

            case 'amenities_categories':
                return AmenitiesCategory::whereIn('id', $conditionIds)->get();

            default:
                return [];
        }
    }

    ###################### update custom field by id ###########################

    // public function updateCustomFieldById(Request $request, $id)
    // {
    //     $fieldId = $id;
    //     try {
    //         $validatedData = $request->validate([
    //             'group_id' => 'nullable|numeric|exists:group_name,id',
    //             'group_name' => 'nullable|string|max:255', // ✅ Add group_name
    //             'field_label' => 'required|string|max:255',
    //             'field_name_slug' => 'required|string|max:255|unique:custom_fields,field_name_slug,' . $fieldId,
    //             'field_placeholder' => 'nullable|string|max:255',
    //             'field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file',
    //             'required' => 'required|string|in:yes,no',
    //             'post_type' => 'required|string|max:255',
    //             'template_id' => 'nullable|integer|exists:custom_field_unique_codes,id',
    //             'media_limit' => 'nullable|integer',
    //             'media_size' => 'nullable|string',
    //             'media_format' => 'nullable|array',
    //             'options' => 'nullable|array',
    //             'options.*.label' => 'required_if:field_type,select,checkbox|string|max:255',
    //             'options.*.value' => 'required_if:field_type,select,checkbox|string|max:255',
    //             'repeater' => 'nullable|array',
    //             'model_fields' => 'nullable|array',
    //             'model_fields.*.model' => 'nullable|string',
    //             'model_fields.*.condition' => 'nullable|array',
    //         ]);

    //         DB::beginTransaction();

    //         $field = CustomField::findOrFail($fieldId);
    //         $groupId = $validatedData['group_id'] ?? $field->group_id;

    //         // ✅ Update group name if sent
    //         if (!empty($validatedData['group_name'])) {
    //             $group = Groupname::find($groupId);
    //             if ($group) {
    //                 $group->update([
    //                     'group_name' => $validatedData['group_name']
    //                 ]);
    //             }
    //         }

    //         $mediaFormat = isset($validatedData['media_format']) ? implode(',', $validatedData['media_format']) : null;

    //         $modelFieldsData = [];
    //         if (!empty($validatedData['model_fields'])) {
    //             foreach ($validatedData['model_fields'] as $modelField) {
    //                 $modelFieldsData[] = [
    //                     'model' => $modelField['model'],
    //                     'condition' => $modelField['condition'],
    //                 ];
    //             }
    //         }

    //         $field->update([
    //             'group_id' => $groupId,
    //             'field_label' => $validatedData['field_label'],
    //             'field_name_slug' => $validatedData['field_name_slug'],
    //             'field_placeholder' => $validatedData['field_placeholder'] ?? null,
    //             'field_type' => $validatedData['field_type'],
    //             'required' => $validatedData['required'],
    //             'post_type' => $validatedData['post_type'],
    //             'template_id' => $validatedData['template_id'] ?? null,
    //             'media_limit' => $validatedData['media_limit'] ?? null,
    //             'media_size' => $validatedData['media_size'] ?? null,
    //             'media_format' => $mediaFormat,
    //             'model_fields' => json_encode($modelFieldsData),
    //         ]);

    //         // ➤ Select / Checkbox Options
    //         if (in_array($field->field_type, ['select', 'checkbox']) && isset($validatedData['options'])) {
    //             $existingOptionIds = $field->options->pluck('id')->toArray();
    //             $newOptionIds = [];

    //             foreach ($validatedData['options'] as $option) {
    //                 if (isset($option['id'])) {
    //                     CustomFieldOption::where('id', $option['id'])->update([
    //                         'name' => $option['label'],
    //                         'value' => $option['value'],
    //                     ]);
    //                     $newOptionIds[] = $option['id'];
    //                 } else {
    //                     $newOption = CustomFieldOption::create([
    //                         'custom_field_id' => $field->id,
    //                         'type' => $field->field_type,
    //                         'name' => $option['label'],
    //                         'value' => $option['value'],
    //                     ]);
    //                     $newOptionIds[] = $newOption->id;
    //                 }
    //             }

    //             CustomFieldOption::where('custom_field_id', $field->id)
    //                 ->whereNotIn('id', $newOptionIds)
    //                 ->delete();
    //         }

    //         // ➤ Repeater Fields
    //         if ($field->field_type === 'repeater' && isset($validatedData['repeater'])) {
    //             $repeaterIds = [];

    //             foreach ($validatedData['repeater'] as $repeaterItem) {
    //                 $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat'])
    //                     ? (is_array($repeaterItem['fieldMediaFormat']) ? implode(',', $repeaterItem['fieldMediaFormat']) : $repeaterItem['fieldMediaFormat'])
    //                     : null;

    //                 $repeaterField = CustomFieldRepeater::updateOrCreate(
    //                     ['field_name_slug' => Str::slug($repeaterItem['field_name_slug'])],
    //                     [
    //                         'group_id' => $groupId,
    //                         'custom_field_id' => $field->id,
    //                         'field_label' => $repeaterItem['fieldName'],
    //                         'field_type' => $repeaterItem['fieldType'],
    //                         'field_name_slug' => $repeaterItem['field_name_slug'],
    //                         'field_placeholder' => $repeaterItem['fieldPlaceholder'] ?? null,
    //                         'media_format' => $fieldMediaFormat,
    //                         'media_limit' => $repeaterItem['fieldMediaLimit'] ?? null,
    //                         'media_size' => $repeaterItem['fieldMediaSize'] ?? null,
    //                     ]
    //                 );

    //                 $repeaterIds[] = $repeaterField->id;

    //                 if (isset($repeaterItem['fieldOptions'])) {
    //                     $existing = $repeaterField->options->pluck('id')->toArray();
    //                     $newIds = [];

    //                     foreach ($repeaterItem['fieldOptions'] as $option) {
    //                         if (isset($option['id'])) {
    //                             CustomFieldRepeaterOption::where('id', $option['id'])->update([
    //                                 'name' => $option['name'],
    //                                 'value' => $option['value'],
    //                             ]);
    //                             $newIds[] = $option['id'];
    //                         } else {
    //                             $new = CustomFieldRepeaterOption::create([
    //                                 'custom_field_repeater_id' => $repeaterField->id,
    //                                 'type' => $repeaterItem['fieldType'],
    //                                 'name' => $option['name'],
    //                                 'value' => $option['value'],
    //                             ]);
    //                             $newIds[] = $new->id;
    //                         }
    //                     }

    //                     CustomFieldRepeaterOption::where('custom_field_repeater_id', $repeaterField->id)
    //                         ->whereNotIn('id', $newIds)
    //                         ->delete();
    //                 }
    //             }

    //             CustomFieldRepeater::where('custom_field_id', $field->id)
    //                 ->whereNotIn('id', $repeaterIds)
    //                 ->delete();
    //         }

    //         DB::commit();
    //         return response()->json(['message' => 'Custom field updated successfully'], 200);

    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         DB::rollBack();
    //         return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['message' => 'Update failed', 'error' => $e->getMessage()], 500);
    //     }
    // }


    public function updateCustomFieldById(Request $request, $id)
    {
        $fieldId = $id;
        try {
            $validatedData = $request->validate([
                'group_id' => 'nullable|numeric|exists:group_name,id',
                'group_name' => 'nullable|string|max:255',
                'field_label' => 'required|string|max:255',
                'field_name_slug' => 'required|string|max:255|unique:custom_fields,field_name_slug,' . $fieldId,
                'field_placeholder' => 'nullable|string|max:255',
                'field_type' => 'required|string|in:text,texteditor,textarea,checkbox,radio,select,repeater,media,file,number',
                'required' => 'required|string|in:yes,no',
                'post_type' => 'required|string|max:255',
                'template_id' => 'nullable|integer|exists:custom_field_unique_codes,id',
                'media_limit' => 'nullable|integer',
                'media_size' => 'nullable|string',
                'media_format' => 'nullable|array',
                'options' => 'nullable|array',
                'options.*.label' => 'required_if:field_type,select,checkbox|string|max:255',
                'options.*.value' => 'required_if:field_type,select,checkbox|string|max:255',
                'repeater' => 'nullable|array',
                'model_fields' => 'nullable|array',
                'model_fields.*.model' => 'nullable|string',
                'model_fields.*.condition' => 'nullable|array',
            ]);

            DB::beginTransaction();

            // ✅ Eager load options relation
            $field = CustomField::with('options')->findOrFail($fieldId);
            $groupId = $validatedData['group_id'] ?? $field->group_id;

            if (!empty($validatedData['group_name'])) {
                $group = Groupname::find($groupId);
                if ($group) {
                    $group->update(['group_name' => $validatedData['group_name']]);
                }
            }

            $mediaFormat = isset($validatedData['media_format']) ? implode(',', $validatedData['media_format']) : null;

            $modelFieldsData = [];
            if (!empty($validatedData['model_fields'])) {
                foreach ($validatedData['model_fields'] as $modelField) {
                    $modelFieldsData[] = [
                        'model' => $modelField['model'],
                        'condition' => $modelField['condition'],
                    ];
                }
            }

            $field->update([
                'group_id' => $groupId,
                'field_label' => $validatedData['field_label'],
                'field_name_slug' => $validatedData['field_name_slug'],
                'field_placeholder' => $validatedData['field_placeholder'] ?? null,
                'field_type' => $validatedData['field_type'],
                'required' => $validatedData['required'],
                'post_type' => $validatedData['post_type'],
                'template_id' => $validatedData['template_id'] ?? null,
                'media_limit' => $validatedData['media_limit'] ?? null,
                'media_size' => $validatedData['media_size'] ?? null,
                'media_format' => $mediaFormat,
                'model_fields' => json_encode($modelFieldsData),
            ]);

            // ✅ Select / Checkbox Options
            if (in_array($field->field_type, ['select', 'checkbox']) && isset($validatedData['options'])) {
                $existingOptionIds = $field->options ? $field->options->pluck('id')->toArray() : [];
                $newOptionIds = [];

                foreach ($validatedData['options'] as $option) {
                    if (isset($option['id'])) {
                        CustomFieldOption::where('id', $option['id'])->update([
                            'name' => $option['label'],
                            'value' => $option['value'],
                        ]);
                        $newOptionIds[] = $option['id'];
                    } else {
                        $newOption = CustomFieldOption::create([
                            'custom_field_id' => $field->id,
                            'type' => $field->field_type,
                            'name' => $option['label'],
                            'value' => $option['value'],
                        ]);
                        $newOptionIds[] = $newOption->id;
                    }
                }

                CustomFieldOption::where('custom_field_id', $field->id)
                    ->whereNotIn('id', $newOptionIds)
                    ->delete();
            }

            // ✅ Repeater Fields
            if ($field->field_type === 'repeater' && isset($validatedData['repeater'])) {
                $repeaterIds = [];

                foreach ($validatedData['repeater'] as $repeaterItem) {
                    $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat'])
                        ? (is_array($repeaterItem['fieldMediaFormat']) ? implode(',', $repeaterItem['fieldMediaFormat']) : $repeaterItem['fieldMediaFormat'])
                        : null;

                    $repeaterField = CustomFieldRepeater::updateOrCreate(
                        ['field_name_slug' => Str::slug($repeaterItem['field_name_slug'])],
                        [
                            'group_id' => $groupId,
                            'custom_field_id' => $field->id,
                            'field_label' => $repeaterItem['fieldName'],
                            'field_type' => $repeaterItem['fieldType'],
                            'field_name_slug' => $repeaterItem['field_name_slug'],
                            'field_placeholder' => $repeaterItem['fieldPlaceholder'] ?? null,
                            'media_format' => $fieldMediaFormat,
                            'media_limit' => $repeaterItem['fieldMediaLimit'] ?? null,
                            'media_size' => $repeaterItem['fieldMediaSize'] ?? null,
                        ]
                    );

                    $repeaterIds[] = $repeaterField->id;

                    if (isset($repeaterItem['fieldOptions'])) {
                        $existing = $repeaterField->options ? $repeaterField->options->pluck('id')->toArray() : [];
                        $newIds = [];

                        foreach ($repeaterItem['fieldOptions'] as $option) {
                            if (isset($option['id'])) {
                                CustomFieldRepeaterOption::where('id', $option['id'])->update([
                                    'name' => $option['name'],
                                    'value' => $option['value'],
                                ]);
                                $newIds[] = $option['id'];
                            } else {
                                $new = CustomFieldRepeaterOption::create([
                                    'custom_field_repeater_id' => $repeaterField->id,
                                    'type' => $repeaterItem['fieldType'],
                                    'name' => $option['name'],
                                    'value' => $option['value'],
                                ]);
                                $newIds[] = $new->id;
                            }
                        }

                        CustomFieldRepeaterOption::where('custom_field_repeater_id', $repeaterField->id)
                            ->whereNotIn('id', $newIds)
                            ->delete();
                    }
                }

                CustomFieldRepeater::where('custom_field_id', $field->id)
                    ->whereNotIn('id', $repeaterIds)
                    ->delete();
            }

            DB::commit();
            return response()->json(['message' => 'Custom field updated successfully'], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Update failed', 'error' => $e->getMessage()], 500);
        }
    }

    #################### end update custom field by id ################



    // this is for listing of custom fields by type
    // public function customFieldListingByType(Request $request)
    // {
    //     try {

    //         if (isset($request->post_type) && $request->post_type == 'project') {
    //             $post_type = 'project';
    //         } elseif (isset($request->post_type) && $request->post_type == 'developer_list') {
    //             $post_type = 'developer_list';
    //         } else {
    //             $post_type = 'property_list';
    //         }

    //         $groupIdArr = [];
    //         // Retrieve all CustomField records with their associated Groupname
    //         $data = CustomField::with('groupname', 'options')->where('post_type', $post_type)->get();

    //         $customFields = [];


    //         foreach ($data as $field) {
    //             array_push($groupIdArr, $field->group_id);

    //             switch ($field->model) {
    //                 case 'purpose':
    //                     $modelConditionName = Purpose::whereIn('id', json_decode($field->condition))->get();
    //                     break;
    //                 case 'property':
    //                     $modelConditionName = Property::whereIn('id', json_decode($field->condition))->get();
    //                     break;
    //                 case 'property_type':
    //                     $modelConditionName = PropertyType::whereIn('id', json_decode($field->condition))->get();
    //                     break;
    //                 case 'property_status':
    //                     $modelConditionName = Status::whereIn('id', json_decode($field->condition))->get();
    //                     break;
    //                 case 'amenities':
    //                     $modelConditionName = Amenity::whereIn('id', json_decode($field->condition))->get();
    //                     break;
    //                 case 'amenities_categories':
    //                     $modelConditionName = AmenitiesCategory::whereIn('id', json_decode($field->condition))->get();
    //                     break;
    //                 default:
    //                     $modelConditionName = null;
    //                     break;
    //             }


    //             $modelNameNew = ucwords(str_replace('_', ' ', $field->model));


    //             $customFields[] = [
    //                 'id' => $field->id,
    //                 'post_type' => $field->post_type,
    //                 'field_label' => $field->field_label,
    //                 'field_name' => $field->field_name,
    //                 'field_placeholder' => $field->field_placeholder,
    //                 //'field_name_description' => $field->field_name_description,
    //                 'field_type' => $field->field_type,
    //                 'options' => $field->options,
    //                 'model' => $field->model,
    //                 'model_show' => $modelNameNew,
    //                 'condition' => $modelConditionName,
    //                 'group_data' => $field->groupname,
    //                 'required' => $field->required,
    //                 'media_limit' => $field->media_limit,
    //                 'media_size' => $field->media_size,
    //                 'media_format' => explode(',', $field->media_format),
    //                 'post_type' => $field->post_type,
    //                 'checkbox_type' => $field->checkbox_type,
    //                 'created_at' => $field->created_at,
    //                 'updated_at' => $field->updated_at
    //             ];
    //         }


    //         // Return the array of custom field data as JSON response
    //         return response()->json($customFields, 200);
    //     } catch (\Exception $e) {
    //         // Log and return generic error response
    //         Log::error('Error: ' . $e->getMessage());
    //         return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
    //     }
    // }





    ################# new update code model_fields ###############
    public function customFieldListingByType(Request $request)
    {
        try {
            // Determine the post type from the request
            if (isset($request->post_type) && $request->post_type == 'project_list') {
                $post_type = 'project_list';
            } elseif (isset($request->post_type) && $request->post_type == 'developer_list') {
                $post_type = 'developer_list';
            } else {
                $post_type = 'property_list';
            }

            $groupIdArr = [];

            // Retrieve all CustomField records with their associated Groupname and Options
            $data = CustomField::with('groupname', 'options')->where('post_type', $post_type)->get();

            $customFields = [];

            foreach ($data as $field) {
                array_push($groupIdArr, $field->group_id);

                $modelConditions = [];

                if (!empty($field->model_fields)) {
                    $modelFieldsDecoded = json_decode($field->model_fields, true);

                    foreach ($modelFieldsDecoded as $mf) {
                        $model = $mf['model'] ?? null;
                        $conditionIds = $mf['condition'] ?? [];

                        if (!$model || empty($conditionIds)) {
                            continue;
                        }

                        // Load related condition data dynamically
                        switch ($model) {
                            case 'purpose':
                                $conditionData = Purpose::whereIn('id', $conditionIds)->get();
                                break;
                            case 'property':
                                $conditionData = Property::whereIn('id', $conditionIds)->get();
                                break;
                            case 'property_type':
                                $conditionData = PropertyType::whereIn('id', $conditionIds)->get();
                                break;
                            case 'property_status':
                                $conditionData = Status::whereIn('id', $conditionIds)->get();
                                break;
                            case 'amenities':
                                $conditionData = Amenity::whereIn('id', $conditionIds)->get();
                                break;
                            case 'amenities_categories':
                                $conditionData = AmenitiesCategory::whereIn('id', $conditionIds)->get();
                                break;
                            default:
                                $conditionData = null;
                                break;
                        }

                        // Push if valid data is found
                        if ($conditionData) {
                            $modelConditions[] = [
                                'model' => $model,
                                'model_show' => ucwords(str_replace('_', ' ', $model)),
                                'conditions' => $conditionData
                            ];
                        }
                    }
                }

                $customFields[] = [
                    'id' => $field->id,
                    'post_type' => $field->post_type,
                    'field_label' => $field->field_label,
                    'field_name' => $field->field_name,
                    'field_placeholder' => $field->field_placeholder,
                    'field_type' => $field->field_type,
                    'options' => $field->options,
                    'model_fields' => $modelConditions,
                    'group_data' => $field->groupname,
                    'required' => $field->required,
                    'media_limit' => $field->media_limit,
                    'media_size' => $field->media_size,
                    'media_format' => explode(',', $field->media_format),
                    'checkbox_type' => $field->checkbox_type,
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at
                ];
            }

            // Return the result as JSON response
            return response()->json($customFields, 200);

        } catch (\Exception $e) {
            // Log and return error
            Log::error('customFieldListingByType error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong. ' . $e->getMessage()], 500);
        }
    }

    ################# end code #################



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



    public function customFieldListing(Request $request)
    {
        try {

            //  Which model are we looking for inside model_fields?

            $filterModel = $request->model;
            if (!$filterModel) {
                return response()->json(
                    ['error' => 'Please pass `model` in request body'],
                    422
                );
            }


            //    Pull ALL rows that might match   (post_type = property_list)
            //    If you are on MySQL ≥ 5.7 / MariaDB ≥ 10.2 you can
            //    replace this with a JSON_SEARCH() WHERE clause; the
            //    plain LIKE keeps it portable.

            $cusData = CustomField::query()
                ->where('post_type', 'property_list')
                ->where('model_fields', 'LIKE', '%"model":"' . $filterModel . '"%')
                ->with('groupname', 'options')
                ->get();


            //  Iterate & keep only rows whose model_fields
            //    actually CONTAIN the requested model

            $payload = [];

            foreach ($cusData as $row) {

                $modelFields = json_decode($row->model_fields, true) ?: [];
                $matchesFilter = false;
                $resolvedConditions = [];

                foreach ($modelFields as $mf) {
                    // Does this entry correspond to the requested model?
                    if (($mf['model'] ?? null) !== $filterModel) {
                        continue;
                    }

                    $matchesFilter = true;                  // mark row as match
                    foreach ($mf['condition'] ?? [] as $id) {
                        // resolve ID → human-readable name
                        $resolvedConditions[] = $this->resolveCondition(
                            $filterModel,
                            $id
                        );
                    }
                }

                // skip rows that do not contain the requested model
                if (!$matchesFilter) {
                    continue;
                }

                $payload[] = [
                    'id' => $row->id,
                    'group' => $row->groupname,
                    'field_label' => $row->field_label,
                    'field_name' => $row->field_name_slug,
                    'field_placeholder' => $row->field_placeholder,
                    'field_type' => $row->field_type,
                    'required' => $row->required,
                    'options' => $row->options,
                    'model_conditions' => array_values(
                        array_filter($resolvedConditions)   // drop nulls
                    ),
                ];
            }

            return $payload
                ? response()->json(['data' => $payload], 200)
                : response()->json(['success' => 'No data found'], 200);

        } catch (\Throwable $e) {
            \Log::error('customFieldListing error: ' . $e->getMessage());
            return response()->json(
                ['error' => 'Something went wrong. ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Resolve a single condition ID into {model,id,name}
     * Returns null if the row is missing or unsupported.
     */
    private function resolveCondition(string $model, int $id): ?array
    {
        switch ($model) {
            case 'property_type':
                $record = PropertyType::find($id);
                break;
            case 'purpose':
                $record = Purpose::find($id);
                break;
            case 'property_status':
                $record = Status::find($id);
                break;
            case 'amenities':
                $record = Amenity::find($id);
                break;
            case 'amenities_categories':
                $record = AmenitiesCategory::find($id);
                break;
            default:
                return null;   // unsupported model
        }

        return $record
            ? ['model' => $model, 'id' => $id, 'name' => $record->name]
            : null;
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

    public function customFieldListingByModelConditionId123(Request $request)
    {
        try {
            // Validate request
            $validatedData = $request->validate([
                'model_fields' => 'required',  // 'model_fields' is expected to be an array
                'post_type' => 'nullable|string|in:project_list,developer_list,property_list',
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

    public function customFieldListingByModelConditionId(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'model_fields' => 'required|array',
                'post_type' => 'nullable|string|in:project_list,developer_list,property_list',
            ]);

            $postType = $request->post_type ?? 'property_list';

            $customFields = CustomField::where('post_type', $postType)
                ->with(['groupname', 'options', 'repeaterFields.repeaterFieldsOptions'])
                ->get()
                ->map(function ($customField) use ($validatedData) {
                    // Safely decode model_fields JSON
                    $modelFields = json_decode($customField->model_fields, true);
                    if (!is_array($modelFields))
                        return null;

                    // Filter model_fields
                    $filteredModelFields = array_values(array_filter($modelFields, function ($entry) use ($validatedData) {
                        foreach ($validatedData['model_fields'] as $modelField) {
                            if (
                                isset($entry['model']) &&
                                $entry['model'] === $modelField['model'] &&
                                !empty($entry['condition']) &&
                                count(array_intersect($entry['condition'], $modelField['condition'])) > 0
                            ) {
                                return true;
                            }
                        }
                        return false;
                    }));

                    if (empty($filteredModelFields))
                        return null;

                    // Process repeater fields
                    $processedRepeaterFields = $customField->repeaterFields->map(function ($repeater) {
                        $repeaterData = [
                            'id' => $repeater->id,
                            'field_name_slug' => $repeater->field_name_slug,
                            'group_id' => $repeater->group_id,
                            'custom_field_id' => $repeater->custom_field_id,
                            'field_type' => $repeater->field_type,
                            'field_label' => $repeater->field_label,
                            'field_placeholder' => $repeater->field_placeholder,
                            'status' => $repeater->status,
                            'created_at' => $repeater->created_at,
                            'updated_at' => $repeater->updated_at,
                            'repeater_fields_options' => $repeater->repeaterFieldsOptions,
                        ];

                        if ($repeaterData['field_type'] == 'checkbox') {
                            $repeaterData['checkbox_type'] = $repeater->checkbox_type;
                        }

                        // Add media details conditionally
                        if (in_array($repeater->field_type, ['media', 'file'])) {
                            $repeaterData['media_limit'] = $repeater->media_limit;
                            $repeaterData['media_size'] = $repeater->media_size;
                            $repeaterData['media_format'] = $repeater->media_format;
                        }

                        return $repeaterData;
                    });

                    // Final response per custom field
                    $response = [
                        'id' => $customField->id,
                        'group_data' => $customField->groupname,
                        'field_label' => $customField->field_label,
                        'field_name_slug' => $customField->field_name_slug,
                        'field_placeholder' => $customField->field_placeholder,
                        'field_type' => $customField->field_type,
                        'post_type' => $customField->post_type,
                        'required' => $customField->required,
                        'options' => $customField->options,
                        'model_fields' => $filteredModelFields,
                        // 'repeater_fields' => $processedRepeaterFields,
                        'field_value' => $processedRepeaterFields,
                    ];

                    if ($customField->field_type == 'checkbox') {
                        $response['checkbox_type'] = $customField->checkbox_type;
                    }

                    // Include media meta only if top-level field is media or file
                    if (in_array($customField->field_type, ['media', 'file'])) {
                        $response['media_limit'] = $customField->media_limit;
                        $response['media_size'] = $customField->media_size;
                        $response['media_format'] = $customField->media_format;
                    }

                    return $response;
                })
                ->filter()
                ->values();

            return response()->json(['data' => $customFields], 200);
        } catch (\Exception $e) {
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
    // public function propertyStatusListingByPropertyType(Request $request)
    // {
    //     try {

    //         $conditionData = Status::where('property_type_id', $request->property_type_id)->get();


    //         $data = [];

    //         if (count($conditionData) > 0) {
    //             foreach ($conditionData as $condition) {
    //                 $data[] = [
    //                     'id' => $condition->id,
    //                     'name' => $condition->name,
    //                 ];
    //             }
    //             $arr['data'] = $data;
    //             return response()->json($arr, 200);
    //         } else {
    //             return response()->json(['success' => 'No data found'], 200);
    //         }

    //     } catch (\Exception $e) {
    //         // Log and return generic error response
    //         Log::error('Error: ' . $e->getMessage());
    //         return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
    //     }
    // }

    // new function
    public function propertyStatusListingByPropertyType(Request $request)
    {
        try {
            // Get all statuses where the property_type_id JSON array contains the requested ID
            $allStatuses = Status::all();

            $filteredStatuses = $allStatuses->filter(function ($status) use ($request) {
                $propertyTypeIds = json_decode($status->property_type_id);
                return in_array($request->property_type_id, $propertyTypeIds);
            });

            $data = [];

            if ($filteredStatuses->count() > 0) {
                foreach ($filteredStatuses as $status) {
                    $data[] = [
                        'id' => $status->id,
                        'name' => $status->name,
                    ];
                }
                return response()->json(['data' => $data], 200);
            } else {
                return response()->json(['success' => 'No data found'], 200);
            }

        } catch (\Exception $e) {
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
        }
    }






    // this  id for listing of models
    // public function customFieldUniqueCode(Request $request)
    // {
    //     try {
    //         // Check if 'name' exists in the request and ensure its uniqueness
    //         $name = $request->input('name');

    //         // Check if name already exists in the CustomFieldUniqueCode table
    //         $existingField = CustomFieldUniqueCode::where('slug', $name)->first();

    //         if ($existingField) {
    //             return response()->json(['error' => 'The name is already taken.'], 422);
    //         }

    //         if (isset($request->post_type) && $request->post_type == 'project_list') {
    //             $post_type = 'project_list';
    //         } elseif (isset($request->post_type) && $request->post_type == 'developer_list') {
    //             $post_type = 'developer_list';
    //         } else {
    //             $post_type = 'property_list';
    //         }

    //         // Retrieve all CustomField records with their associated Groupname
    //         $Codedata = CustomFieldUniqueCode::where('status', 1)->get();

    //         $data = [];

    //         if (count($Codedata) > 0) {
    //             foreach ($Codedata as $model) {
    //                 // Modify the name field
    //                 $name = ucwords(str_replace('_', ' ', $model->name));

    //                 $data[] = [
    //                     'id' => $model->id,
    //                     'show' => $name,
    //                     'name' => $model->slug,
    //                     'type' => $model->post_type,
    //                 ];
    //             }
    //             $arr['data'] = $data;
    //             return response()->json($arr, 200);
    //         } else {
    //             return response()->json(['success' => 'No data found'], 200);
    //         }

    //     } catch (\Exception $e) {
    //         // Log and return generic error response
    //         Log::error('Error: ' . $e->getMessage());
    //         return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
    //     }
    // }

    public function customFieldUniqueCode(Request $request)
{
    try {
        $nameInput = $request->input('name');

        // Check if name already exists
        $existingField = CustomFieldUniqueCode::where('slug', $nameInput)->first();
        if ($existingField) {
            return response()->json(['error' => 'The name is already taken.'], 422);
        }

        // Determine post type
        $post_type = match($request->post_type) {
            'project_list' => 'project_list',
            'developer_list' => 'developer_list',
            default => 'property_list',
        };

        // Pagination: get 10 items per page (can be changed)
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $Codedata = CustomFieldUniqueCode::where('status', 1)
            ->paginate($perPage, ['*'], 'page', $page);

        if ($Codedata->count() > 0) {
            $data = $Codedata->map(function ($model) {
                return [
                    'id' => $model->id,
                    'show' => ucwords(str_replace('_', ' ', $model->name)),
                    'name' => $model->slug,
                    'type' => $model->post_type,
                ];
            });

            return response()->json([
                'data' => $data,
                'pagination' => [
                    'total' => $Codedata->total(),
                    'per_page' => $Codedata->perPage(),
                    'current_page' => $Codedata->currentPage(),
                    'last_page' => $Codedata->lastPage(),
                    'next_page_url' => $Codedata->nextPageUrl(),
                    'prev_page_url' => $Codedata->previousPageUrl(),
                ],
            ], 200);
        } else {
            return response()->json(['success' => 'No data found'], 200);
        }
    } catch (\Exception $e) {
        Log::error('Error: ' . $e->getMessage());
        return response()->json(['error' => 'Something went wrong. ' . $e->getMessage()], 500);
    }
}


    // start template

    public function storeCustomFieldUniqueCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'post_type' => 'required|in:project_list,property_list,developer_list',
            'status' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Generate slug from name
        $slug = Str::slug($validated['name']);

        //   Slug already exists globally (optional if DB unique)
        $slugExists = CustomFieldUniqueCode::where('slug', $slug)->exists();

        if ($slugExists) {
            return response()->json([
                'status' => false,
                'message' => 'Slug already exists. Please change the "name" field.',
            ], 409);
        }

        //   Slug with same post_type exists
        $comboExists = CustomFieldUniqueCode::where('slug', $slug)
            ->where('post_type', $validated['post_type'])
            ->exists();

        if ($comboExists) {
            return response()->json([
                'status' => false,
                'message' => 'This name already exists for the selected post type. Please change the name.',
            ], 409);
        }

        $validated['slug'] = $slug;

        $data = CustomFieldUniqueCode::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Data created successfully',
            'data' => $data,
        ], 201);
    }


    public function showCustomFieldUniqueCodeById(Request $request)
    {
        try {
            $id = $request->id;

            if (!$id) {
                return response()->json([
                    'status' => false,
                    'message' => 'ID is required',
                ], 400);
            }

            // Fetch data with status = 1
            $model = CustomFieldUniqueCode::where('id', $id)
                ->where('status', 1)
                ->first();

            if (!$model) {
                return response()->json([
                    'status' => false,
                    'message' => 'Data not found or inactive',
                ], 404);
            }

            // Format name
            $formattedName = ucwords(str_replace('_', ' ', $model->name));

            $data = [
                'id' => $model->id,
                'show' => $formattedName,
                'name' => $model->slug,
                'type' => $model->post_type,
            ];

            return response()->json([
                'status' => true,
                'message' => 'Data fetched successfully',
                'data' => $data,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error fetching CustomFieldUniqueCode by ID: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function updateCustomFieldUniqueCode(Request $request)
    {
        $id = $request->id;
        $data = CustomFieldUniqueCode::find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:50',
            'slug' => 'nullable|string|max:100',
            'post_type' => 'nullable|in:project_list,property_list,developer_list',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Use existing post_type if not sent
        $postType = $validated['post_type'] ?? $data->post_type;

        // Slug logic
        if (!empty($validated['slug'])) {
            $slug = Str::slug($validated['slug']);
        } elseif (!empty($validated['name'])) {
            $slug = Str::slug($validated['name']);
        } else {
            $slug = $data->slug; // no change
        }

        // Check for duplicate slug + post_type combo (excluding current record)
        $exists = CustomFieldUniqueCode::where('slug', $slug)
            ->where('post_type', $postType)
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => false,
                'message' => 'This name or slug already exists for the selected post type. Please change the name or slug.',
            ], 409);
        }

        $validated['slug'] = $slug;
        $validated['post_type'] = $postType;

        $data->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Data updated successfully',
            'data' => $data,
        ]);
    }


    public function destroyCustomFieldUniqueCode(Request $request)
    {
        $id = $request->id;
        $data = CustomFieldUniqueCode::find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data not found',
            ], 200);
        }

        $data->delete();

        return response()->json([
            'status' => true,
            'message' => 'Data deleted successfully',
        ]);
    }

    // Bulk Delete

    public function bulkDeleteCustomFieldUniqueCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:custom_field_unique_codes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ids = $request->ids;

        try {
            DB::beginTransaction();

            CustomFieldUniqueCode::whereIn('id', $ids)->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected records deleted successfully.',
                'deleted_ids' => $ids
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function exportCustomFieldUniqueCode()
    {
        $fileName = 'custom_field_unique_codes.csv';
        $columns = ['id', 'name', 'slug', 'post_type', 'status'];

        $callback = function () use ($columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            $records = CustomFieldUniqueCode::all($columns);
            foreach ($records as $record) {
                fputcsv($file, $record->toArray());
            }

            fclose($file);
        };

        return response()->stream($callback, 200, [
            "Content-Type" => "text/csv",
            "Content-Disposition" => "attachment; filename={$fileName}",
        ]);
    }


    public function importCustomFieldUniqueCode(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        $header = fgetcsv($handle, 1000, ','); // read first row (header)

        $updated = 0;
        $created = 0;

        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $data = array_combine($header, $row);

            // Slugify to keep it consistent
            $slug = Str::slug($data['slug'] ?? $data['name']);

            // First try update
            $record = CustomFieldUniqueCode::where('slug', $slug)
                ->where('post_type', $data['post_type'])
                ->first();

            if ($record) {
                $record->update([
                    'name' => $data['name'],
                    'slug' => $slug,
                    'post_type' => $data['post_type'],
                    'status' => $data['status'],
                ]);
                $updated++;
            } else {
                CustomFieldUniqueCode::create([
                    'name' => $data['name'],
                    'slug' => $slug,
                    'post_type' => $data['post_type'],
                    'status' => $data['status'],
                ]);
                $created++;
            }
        }

        fclose($handle);

        return response()->json([
            'status' => true,
            'message' => "Import completed. {$updated} updated, {$created} created.",
        ]);
    }


    public function searchCustomFieldUniqueCode(Request $request)
    {
        $request->validate([
            'search' => 'required|string|max:100',
        ]);

        $term = $request->search;

        $results = CustomFieldUniqueCode::where('status', 1)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%");
            })
            ->get();

        if ($results->isEmpty()) {
            return response()->json(['message' => 'No records found'], 200);
        }

        $data = $results->map(function ($model) {
            return [
                'id' => $model->id,
                'show' => ucwords(str_replace('_', ' ', $model->name)),
                'name' => $model->slug,
                'type' => $model->post_type,
            ];
        });

        return response()->json(['data' => $data], 200);
    }



    public function filterCustomFieldByType(Request $request)
    {
        $request->validate([
            'post_type' => 'required|in:project_list,property_list,developer_list',
        ]);

        $results = CustomFieldUniqueCode::where('status', 1)
            ->where('post_type', $request->post_type)
            ->get();

        if ($results->isEmpty()) {
            return response()->json(['message' => 'No records found for this type'], 200);
        }

        $data = $results->map(function ($model) {
            return [
                'id' => $model->id,
                'show' => ucwords(str_replace('_', ' ', $model->name)),
                'name' => $model->slug,
                'type' => $model->post_type,
            ];
        });

        return response()->json(['data' => $data], 200);
    }




    // end template



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

    // public function searchAndFilter(Request $request)
    // {
    //     try {
    //         // Extract input parameters
    //         $dropdownValue = $request->input('dropdown_value'); // This will be the group_name
    //         $searchQuery = $request->input('search');
    //         $modelValue = $request->input('model_value'); // Value for the second dropdown (model)
    //         $field = $request->input('field');
    //         $condition = $request->input('condition');
    //         $sortField = $request->input('sort_field'); // Field to sort by
    //         $sortOrder = $request->input('sort_order'); // Sort order: 'asc' or 'desc'
    //         $perPage = $request->input('per_page', 10);
    //         $fieldType = $request->input('field_type');
    //         // Start the query
    //         $query = CustomField::query();

    //         // Retrieve the group_id based on the group_name (dropdownValue)
    //         $groupId = DB::table('group_name')->where('group_name', $dropdownValue)->value('id');

    //         // Filter by group_id if dropdownValue exists
    //         if ($dropdownValue && $groupId) {
    //             $query->where('group_id', $groupId);
    //         }

    //         // If model_value is provided, filter records by the model
    //         if ($modelValue) {
    //             $query->where('model', $modelValue);
    //         }

    //         if ($fieldType) {
    //             $query->where('field_type', $fieldType);
    //         }

    //         // Apply search filtering
    //         if ($searchQuery) {
    //             // Join the group_name table to access group_name for searching
    //             $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
    //                 ->where(function ($q) use ($searchQuery) {
    //                     $q->where('field_label', 'LIKE', "%{$searchQuery}%")
    //                         ->orWhere('field_name', 'LIKE', "%{$searchQuery}%")
    //                         ->orWhere('model', 'LIKE', "%{$searchQuery}%")
    //                         ->orWhere('field_type', 'LIKE', "%{$searchQuery}%")
    //                         ->orWhere('group_name.group_name', 'LIKE', "%{$searchQuery}%"); // Search in group_name
    //                 })
    //                 ->select('custom_fields.*', 'group_name.group_name'); // Ensure selected fields are correct
    //         }


    //         // Apply specific field filtering
    //         if ($field && $condition) {
    //             if ($field === 'condition') {
    //                 $query->whereJsonContains($field, json_decode($condition));
    //             } else {
    //                 $query->where($field, $condition);
    //             }
    //         }
    //         // Apply sorting by specific fields
    //         if ($sortField && in_array($sortField, ['model', 'field_type', 'group_name', 'field_label'])) {
    //             if ($sortField === 'group_name') {
    //                 // For group_name, join with the group_name table to sort
    //                 $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
    //                     ->orderBy('group_name.group_name', $sortOrder)
    //                     ->select('custom_fields.*', 'group_name.group_name');
    //             } else {
    //                 $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
    //                     ->orderBy($sortField, $sortOrder)
    //                     ->select('custom_fields.*', 'group_name.group_name');
    //             }
    //         }

    //         // Fetch the filtered results
    //         $results = $query->paginate($perPage);

    //         // Return the results as a JSON response
    //         return response()->json([
    //             'message' => 'Filtered results retrieved successfully',
    //             'data' => $results->items(),
    //             'pagination' => [
    //                 'current_page' => $results->currentPage(),
    //                 'last_page' => $results->lastPage(),
    //                 'per_page' => $results->perPage(),
    //                 'total' => $results->total(),
    //             ],
    //         ], 200);
    //     } catch (\Exception $e) {
    //         // Return error response in case of failure
    //         return response()->json([
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    ################# new code #############

    // public function searchAndFilter(Request $request)
    // {
    //     try {
    //         $dropdownValue = $request->input('dropdown_value');
    //         $searchQuery = $request->input('search');
    //         $modelValue = $request->input('model_value');
    //         $conditionValue = $request->input('condition');
    //         $sortField = $request->input('sort_field');
    //         $sortOrder = $request->input('sort_order');
    //         $perPage = $request->input('per_page', 10);
    //         $fieldType = $request->input('field_type');
    //         $field = $request->input('field');

    //         $query = CustomField::query();

    //         // ✅ Group filter
    //         $groupId = DB::table('group_name')->where('group_name', $dropdownValue)->value('id');
    //         if ($dropdownValue && $groupId) {
    //             $query->where('group_id', $groupId);
    //         }

    //         // ✅ Filter by model_fields JSON column (model + condition)
    //         if ($modelValue && $conditionValue !== null) {
    //             $conditions = is_array($conditionValue) ? $conditionValue : [$conditionValue];
    //             foreach ($conditions as $cond) {
    //                 $query->whereRaw(
    //                     "JSON_SEARCH(model_fields, 'one', ?, NULL, '$[*].model') IS NOT NULL
    //                  AND JSON_CONTAINS(JSON_EXTRACT(model_fields, '$[*].condition'), JSON_ARRAY(?))",
    //                     [$modelValue, $cond]
    //                 );
    //             }
    //         }

    //         // ✅ Additional filtering from model_fields if "field" is provided (acts as model)
    //         if ($field && $conditionValue) {
    //             $values = is_array($conditionValue) ? $conditionValue : [$conditionValue];
    //             foreach ($values as $val) {
    //                 $query->whereRaw(
    //                     "JSON_SEARCH(model_fields, 'one', ?, NULL, '$[*].model') IS NOT NULL
    //                  AND JSON_CONTAINS(JSON_EXTRACT(model_fields, '$[*].condition'), JSON_ARRAY(?))",
    //                     [$field, $val]
    //                 );
    //             }
    //         }

    //         // ✅ Filter by field_type
    //         if ($fieldType) {
    //             $query->where('field_type', $fieldType);
    //         }

    //         // ✅ Search filtering (joins group_name table)
    //         if ($searchQuery) {
    //             $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
    //                 ->where(function ($q) use ($searchQuery) {
    //                     $q->where('field_label', 'LIKE', "%{$searchQuery}%")
    //                         ->orWhere('field_name_slug', 'LIKE', "%{$searchQuery}%")
    //                         ->orWhere('field_type', 'LIKE', "%{$searchQuery}%")
    //                         ->orWhere('group_name.group_name', 'LIKE', "%{$searchQuery}%");
    //                 })
    //                 ->select('custom_fields.*', 'group_name.group_name');
    //         }

    //         // ✅ Sorting (excluding model which is not a column)
    //         if ($sortField && in_array($sortField, ['field_type', 'group_name', 'field_label'])) {
    //             if ($sortField === 'group_name') {
    //                 $query->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
    //                     ->orderBy('group_name.group_name', $sortOrder)
    //                     ->select('custom_fields.*', 'group_name.group_name');
    //             } else {
    //                 $query->orderBy($sortField, $sortOrder);
    //             }
    //         }

    //         // ✅ Paginate
    //         $results = $query->paginate($perPage);

    //         return response()->json([
    //             'message' => 'Filtered results retrieved successfully',
    //             'data' => $results->items(),
    //             'pagination' => [
    //                 'current_page' => $results->currentPage(),
    //                 'last_page' => $results->lastPage(),
    //                 'per_page' => $results->perPage(),
    //                 'total' => $results->total(),
    //             ],
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }



    // public function searchAndFilter(Request $request)
    // {
    //     try {
    //         $dropdownValue = $request->input('dropdown_value');
    //         $searchQuery = $request->input('search');
    //         $modelValue = $request->input('model_value');
    //         $conditionValue = $request->input('condition');
    //         $sortField = $request->input('sort_field');
    //         $sortOrder = $request->input('sort_order');
    //         $perPage = $request->input('per_page', 10);
    //         $fieldType = $request->input('field_type');
    //         $field = $request->input('field');

    //         // ✅ Always include group_name
    //         $query = CustomField::query()
    //             ->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
    //             ->select('custom_fields.*', 'group_name.group_name');

    //         // ✅ Group filter
    //         $groupId = DB::table('group_name')->where('group_name', $dropdownValue)->value('id');
    //         if ($dropdownValue && $groupId) {
    //             $query->where('group_id', $groupId);
    //         }

    //         // ✅ Filter by model_fields JSON column (model + condition)
    //         if ($modelValue && $conditionValue !== null) {
    //             $conditions = is_array($conditionValue) ? $conditionValue : [$conditionValue];
    //             foreach ($conditions as $cond) {
    //                 $query->whereRaw(
    //                     "JSON_SEARCH(model_fields, 'one', ?, NULL, '$[*].model') IS NOT NULL
    //                     AND JSON_CONTAINS(JSON_EXTRACT(model_fields, '$[*].condition'), JSON_ARRAY(?))",
    //                     [$modelValue, $cond]
    //                 );
    //             }
    //         }

    //         // ✅ Additional filtering from model_fields if "field" is provided (acts as model)
    //         if ($field && $conditionValue) {
    //             $values = is_array($conditionValue) ? $conditionValue : [$conditionValue];
    //             foreach ($values as $val) {
    //                 $query->whereRaw(
    //                     "JSON_SEARCH(model_fields, 'one', ?, NULL, '$[*].model') IS NOT NULL
    //                     AND JSON_CONTAINS(JSON_EXTRACT(model_fields, '$[*].condition'), JSON_ARRAY(?))",
    //                     [$field, $val]
    //                 );
    //             }
    //         }

    //         // ✅ Filter by field_type
    //         if ($fieldType) {
    //             $query->where('field_type', $fieldType);
    //         }

    //         // ✅ Search filtering
    //         if ($searchQuery) {
    //             $query->where(function ($q) use ($searchQuery) {
    //                 $q->where('field_label', 'LIKE', "%{$searchQuery}%")
    //                     ->orWhere('field_name_slug', 'LIKE', "%{$searchQuery}%")
    //                     ->orWhere('field_type', 'LIKE', "%{$searchQuery}%")
    //                     ->orWhere('group_name.group_name', 'LIKE', "%{$searchQuery}%");
    //             });
    //         }

    //         // ✅ Sorting
    //         if ($sortField && in_array($sortField, ['field_type', 'group_name', 'field_label'])) {
    //             if ($sortField === 'group_name') {
    //                 $query->orderBy('group_name.group_name', $sortOrder);
    //             } else {
    //                 $query->orderBy($sortField, $sortOrder);
    //             }
    //         }

    //         // ✅ Paginate
    //         $results = $query->paginate($perPage);

    //         return response()->json([
    //             'message' => 'Filtered results retrieved successfully',
    //             'data' => $results->items(),
    //             'pagination' => [
    //                 'current_page' => $results->currentPage(),
    //                 'last_page' => $results->lastPage(),
    //                 'per_page' => $results->perPage(),
    //                 'total' => $results->total(),
    //             ],
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function searchAndFilter(Request $request)
{
    try {
        $dropdownValue = $request->input('dropdown_value');
        $searchQuery = $request->input('search');
        $modelConditions = $request->input('model_conditions'); // Array of {model, condition}
        $fieldType = $request->input('field_type');
        $sortField = $request->input('sort_field');
        $sortOrder = $request->input('sort_order');
        $perPage = $request->input('per_page', 10);

        $query = CustomField::query()
            ->join('group_name', 'custom_fields.group_id', '=', 'group_name.id')
            ->select('custom_fields.*', 'group_name.group_name');

        // ✅ Group filter
        if ($dropdownValue) {
            $groupId = DB::table('group_name')->where('group_name', $dropdownValue)->value('id');
            if ($groupId) {
                $query->where('group_id', $groupId);
            }
        }

        // ✅ Filter by model_conditions array
        if (!empty($modelConditions) && is_array($modelConditions)) {
            $query->where(function($q) use ($modelConditions) {
                foreach ($modelConditions as $item) {
                    $model = $item['model'] ?? null;
                    $conditions = $item['condition'] ?? [];
                    if ($model && !empty($conditions)) {
                        foreach ($conditions as $cond) {
                            $q->orWhereRaw(
                                "JSON_SEARCH(model_fields, 'one', ?, NULL, '$[*].model') IS NOT NULL
                                 AND JSON_CONTAINS(JSON_EXTRACT(model_fields, '$[*].condition'), JSON_ARRAY(?))",
                                [$model, $cond]
                            );
                        }
                    }
                }
            });
        }

        // ✅ Field type filter
        if ($fieldType) {
            $query->where('field_type', $fieldType);
        }

        // ✅ Search filter
        if ($searchQuery) {
            $query->where(function ($q) use ($searchQuery) {
                $q->where('field_label', 'LIKE', "%{$searchQuery}%")
                    ->orWhere('field_name_slug', 'LIKE', "%{$searchQuery}%")
                    ->orWhere('field_type', 'LIKE', "%{$searchQuery}%")
                    ->orWhere('group_name.group_name', 'LIKE', "%{$searchQuery}%");
            });
        }

        // ✅ Sorting
        if ($sortField && in_array($sortField, ['field_label', 'field_type', 'group_name'])) {
            if ($sortField === 'group_name') {
                $query->orderBy('group_name.group_name', $sortOrder ?? 'asc');
            } else {
                $query->orderBy($sortField, $sortOrder ?? 'asc');
            }
        }

        // ✅ Paginate
        $results = $query->paginate($perPage);

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
        return response()->json([
            'error' => $e->getMessage(),
        ], 500);
    }
}



    ################## end new code ############

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
                'Purposes' => Purpose::all(),
                'Properties' => Property::all(),
                'Property Types' => PropertyType::all(),
                'Property Statuses' => Status::all(),
                //'Amenities' => Amenity::all(),
                //'Amenities Categories' => AmenitiesCategory::all(),
            ];

            // Define slug mappings
            $slugMap = [
                'Purposes' => 'purpose',
                'Properties' => 'property',
                'Property Types' => 'property_type',
                'Property Statuses' => 'property_status',
                //'Amenities' => 'amenity',
                //'Amenities Categories' => 'amenities_category',
            ];


            // Build the response data dynamically
            $data = [];
            foreach ($models as $name => $dataset) {
                $options = $dataset->map(function ($item) {
                    return [
                        'value' => $item->id,
                        'label' => $item->name, // Assuming the model has `id` and `name` fields
                        'slug' => $item->slug ?? null, // Include slug if available
                    ];
                });

                $data[] = [
                    'label' => $name,
                     'slug' => $slugMap[$name] ?? Str::snake($name), 
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


    ########## delete by custom field id 14-07-2025  ##########

    public function deleteCustomFieldById(Request $request)
    {
        try {
            // Validate the request
            $request->validate([
                'custom_field_id' => 'required|integer',
            ]);

            $customFieldId = $request->custom_field_id;

            // Fetch the CustomField by ID
            $customField = CustomField::find($customFieldId);
            if (!$customField) {
                return response()->json(['error' => 'Invalid Custom Field ID'], 200);
            }

            // Delete associated CustomFieldRepeaterOptions
            $customFieldRepeaterIds = CustomFieldRepeater::where('custom_field_id', $customFieldId)->pluck('id');
            CustomFieldRepeaterOption::whereIn('custom_field_repeater_id', $customFieldRepeaterIds)->delete();

            // Delete associated CustomFieldRepeaters
            CustomFieldRepeater::where('custom_field_id', $customFieldId)->delete();

            // Delete associated CustomFieldOptions
            CustomFieldOption::where('custom_field_id', $customFieldId)->delete();

            // Delete the CustomField itself
            $customField->delete();

            return response()->json(['message' => 'CustomField and related data deleted successfully.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('Error in deleteByCustomFieldId: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }

    ########## bulk delete by custom field id 14-07-2025  ##########

    public function bulkDeleteCustomFieldByIds(Request $request)
    {
        try {
            // Validate the input
            $request->validate([
                'custom_field_ids' => 'required|array|min:1',
                'custom_field_ids.*' => 'integer'
            ]);

            $customFieldIds = $request->custom_field_ids;

            // Fetch only existing CustomField IDs
            $existingIds = CustomField::whereIn('id', $customFieldIds)->pluck('id');

            if ($existingIds->isEmpty()) {
                return response()->json(['error' => 'No valid Custom Field IDs found'], 200);
            }

            // Get all repeater IDs linked to these fields
            $customFieldRepeaterIds = CustomFieldRepeater::whereIn('custom_field_id', $existingIds)->pluck('id');

            // Delete associated data
            CustomFieldRepeaterOption::whereIn('custom_field_repeater_id', $customFieldRepeaterIds)->delete();
            CustomFieldRepeater::whereIn('custom_field_id', $existingIds)->delete();
            CustomFieldOption::whereIn('custom_field_id', $existingIds)->delete();
            CustomField::whereIn('id', $existingIds)->delete();

            return response()->json([
                'message' => 'CustomFields and related data deleted successfully.',
                'deleted_ids' => $existingIds
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('Bulk delete error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong during bulk delete.'], 500);
        }
    }




}
