<?php

namespace App\Http\Controllers;

use App\Models\AmenitiesCategory;
use App\Models\Amenity;
use App\Models\CustomField;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Purpose;
use App\Models\Status;
use Illuminate\Http\Request;
use Log;

class CustomMultipleFieldController extends Controller
{
    public function customFieldListingByModelConditionId(Request $request)
{
    $postType = $request->post_type && in_array($request->post_type, ['project', 'developer_list', 'property_list']) 
        ? $request->post_type 
        : 'property_list';

    try {
        $validatedData = $request->validate([
            'model' => 'required',
            'condition_id' => 'required', // condition_id is still required
        ]);

        $model = $request->model;
        $conditionIds = explode(',', $request->condition_id); // Split comma-separated condition IDs

        $customFields = CustomField::where('model', $model)
            ->with(['groupname', 'options', 'repeaterFields.repeaterFieldsOptions'])
            ->where('post_type', $postType)
            ->get()
            ->filter(function ($customField) use ($conditionIds) {
                if (!isset($customField->condition) || empty($customField->condition)) {
                    return false;
                }

                $conditionsArray = json_decode($customField->condition, true);

                // Check if any condition_id in the array matches
                return !empty(array_intersect($conditionIds, $conditionsArray));
            })
            ->map(function ($customField) {
                return [
                    'id' => $customField->id,
                    'group_data' => [
                        'id' => $customField->groupname->id ?? null,
                        'group_name' => $customField->groupname->group_name ?? null,
                        'created_at' => optional($customField->groupname->created_at)->toISOString(),
                        'updated_at' => optional($customField->groupname->updated_at)->toISOString(),
                        'status' => $customField->groupname->status ?? null,
                    ],
                    'field_label' => $customField->field_label,
                    'field_name' => $customField->field_name,
                    'field_placeholder' => $customField->field_placeholder,
                    'field_type' => $customField->field_type,
                    'post_type' => $customField->post_type,
                    'media_limit' => $customField->media_limit,
                    'media_size' => $customField->media_size,
                    'media_format' => $customField->media_format,
                    'checkbox_type' => $customField->checkbox_type,
                    'required' => $customField->required,
                    'options' => $customField->options,
                    'model' => $customField->model,
                    // 'condition' => $customField->condition ? json_decode($customField->condition) : null,

                    // 'condition' => [
                    //     'id' => $customField->groupname->id ?? null,
                    //     'group_name' => $customField->groupname->group_name ?? null,
                    //     'created_at' => optional($customField->groupname->created_at)->toISOString(),
                    //     'updated_at' => optional($customField->groupname->updated_at)->toISOString(),
                    //     'status' => $customField->groupname->status ?? null,
                    // ],
                    'condition' => $customField->groupname ? [
                            'id' => $customField->groupname->id ?? null,
                            'group_name' => $customField->groupname->group_name ?? null,
                            'created_at' => optional($customField->groupname->created_at)->toISOString(),
                            'updated_at' => optional($customField->groupname->updated_at)->toISOString(),
                            'status' => $customField->groupname->status ?? null,
                        ] : [],

                    // 'condition' => $customField->condition ? json_decode($customField->condition, true) : null,
                    'repeater_fields' => $customField->repeaterFields->map(function ($repeaterField) {
                        return [
                            'id' => $repeaterField->id,
                            'field_label' => $repeaterField->field_label,
                            'options' => $repeaterField->repeaterFieldsOptions,
                        ];
                    }),
                ];
            });

        return response()->json([
            'data' => $customFields->values(),
        ], 200);

    } catch (\Exception $e) {
        Log::error('Error: ' . $e->getMessage());
        return response()->json(['error' => 'Something went wrong. ' . $e->getMessage()], 500);
    }
}

    // public function customFieldListingByModelConditionId(Request $request)
    // {
    //     $postType = null;
    
    //     if (isset($request->post_type)) {
    //         $postType = in_array($request->post_type, ['project', 'developer_list', 'property_list']) ? $request->post_type : 'property_list';
    //     } else {
    //         $postType = 'property_list';
    //     }
    
    //     try {
    //         $validatedData = $request->validate([
    //             'model' => 'required',
    //             'condition_id' => 'required', // condition_id is still required
    //         ]);
    
    //         $model = $request->model;
    //         $conditionIds = explode(',', $request->condition_id); // Split comma-separated condition IDs
    
    //         // Retrieve all records matching the model
    //         $customFields = CustomField::where('model', $model)
    //             ->with(['groupname', 'options', 'repeaterFields.repeaterFieldsOptions'])
    //             ->where('post_type', $postType)
    //             ->get()
    //             ->filter(function ($customField) use ($conditionIds) {
    //                 if (!isset($customField->condition) || empty($customField->condition)) {
    //                     return false;
    //                 }
    
    //                 $conditionsArray = json_decode($customField->condition, true);
    
    //                 // Check if any condition_id in the array matches
    //                 return !empty(array_intersect($conditionIds, $conditionsArray));
    //             })
    //             ->groupBy('groupname.id') // Group custom fields by groupname.id
    //             ->map(function ($groupedCustomFields, $groupId) {
    //                 $groupData = $groupedCustomFields->first()->groupname; // Get the group data from the first custom field in the group
    //                 return [
    //                     'group_data' => [
    //                         'id' => $groupData->id,
    //                         'group_name' => $groupData->group_name,
                            
    //                         'created_at' => $groupData->created_at->toISOString(),
    //                         'updated_at' => $groupData->updated_at->toISOString(),
    //                         'status' => $groupData->status,
    //                     ],
    //                     'custom_field_counts' => $groupedCustomFields->count(), // Count of custom fields in this group
    //                     'custom_fields' => $groupedCustomFields->map(function ($customField) {
    //                         return [
    //                             'id' => $customField->id,
    //                             'group_data' => $customField->groupname,
    //                             'field_label' => $customField->field_label,
    //                             'field_name' => $customField->field_name,
    //                             'field_placeholder' => $customField->field_placeholder,
    //                             //'field_name_description' => $customField->field_name_description,
    //                             'field_type' => $customField->field_type,
    //                             'post_type' => $customField->post_type,
    //                             'media_limit' => $customField->media_limit,
    //                             'media_size' => $customField->media_size,
    //                             'media_format' => $customField->media_format,
    //                             'checkbox_type' => $customField->checkbox_type,
    //                             'required' => $customField->required,
    //                             'options' => $customField->options,
    //                             'model' => $customField->model,
    //                             'created_at' => $customField->created_at->toISOString(),
    //                             'updated_at' => $customField->updated_at->toISOString(),
    //                             'propertyCount' => $customField->repeaterFields->count(), // Count of repeater fields (property count)
    //                         ];
    //                     }),
    //                 ];
    //             })
    //             ->values(); // Reindex the collection
    
    //         return response()->json($customFields, 200);
    
    //     } catch (\Exception $e) {
    //         // Log and return generic error response
    //         Log::error('Error: ' . $e->getMessage());
    //         return response()->json(['error' => 'Something went wrong. ' . $e->getMessage()], 500);
    //     }
    // }
    
//     public function customFieldListingByModelConditionId(Request $request)
//     {
//         $postType = null;
    
//         if (isset($request->post_type)) {
//             $postType = in_array($request->post_type, ['project', 'developer_list', 'property_list']) ? $request->post_type : 'property_list';
//         } else {
//             $postType = 'property_list';
//         }
    
//         try {
//             $validatedData = $request->validate([
//                 'model' => 'required',
//                 'condition_id' => 'required',
//             ]);
    
//             $model = $request->model;
//             $conditionId = explode(',', $request->condition_id);
    
//             // Retrieve all records matching the model
//             $customFields = CustomField::where('model', $model)
//                 ->with(['groupname', 'options', 'repeaterFields.repeaterFieldsOptions'])
//                 ->where('post_type', $postType)
//                 ->get()
//                 ->filter(function ($customField) use ($conditionId) {
//                     if (!isset($customField->condition) || empty($customField->condition)) {
//                         return false;
//                     }
    
//                     $conditionsArray = json_decode($customField->condition, true);
//                     return in_array($conditionId, $conditionsArray);
//                 })
//                 ->map(function ($customField) use ($model, $conditionId) {
//                     if (!isset($customField->groupname) || empty($customField->groupname)) {
//                         $customField->groupname = null;
//                     }
    
//                     if (!isset($customField->options) || empty($customField->options)) {
//                         $customField->options = null;
//                     }
    
//                     if (!isset($customField->repeaterFields) || empty($customField->repeaterFields)) {
//                         $customField->repeaterFields = collect();
//                     }
    
//                     $modelConditionName = $this->fetchModelConditionName($model, $conditionId);
//                     $repeaterFieldsData = $customField->repeaterFields->map(function ($repeaterField) {
//                         if (!isset($repeaterField->repeaterFieldsOptions) || empty($repeaterField->repeaterFieldsOptions)) {
//                             $repeaterField->repeaterFieldsOptions = null;
//                         }
    
//                         return [
//                             'id' => $repeaterField->id,
//                             'custom_field_id' => $repeaterField->custom_field_id,
//                             'field_label' => $repeaterField->field_label,
//                             'field_name' => $repeaterField->field_name,
//                             'field_type' => $repeaterField->field_type,
//                             'options' => $repeaterField->repeaterFieldsOptions,
//                         ];
//                     });
    
//                     return [
//                         'id' => $customField->id,
//                         'group_data' => $customField->groupname,
//                         'field_label' => $customField->field_label,
//                         'field_name' => $customField->field_name,
//                         'field_placeholder' => $customField->field_placeholder,
//                         //'field_name_description' => $customField->field_name_description,
//                         'field_type' => $customField->field_type,
//                         'post_type' => $customField->post_type,
//                         'media_limit' => $customField->media_limit,
//                         'media_size' => $customField->media_size,
//                         'media_format' => $customField->media_format,
//                         'checkbox_type' => $customField->checkbox_type,
//                         'required' => $customField->required,
//                         'options' => $customField->options,
//                         'model' => $customField->model,
//                         'condition' => $modelConditionName,
//                         'repeater_fields' => $repeaterFieldsData,
//                     ];
//                 });
    
//             return response()->json(['data' => $customFields], 200);
    
//         } catch (\Exception $e) {
//             // Log and return generic error response
//             Log::error('Error: ' . $e->getMessage());
//             return response()->json(['error' => 'Something went wrong.' . $e->getMessage()], 500);
//         }
// }


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

}


