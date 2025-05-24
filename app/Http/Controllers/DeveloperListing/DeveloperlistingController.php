<?php

namespace App\Http\Controllers\DeveloperListing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Developerlist;
use App\Models\User;
use App\Models\Status;
use App\Models\Amenity;
use App\Models\PropertyType;
use App\Models\Location;
use App\Models\Customfieldvalue;
use App\Models\CustomFieldRepeaterValues; 
use App\Models\AmenitiesCategory;
use App\Models\Keyword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;


class DeveloperlistingController extends Controller{
    
    
      
    public function store(Request $request)
{
    try {
        // Check if token is provided and validate if it's in the users table
        $userToken = $request->header('Authorization'); // Assuming the token is passed in the Authorization header as 'Bearer {token}'
        if (!$userToken || !str_starts_with($userToken, 'Bearer ')) {
            return response()->json(['error' => 'API token is required'], 422);
        }

        $token = substr($userToken, 7); // Extract the token from 'Bearer {token}'

        // Find the user associated with the token
        $user = User::where('api_token', $token)->first();
        if (!$user) {
            return response()->json(['error' => 'Invalid API token'], 401);
        }

        // Get the user ID
        $userId = $user->id;

        // ✅ Ensure `live_status` is set to "Under Review" by default
        if (!$request->has('live_status') || empty($request->live_status)) {
            $request->merge(['live_status' => 'Under Review']);
        }

        // Validate the request
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'country_id' => 'nullable|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'nullable|exists:cities,id',
            'purpose_id' => 'nullable',
            'property_id' => 'nullable',
            'property_status_id' => 'nullable',
            'property_type_id' => 'nullable',
            'live_status' => 'nullable|in:Approve,Disapprove,Reject,Under Review,Modify Review',
            'status_reason' => $request->live_status === 'Reject' ? 'required|string|max:500' : 'nullable',
            
        ]);

        // Ensure `status_reason` is null if live_status is not "Reject"
        $validatedData['status_reason'] = $request->live_status === 'Reject' ? $request->status_reason : null;

        // Generate unique developer ID
        $developer_unique_id = 'Developer' . rand(111111, 999999);

        // Prepare developer data
        $developerData = array_merge($validatedData, [
            'developer_unique_id' => $developer_unique_id,
            'user_id' => $userId,
            'created_by' => $userId, // Store the authenticated user's ID
            'address' => $request->address,
        ]);

        // ✅ Create the developer listing FIRST
        $project = Developerlist::create($developerData);

        // ✅ Handle repeater fields AFTER the project is created
        if ($request->has('repeater_fields')) {
            foreach ($request->repeater_fields as $repeaterField) {
                $customFieldData = [
                    'developer_listing_id' => $project->id, // ✅ FIXED: Use `developer_listing_id` instead of `properties_listing_id`
                    'custom_field_id' => $repeaterField['custom_field_id'],
                ];

                switch ($repeaterField['field_type']) {
                    case 'text':
                    case 'textarea':
                    case 'texteditor':
                        $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                        break;

                    case 'select':
                    case 'radio':
                        $customFieldOption = DB::table('custom_field_options')
                            ->where('custom_field_id', $repeaterField['custom_field_id'])
                            ->where('value', $repeaterField['field_value'])
                            ->first();
                        if ($customFieldOption) {
                            $customFieldData['custom_field_options_id'] = $customFieldOption->id;
                        }
                        $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                        break;

                    case 'checkbox':
                        $values = explode(',', $repeaterField['field_value']);
                        $customFieldData['field_meta_value'] = implode(',', $values);
                        $customFieldOptionsIds = DB::table('custom_field_options')
                            ->whereIn('value', $values)
                            ->where('custom_field_id', $repeaterField['custom_field_id'])
                            ->pluck('id')
                            ->implode(',');
                        $customFieldData['custom_field_options_id'] = $customFieldOptionsIds;
                        break;

                    case 'media':
                        if ($request->hasFile("repeater_fields.{$repeaterField['custom_field_id']}.field_value")) {
                            $files = $request->file("repeater_fields.{$repeaterField['custom_field_id']}.field_value");
                            $fileNames = [];
                            foreach ($files as $file) {
                                if ($file->isValid()) {
                                    $fileName = time() . '_' . $file->getClientOriginalName();
                                    $file->move(public_path('uploads/media'), $fileName);
                                    $fileNames[] = $fileName;
                                }
                            }
                            $customFieldData['field_meta_value'] = json_encode($fileNames);
                        }
                        break;

                    case 'file':
                        if ($request->hasFile("repeater_fields.{$repeaterField['custom_field_id']}.field_value")) {
                            $file = $request->file("repeater_fields.{$repeaterField['custom_field_id']}.field_value");
                            if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                                $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                                $customFieldData['field_meta_value'] = $filePath;
                            } else {
                                return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
                            }
                        }
                        break;
                }

                Customfieldvalue::create($customFieldData);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Data added successfully.',
            'data' => $project
        ], 201);

    } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage()], 500);
    }
}


    
        // this is for listing
        public function index(Request $request)
        {
            
            try {
                $baseURL = config('app.url');
                $basePath = public_path();
        
                // Fetch only listings where live_status is "Approve"
                $projects = Developerlist::where('live_status', 'Approve')
                    ->with([
                        'user', 
                        'propertyType', 
                        'purpose', 
                        'property', 
                        'propertystatus', 
                        'customFieldValues.customField', 
                        'customFieldValues.customFieldOption',
                        'country', // Add country relationship
                        'state',   // Add state relationship
                        'city',    // Add city relationship
                        'createdBy.role', // ✅ Include creator's role
                    'updatedBy.role'  // ✅ Include updater's role
                    ])
                    ->get();
                
                $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                    $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                        $customField = $customFieldValue->customField;
                        
                        $fieldValue = $customFieldValue->field_meta_value;
                        if ($customField && $customField->field_type == 'checkbox') {
                            $fieldValueArray = explode(',', $fieldValue);
                        } elseif ($customField && $customField->field_type == 'media') {
                            $fieldValueArray = json_decode($fieldValue);
                            $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                                return $baseURL . '/uploads/media/' . $file;
                            });
                        } else {
                            $fieldValueArray = $fieldValue;
                        }
            
                        return [
                            'custom_field_id' => $customField ? $customField->id : null,
                            'field_type' => $customField ? $customField->field_type : null,
                            'field_value' => $fieldValueArray,
                            'field_name' => $customField ? $customField->field_name : null,
                        ];
                    });
            
                    // Prepare property data
                    return [
                        'id' => $property->id,
                        'developer_unique_id' => $property->developer_unique_id,
                        'name' => $property->name,
                        'description' => $property->description,
                        'live_status' => $property->live_status,
                        'temporary_status' => $property->temporary_status,
                        'status_reason' => $property->status_reason,
                        'user_id' => $property->user_id,
                        'created_by' => $property->created_by,
                        'created_by_role' => optional(optional($property->createdBy)->role)->name,
                        'updated_by' => $property->updated_by,
                        'updated_by_role' => optional(optional($property->updatedBy)->role)->name,
                        'listed_by' => optional(optional($property->user)->role)->name,
                        'purpose_id' => $property->purpose_id,
                        'purpose_id_name' => optional($property->purpose)->name,
                        'property_id' => $property->property_id,
                        'property_id_name' => optional($property->property)->name,
                        'property_status_id' => $property->property_status_id,
                        'property_status_id_name' => optional($property->propertystatus)->name,
                        'property_type_id' => $property->property_type_id,
                        'property_type_id_name' => optional($property->propertyType)->name,
                        
                        // Newly added fields
                        'country_id' => $property->country_id,
                        'country_name' => optional($property->country)->name,
                        'state_id' => $property->state_id,
                        'state_name' => optional($property->state)->name,
                        'city_id' => $property->city_id,
                        'city_name' => optional($property->city)->name,
        
                        'date' => date('d m Y', strtotime($property->created_at)),
                        'time' => date('h:i A', strtotime($property->created_at)),
                        'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
                        'custom_field_values' => $formattedCustomFieldValues,
                    ];
                });
                
                return response()->json($projectsData);
            } catch (\Throwable $th) {
                return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
            }
        }
        

        public function indexByAdmin(Request $request)
        {
            try {
                $baseURL = config('app.url');
                $basePath = public_path();
        
                // Fetch all listings without filtering live_status
                $projects = Developerlist::with([
                    'user', 
                    'propertyType', 
                    'purpose', 
                    'property', 
                    'propertystatus', 
                    'customFieldValues.customField', 
                    'customFieldValues.customFieldOption',
                    'country', 
                    'state',  
                    'city',
                    'createdBy.role', // ✅ Include creator's role
                    'updatedBy.role'  // ✅ Include updater's role
                ])->get();
                
                $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                    $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                        $customField = $customFieldValue->customField;
                        
                        $fieldValue = $customFieldValue->field_meta_value;
                        if ($customField && $customField->field_type == 'checkbox') {
                            $fieldValueArray = explode(',', $fieldValue);
                        } elseif ($customField && $customField->field_type == 'media') {
                            $fieldValueArray = json_decode($fieldValue);
                            $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                                return $baseURL . '/uploads/media/' . $file;
                            });
                        } else {
                            $fieldValueArray = $fieldValue;
                        }
        
                        return [
                            'custom_field_id' => $customField ? $customField->id : null,
                            'field_type' => $customField ? $customField->field_type : null,
                            'field_value' => $fieldValueArray,
                            'field_name' => $customField ? $customField->field_name : null,
                        ];
                    });
        
                    return [
                        'id' => $property->id,
                        'developer_unique_id' => $property->developer_unique_id,
                        'name' => $property->name,
                        'description' => $property->description,
                        'live_status' => $property->live_status,
                        'temporary_status' => $property->temporary_status,
                        'status_reason' => $property->status_reason,
                        'user_id' => $property->user_id,
                        'listed_by' => optional(optional($property->user)->role)->name,
        
                        'created_by' => $property->created_by,
                        'created_by_role' => optional(optional($property->createdBy)->role)->name, // ✅ Creator role
                        'updated_by' => $property->updated_by,
                        'updated_by_role' => optional(optional($property->updatedBy)->role)->name, // ✅ Updater role
        
                        'purpose_id' => $property->purpose_id,
                        'purpose_id_name' => optional($property->purpose)->name,
                        'property_id' => $property->property_id,
                        'property_id_name' => optional($property->property)->name,
                        'property_status_id' => $property->property_status_id,
                        'property_status_id_name' => optional($property->propertystatus)->name,
                        'property_type_id' => $property->property_type_id,
                        'property_type_id_name' => optional($property->propertyType)->name,
        
                        'country_id' => $property->country_id,
                        'country_name' => optional($property->country)->name,
                        'state_id' => $property->state_id,
                        'state_name' => optional($property->state)->name,
                        'city_id' => $property->city_id,
                        'city_name' => optional($property->city)->name,
        
                        'date' => date('d m Y', strtotime($property->created_at)),
                        'time' => date('h:i A', strtotime($property->created_at)),
                        'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
        
                        'custom_field_values' => $formattedCustomFieldValues,
                    ];
                });
        
                return response()->json($projectsData);
            } catch (\Throwable $th) {
                return response()->json(['error' => $th->getMessage()], 500);
            }
        }
        

        // this is for update the record 
        public function update(Request $request)
        {
            try {
                $userId = auth()->id();  // Using the authenticated user ID
        
                // Automatically set live_status for non-admin users
                $userData = auth()->user();
                if ($userData->role->name != 'admin') {
                    $request->request->add(['live_status' => 'Modify Review']);
                }
        
                // Validate that status_reason is required when rejecting
                if ($request->live_status == 'reject' && !$request->status_reason) {
                    return response()->json(['error' => 'Status reason is required when status is reject.'], 422);
                }
        
                // Remove status_reason if live_status is not 'reject'
                if ($request->live_status != 'reject') {
                    $request->request->add(['status_reason' => null]);
                }
        
                // Validate ID
                $id = $request->id;
                $project = Developerlist::find($id);
                if (!$project) {
                    return response()->json(['error' => 'Invalid Developer Id'], 404);
                }
        
                // Handle file uploads
                $fileFields = ['property_video', 'virtual_tour', 'video_thumbnail', 'featured_image', 'brochure'];
                foreach ($fileFields as $fileField) {
                    if ($request->hasFile($fileField)) {
                        $file = $request->file($fileField);
                        $fileName = time() . '_' . $file->getClientOriginalName();
                        $file->move(public_path('uploads/' . $fileField), $fileName);
                        $project->$fileField = $fileName;
                    }
                }
        
                // Update project data and store updated_by
                $project->update([
                    'name' => $request->name,
                    'description' => $request->description,
                    'country_id' => $request->country_id,
                    'state_id' => $request->state_id,
                    'city_id' => $request->city_id,
                    'purpose_id' => $request->purpose_id,
                    'property_id' => $request->property_id,
                    'property_status_id' => $request->property_status_id,
                    'property_type_id' => $request->property_type_id,
                    'live_status' => $request->live_status,
                    'status_reason' => $request->status_reason,
                    'updated_by' => $userId,  // Store the user ID in the updated_by field
                    'address' => $request->address,
                ]);
        
                // Handle repeater fields (custom fields)
                if ($request->has('repeater_fields')) {
                    Customfieldvalue::where('project_listing_id', $project->id)->delete(); // Clear existing
        
                    foreach ($request->repeater_fields as $repeaterField) {
                        $customFieldData = [
                            'project_listing_id' => $project->id,
                            'custom_field_id' => $repeaterField['custom_field_id'],
                            'field_meta_value' => $repeaterField['field_value'],
                        ];
        
                        // Handle different field types
                        if (in_array($repeaterField['field_type'], ['checkbox', 'select', 'radio'])) {
                            $customFieldOptions = DB::table('custom_field_options')
                                ->where('custom_field_id', $repeaterField['custom_field_id'])
                                ->where('value', $repeaterField['field_value'])
                                ->first();
        
                            if ($customFieldOptions) {
                                $customFieldData['custom_field_options_id'] = $customFieldOptions->id;
                            }
                        }
        
                        if ($repeaterField['field_type'] === 'media') {
                            $mediaFiles = [];
                            if (is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $value) {
                                    if (is_string($value)) {
                                        $mediaFiles[] = $value;
                                    } else {
                                        if ($value->isValid()) {
                                            $fileName = time() . '_' . $value->getClientOriginalName();
                                            $value->move(public_path('uploads/media'), $fileName);
                                            $mediaFiles[] = $fileName;
                                        } else {
                                            return response()->json(['error' => 'Invalid media file.'], 400);
                                        }
                                    }
                                }
                            }
                            $customFieldData['field_meta_value'] = json_encode($mediaFiles);
                        }
        
                        if ($repeaterField['field_type'] === 'file') {
                            if ($request->hasFile('repeater_fields.' . $repeaterField['custom_field_id'] . '.field_value')) {
                                $file = $request->file('repeater_fields.' . $repeaterField['custom_field_id'] . '.field_value');
                                if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                                    $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                    $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                                    $customFieldData['field_meta_value'] = $filePath;
                                } else {
                                    return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
                                }
                            }
                        }
        
                        Customfieldvalue::create($customFieldData);
                    }
                }
        
                return response()->json(['status' => true, 'message' => 'Data updated successfully.'], 200);
            } catch (\Throwable $th) {
                return response()->json(['error' => $th->getMessage()], 500);
            }
        }
        

    
        // this is for delete the record
        public function destroy(Request $request)
    {

        if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
        }
        
        $requestToken = $request->header('api-token');
        
        try {
        $id = $request->id;
        $project = Developerlist::find($id);
        
        if (!$project) {
        return response()->json(['message' => 'No Developer found'], 404);
        }
        
        // Delete specific related records
        $project->customFieldValues()->delete();
        
        // Delete the project
        $project->delete();
        
        $returnRes = [
        'status' => true,
        'message' => 'Data deleted successfully.'
        ];
        
        return response()->json($returnRes,200);
        } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage()], 500);
        }
    }
        
    public function updateDeveloperStatus(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'developer_unique_id' => 'required|exists:developer_listings,developer_unique_id',  // Ensure the property exists in the table
                'live_status' => 'required|string|in:Approve,Disapprove,Reject,Under Review,Modify Review',  // Valid status values
                'status_reason' => 'nullable|string', // status_reason only required when status is 'Reject'
            ]);
            
            // Retrieve the authenticated user from the middleware
            $user = Auth::user(); 
    
            // Check if the user is admin or not
            $isAdmin = $user->role->name === 'admin';
            
            // If the user is not an admin, set the live_status to 'Modify Review'
            if (!$isAdmin) {
                $request->merge(['live_status' => 'Modify Review']); // Update status to Modify Review for non-admin
            }
    
            // Check if the setting record exists
            $propertyData = Developerlist::where('developer_unique_id', $request->developer_unique_id)->first();
            
            if (!$propertyData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid Property Id',
                ], 401);  
            }
    
            // If the status is 'Reject', ensure that 'status_reason' is provided
            if ($request->live_status === 'Reject' && empty($request->status_reason)) {
                return response()->json(['error' => 'status_reason is required when status is reject.'], 422);
            }
    
            // Update the property status
            $propertyData->live_status = $request->live_status;  // Use live_status
            $propertyData->status_reason = $request->status_reason; // status_reason
            $propertyData->updated_by = $user->id;  // Set the updated_by field as the authenticated user's ID
            $propertyData->update();
    
            // Return the success response
            return response()->json([
                'status' => true,
                'message' => 'Status updated successfully',
            ], 201);
    
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Catch validation errors and return response
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            // Catch general exceptions and return response
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
        // this is for get data by id
        public function getdatabyId(Request $request)
        {
            // dd($request->all());
            try {
                if (!$request->id) {
                    return response()->json(['error' => 'ID is required'], 400);
                }
        
                $baseURL = config('app.url');
        
                $project = Developerlist::with([
                    'location', 
                    'user', 
                    'propertyType', 
                    'purpose', 
                    'property', 
                    'propertystatus', 
                    'customFieldValues.customField', 
                    'customFieldValues.customFieldOption',
                    'createdBy.role', 
                    'updatedBy.role'  
                ])->where('id', $request->id)->first(); 
        
                if (!$project) {
                    return response()->json(['error' => 'Project not found'], 404);
                }
        
                // ✅ Handle Created By and Updated By
                $createdByData = $project->createdBy ? [
                    'id' => $project->createdBy->id,
                    'name' => $project->createdBy->name,
                    'email' => $project->createdBy->email,
                    'role' => optional($project->createdBy->role)->name,
                ] : null;
        
                $updatedByData = $project->updatedBy ? [
                    'id' => $project->updatedBy->id,
                    'name' => $project->updatedBy->name,
                    'email' => $project->updatedBy->email,
                    'role' => optional($project->updatedBy->role)->name,
                ] : null;
        
                // ✅ Fetch repeater fields dynamically
                $repeaterFields = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = optional($customFieldValue->customField);
                    $fieldType = $customField->field_type ?? 'unknown';
                    $fieldValue = $customFieldValue->field_meta_value ?? '';
                    $allAvailableOptions = [];
        
                    // ✅ Fetch all available options dynamically
                    $availableOptions = DB::table('custom_field_options')
                        ->where('custom_field_id', $customFieldValue->custom_field_id)
                        ->where('status', 1)
                        ->get(['id', 'name', 'value']);
        
                    if ($availableOptions->isNotEmpty()) {
                        foreach ($availableOptions as $option) {
                            $allAvailableOptions[] = [
                                'name' => $option->name,
                                'value' => $option->value,
                            ];
                        }
                    }
        
                    // ✅ Handle different field types correctly
                    if (in_array($fieldType, ['select', 'radio'])) {
                        $customFieldOption = DB::table('custom_field_options')
                            ->where('id', $customFieldValue->custom_field_options_id)
                            ->where('status', 1)
                            ->first();
                        $fieldValue = optional($customFieldOption)->name;
                    } elseif ($fieldType === 'checkbox') {
                        $optionIds = explode(',', $customFieldValue->custom_field_options_id);
                        $fieldValue = DB::table('custom_field_options')
                            ->whereIn('id', $optionIds)
                            ->where('status', 1)
                            ->pluck('name')
                            ->toArray();
                    } elseif (in_array($fieldType, ['media', 'file'])) {
                        $fieldValue = json_decode($fieldValue, true) ?? [];
                        if (!empty($fieldValue)) {
                            $fieldValue = array_map(fn($fileName) => $baseURL . '/uploads/media/' . $fileName, (array)$fieldValue);
                        }
                    }
        
                    return [
                        'custom_field_id' => $customFieldValue->custom_field_id,
                        'field_label' => $customField->field_name ?? 'Unknown Field',
                        'placeholder' => $customFieldValue->placeholder,
                        'field_type' => $fieldType,
                        'field_value' => $fieldValue,
                        'options' => $allAvailableOptions,
                    ];
                });
        
                return response()->json([
                    'id' => $project->id,
                    'developer_unique_id' => $project->developer_unique_id,
                    'name' => $project->name,
                    'address' => $project->address,
                    'description' => $project->description,
                    'country_id' => $project->country_id,
                    'country_name' => optional($project->country)->name,
                    'state_id' => $project->state_id,
                    'state_name' => optional($project->state)->name,
                    'city_id' => $project->city_id,
                    'city_name' => optional($project->city)->name,
                    'location_id' => $project->location_id,
                    'location_name' => optional($project->location)->name,
                    'property_address' => $project->property_address,
                    'status' => $project->status,
                    'status_reason' => $project->status_reason,
                    'user_id' => $project->user_id,
                    'created_by' => $createdByData,
                    'updated_by' => $updatedByData,
                    'listed_by' => optional(optional($project->user)->role)->name,
                    'purpose_id' => $project->purpose_id,
                    'purpose_id_name' => optional($project->purpose)->name,
                    'property_id' => $project->property_id,
                    'property_id_name' => optional($project->property)->name,
                    'property_status_id' => $project->property_status_id,
                    'property_status_id_name' => optional($project->propertystatus)->name,
                    'property_type_id' => $project->property_type_id,
                    'property_type_id_name' => optional($project->propertyType)->name,
                    'total_view' => 0, // ✅ Removed analytics() call
                    'date' => $project->created_at ? $project->created_at->format('d m Y') : null,
                    'time' => $project->created_at ? $project->created_at->format('h:i A') : null,
                    'timestamp' => $project->created_at ? $project->created_at->format('d m Y h:i A') : null,
                    'repeater_fields' => $repeaterFields,
                ]);
        
            } catch (\Throwable $th) {
                return response()->json(['error' => $th->getMessage()], 500);
            }
        }
        
   // bulk
    public function bulkDelete(Request $request)
    {
        
        if($request->header('api-token')==''){
            return response()->json(['error' => 'Please enter api token first.'], 422);                
        } 
        
        $requestToken = $request->header('api-token');
            
        $expectedToken = config('constants.API_TOKEN');
        
        if ($requestToken !== $expectedToken) {
                return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
        }
        
        try {
        // Find the builder by ID
        $delete_ids = explode(',', $request->id);
        
        foreach($delete_ids as $row){
            $purpose = Developerlist::findOrFail($row);
            // Delete the builder record
            $purpose->customFieldValues()->delete();
            // Delete the property
            $purpose->delete();
        }
        
        // Return a success response
        return response()->json([
        'message' => 'Developer bulk deleted successfully',
        
        ], 200);
        } catch (ModelNotFoundException $e) {
        // Handle model not found errors
        return response()->json(['error' => 'Developer not found'], 404);
        } catch (\Exception $e) {
        // Handle other unexpected errors
        return response()->json(['error' => 'Something went wrong'], 500);
        }
    }
    public function getUserProject(Request $request)
    {
        try {
            // Authenticate the user
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized.'], 401);
            }
    
            // Fetch properties where created_by or updated_by matches the user ID
            $project = Developerlist::where('created_by', $user->id)
                ->orWhere('updated_by', $user->id)
                ->get();
    
            // If no properties found, return empty array
            if ($project->isEmpty()) {    
                return response()->json(['message' => 'No Project found.'], 200);
            }
    
            return response()->json([
                'status' => true,
                'message' => 'Project retrieved successfully.',
                'data' => $project
            ], 200);
    
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function getAllDeveloperByLocationId(Request $request)
{
    try {
        $baseURL = config('app.url');
        $basePath = public_path();
        $projectData = [];

        // Validate: Ensure at least one of country_id, state_id, city_id is provided and not null
        if (
            (!isset($request->country_id) || $request->country_id === null) &&
            (!isset($request->state_id) || $request->state_id === null) &&
            (!isset($request->city_id) || $request->city_id === null)
        ) {
            return response()->json(['error' => 'Please provide at least one of country_id, state_id, or city_id.'], 422);
        }

        // Build query dynamically based on provided parameters
        $query = Developerlist::query();

        if (!empty($request->country_id)) {
            $query->where('country_id', $request->country_id);
        }
        if (!empty($request->state_id)) {
            $query->where('state_id', $request->state_id);
        }
        if (!empty($request->city_id)) {
            $query->where('city_id', $request->city_id);
        }

        // Fetch matching projects
        $projects = $query->get();

        // Return error if no data is found
        if ($projects->isEmpty()) {
            return response()->json(['error' => 'No projects found for the given location.'], 404);
        }

        // Process results
        foreach ($projects as $row) {
            $projectData[] = [
                'id' => $row->id,
                'name' => $row->name,
            ];
        }

        return response()->json($projectData);

    } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage()], 500);
    }
}


// ====================================================


        // this is for store the data
    //     public function storeWebsite(Request $request)
    // {
    //         if ($request->header('api-token') == '') {
    //                 return response()->json(['error' => 'Please enter api token first.'], 422);
    //         }
                
    //         $requestToken = $request->header('api-token');

            
    //         try {
    //             $userId = null;
            
    //             $userData = User::where('api_token', $requestToken)->first();
            
    //             // Validate that the user exists in the database
    //             if (!$userData) {
    //                 return response()->json(['error' => 'User not found'], 404);
    //             }

    //             $userId = $userData->id;
            
    //             $developer_unique_id = 'Developer' . rand(111111, 999999);

    //             if(isset($request->description)){
    //               $description = $request->description;
    //             }else{
    //               $description = null;
    //             }
            
    //             // Upload files to public/uploads/
    //             $developerData = [
    //                 'developer_unique_id' => $developer_unique_id,
    //                 'user_id' => $userId,
    //                 'name' => $request->name,
    //                 'description' => $description,
    //                 'location_id' => $request->location_id,
    //                 'purpose_id' => $request->purpose_id,
    //                 'property_id' => $request->property_id,
    //                 'property_status_id' => $request->property_status_id,
    //                 'property_type_id' => $request->property_type_id,
    //                 'status' => 'approved'
    //             ];
        
    //             // Handle file uploads
    //             foreach (['property_video', 'virtual_tour', 'video_thumbnail', 'featured_image', 'brochure'] as $fileField) {
    //                 if ($request->hasFile($fileField)) {
    //                     $file = $request->file($fileField);
    //                     $fileName = time() . '_' . $file->getClientOriginalName();
    //                     $file->move(public_path('uploads/' . $fileField), $fileName);
    //                     $developerData[$fileField] = $fileName;
    //                 }
    //             }
            
    //             $project = Developerlist::create($developerData);

            
    //             // Handle repeater field values pawan
    //             if ($request->has('repeater_fields')) {
    //                 $repeaterFields = $request->repeater_fields;
        
    //                 foreach ($repeaterFields as $repeaterFieldIndex => $repeaterField) {
    //                     $customFieldData = [
    //                         'project_listing_id' => $project->id,
    //                         'custom_field_id' => $repeaterField['custom_field_id'],
    //                     ];
        
    //                     // Check the field type and handle accordingly
    //                     switch ($repeaterField['field_type']) {
    //                         case 'text':
    //                         case 'textarea':
    //                         case 'texteditor':
    //                             $customFieldData['field_meta_value'] = $repeaterField['field_value'];
    //                             break;
    //                         case 'select':
    //                         case 'radio':
    //                             // Retrieve the custom_field_options_id from the custom_field_options table
    //                             $customFieldOptions = DB::table('custom_field_options')
    //                                 ->where('custom_field_id', $repeaterField['custom_field_id'])
    //                                 ->where('value', $repeaterField['field_value'])
    //                                 ->first();
        
    //                             if ($customFieldOptions) {
    //                                 $customFieldData['custom_field_options_id'] = $customFieldOptions->id;
    //                             }
    //                             $customFieldData['field_meta_value'] = $repeaterField['field_value'];
    //                             break;
    //                         case 'checkbox':
    //                             // For checkbox, assume multiple values can be selected
    //                             $values = explode(',', $repeaterField['field_value']); // Split the string into an array
        
    //                             // Convert array to string
    //                             $customFieldData['field_meta_value'] = implode(',', $values);
        
    //                             // Retrieve custom_field_options_id for each selected value
    //                             $customFieldOptionsIds = [];
    //                             foreach ($values as $value) {
    //                                 $customFieldOption = DB::table('custom_field_options')
    //                                     ->where('custom_field_id', $repeaterField['custom_field_id'])
    //                                     ->where('value', $value)
    //                                     ->first();
        
    //                                 if ($customFieldOption) {
    //                                     $customFieldOptionsIds[] = $customFieldOption->id;
    //                                 }
    //                             }
    //                             $customFieldData['custom_field_options_id'] = implode(',', $customFieldOptionsIds);
    //                             break;
    //                         case 'media':
    //                             // Handling for media field
    //                             if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
    //                                 $mediaArray = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');
    //                                 $mediaFiles = [];
        
    //                                 foreach ($mediaArray as $media) {
    //                                     // Ensure each file is valid and handle accordingly
    //                                     if ($media->isValid()) {
    //                                         $fileName = time() . '_' . $media->getClientOriginalName();
    //                                         $media->move(public_path('uploads/media'), $fileName);
    //                                         $mediaFiles[] = $fileName;
    //                                     } else {
    //                                         // Handle invalid files
    //                                         return response()->json(['error' => 'Invalid media file.'], 400);
    //                                     }
    //                                 }
        
    //                                 // Store the file paths array in the 'field_meta_value'
    //                                 $customFieldData['field_meta_value'] = json_encode($mediaFiles);
    //                             } else {
    //                                 // Handle case where 'field_value' is not set
    //                                 return response()->json(['error' => 'No media files uploaded or invalid file data.'], 400);
    //                             }
    //                             break;
    //                         case 'file':
    //                             // Handling for file field
    //                             $filePath = '';
                                
    //                             // Ensure the 'field_value' is set and it's an array
    //                             if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
    //                                 // Retrieve the uploaded file object
    //                                 $file = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');
                                    
    //                                 // Ensure the file is a valid PDF
    //                                 if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
    //                                     // Generate unique filename for the file
    //                                     $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                        
    //                                     // Move the file to the uploads/gallery directory
    //                                     $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
    //                                 } else {
    //                                     return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
    //                                 }
    //                             } else {
    //                                 // Handle case where 'field_value' is not set
    //                                 return response()->json(['error' => 'No files uploaded or invalid file data.'], 400);
    //                             }
                                
    //                             // Store the file path directly in the 'field_meta_value'
    //                             $customFieldData['field_meta_value'] = $filePath;
    //                             break;
    //                         default:
    //                             return response()->json(['error' => 'Unsupported field type'], 400);
    //                     }
        
    //                     Customfieldvalue::create($customFieldData);
    //                 }
    //             }
            
    //             // Prepare the response data
    //             $responseData = $project;
            
    //             $returnRes = [
    //                 'status' => true,
    //                 'message' => 'Data added successfully.',
    //                 'data' => $request->all(),
    //             ];
            
    //             return response()->json($returnRes, 201);
    //         } catch (\Throwable $th) {
    //             // Log or handle the exception
    //             return response()->json(['error' => $th->getMessage() . ' '. $th->getLine() . ' '. $th->getFile()], 500);
    //         }
    // }


    //     // this is for listing
    //     public function indexWebsite(Request $request)
    // {
    //     try {
    //         $baseURL = config('app.url');
    //         $basePath = public_path();
            
    //         $projects = Developerlist::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])->get();
            
    //         $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
    //             $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
    //                 $customField = $customFieldValue->customField;
    //                 $customFieldOption = $customFieldValue->customFieldOption ?? null;
                    
    //                 $fieldValue = $customFieldValue->field_meta_value;
    //                 if ($customField && $customField->field_type == 'checkbox') {
    //                     // For checkbox, explode the value to get an array
    //                     $fieldValueArray = explode(',', $fieldValue);
    //                 } elseif ($customField && $customField->field_type == 'media') {
    //                     // Handling for media field
    //                     // Add baseURL to media file
    //                     $fieldValueArray = json_decode($fieldValue);
    //                     $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
    //                         return $baseURL . '/uploads/media/' . $file;
    //                     });
    //                 } else {
    //                     // For other field types or if $customField is null, keep the value as is
    //                     $fieldValueArray = $fieldValue;
    //                 }
        
    //                 // Include all options for the field
    //                 $customFieldOptions = $customField ? $customField->options : null;
                    
    //                 return [
    //                     'custom_field_id' => $customField ? $customField->id : null,
    //                     'field_type' => $customField ? $customField->field_type : null,
    //                     'field_value' => $fieldValueArray,
    //                     'field_name' => $customField ? $customField->field_name : null,
    //                     // 'custom_field_options' => $customFieldOptions,
    //                 ];
    //             });
        
    //             // Prepare property data
    //             $developerData = [
    //                 'id' => $property->id,
    //                 'developer_unique_id' => $property->developer_unique_id,
    //                 'name' => $property->name,
    //                 'description' => $property->description,
    //                 'location_id' => $property->location_id,
    //                 'location_name' => optional($property->location)->name,
    //                 'status' => $property->status,
    //                 'status_reason' => $property->status_reason,
    //                 'user_id' => $property->user_id,
    //                 'listed_by' => optional(optional($property->user)->role)->name,
    //                 'purpose_id' => $property->purpose_id,
    //                 'purpose_id_name' => optional($property->purpose)->name,
    //                 'property_id' => $property->property_id,
    //                 'property_id_name' => optional($property->property)->name,
    //                 'property_status_id' => $property->property_status_id,
    //                 'property_status_id_name' => optional($property->propertystatus)->name,
    //                 'property_type_id' => $property->property_type_id,
    //                 'property_type_id_name' => optional($property->propertyType)->name,
    //                 'date' => date('d m Y',strtotime($property->created_at)),
    //                 'time' => date('h:m A',strtotime($property->created_at)),
    //                 'timestamp' => date('d m Y h:m A',strtotime($property->created_at)),
    //                 'custom_field_values' => $formattedCustomFieldValues,
    //             ];
                
    //             return $developerData;
    //         });
            
    //         return response()->json($projectsData);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage() . ' '. $th->getLine() . ' '. $th->getFile()], 500);
    //     }
    // }

    

    //     // this is for update the record 
    //     public function updateWebsite(Request $request)
    // {

    //     if ($request->header('api-token') == '') {
    //             return response()->json(['error' => 'Please enter api token first.'], 422);
    //     }
        
    //     $requestToken = $request->header('api-token');

        
    //     try {
    //         $userId = null;
        
    //         $userData = User::where('api_token', $requestToken)->first();
        
    //         // Validate that the user exists in the database
    //         if (!$userData) {
    //             return response()->json(['error' => 'User not found'], 404);
    //         }
            
    //         $userId = $userData->id;
        
    //         $id = $request->id;
        
    //         if (!Developerlist::where('id', $id)->exists()) {
    //             return response()->json(['error' => 'Invalid Developer Id'], 404);
    //         }
        
    //         $project = Developerlist::findOrFail($id);
        
    //         // Validate the request data
    //         $validatedData = $request->validate([
    //             'location_id' => 'required|exists:locations,id',
    //             'purpose_id' => 'nullable',
    //             'property_id' => 'nullable',
    //             'property_status_id' => 'nullable',
    //             'property_type_id' => 'nullable',
    //             'name' => 'nullable',
    //             'description' => 'nullable',
    //         ]);
        
        
    //         // Handle file uploads
    //         foreach (['property_video', 'virtual_tour', 'video_thumbnail', 'featured_image', 'brochure'] as $fileField) {
    //             if ($request->hasFile($fileField)) {
    //                 $file = $request->file($fileField);
    //                 $fileName = time() . '_' . $file->getClientOriginalName();
    //                 $file->move(public_path('uploads/' . $fileField), $fileName);
    //                 $validatedData[$fileField] = $fileName;
    //             }
    //         }
        
    //         $project->update($validatedData);
        
    //         // Handle repeater field values
    //         if ($request->has('repeater_fields')) {
    //             Customfieldvalue::where('project_listing_id', $project->id)->delete();
    //                 $repeaterFields = $request->repeater_fields;
        
    //                 foreach ($repeaterFields as $repeaterFieldIndex => $repeaterField) {
    //                     $customFieldData = [
    //                         'project_listing_id' => $project->id,
    //                         'custom_field_id' => $repeaterField['custom_field_id'],
    //                     ];
        
    //                     // Check the field type and handle accordingly
    //                     switch ($repeaterField['field_type']) {
    //                         case 'text':
    //                         case 'textarea':
    //                         case 'texteditor':
    //                             $customFieldData['field_meta_value'] = $repeaterField['field_value'];
    //                             break;
    //                         case 'select':
    //                         case 'radio':
    //                             // Retrieve the custom_field_options_id from the custom_field_options table
    //                             $customFieldOptions = DB::table('custom_field_options')
    //                                 ->where('custom_field_id', $repeaterField['custom_field_id'])
    //                                 ->where('value', $repeaterField['field_value'])
    //                                 ->first();
        
    //                             if ($customFieldOptions) {
    //                                 $customFieldData['custom_field_options_id'] = $customFieldOptions->id;
    //                             }
    //                             $customFieldData['field_meta_value'] = $repeaterField['field_value'];
    //                             break;
    //                         case 'checkbox':
    //                             // For checkbox, assume multiple values can be selected
    //                             $values = explode(',', $repeaterField['field_value']); // Split the string into an array
        
    //                             // Convert array to string
    //                             $customFieldData['field_meta_value'] = implode(',', $values);
        
    //                             // Retrieve custom_field_options_id for each selected value
    //                             $customFieldOptionsIds = [];
    //                             foreach ($values as $value) {
    //                                 $customFieldOption = DB::table('custom_field_options')
    //                                     ->where('custom_field_id', $repeaterField['custom_field_id'])
    //                                     ->where('value', $value)
    //                                     ->first();
        
    //                                 if ($customFieldOption) {
    //                                     $customFieldOptionsIds[] = $customFieldOption->id;
    //                                 }
    //                             }
    //                             $customFieldData['custom_field_options_id'] = implode(',', $customFieldOptionsIds);
    //                             break;
    //                         case 'media':
    //                             // Handling for media field
    //                             $mediaFiles = [];

    //                             // Check if file names are provided as strings
    //                             if (is_array($repeaterField['field_value'])) {
    //                                 foreach ($repeaterField['field_value'] as $value) {
    //                                     // Check if the value is a string (file name)
    //                                     if (is_string($value)) {
    //                                         // Add the file name to the array
    //                                         $mediaFiles[] = $value;
    //                                     } else {
    //                                         // Handle binary data (assuming it's a file object)
    //                                         if ($value->isValid()) {
    //                                             $fileName = time() . '_' . $value->getClientOriginalName();
    //                                             $value->move(public_path('uploads/media'), $fileName);
    //                                             $mediaFiles[] = $fileName;
    //                                         } else {
    //                                             // Handle invalid files
    //                                             return response()->json(['error' => 'Invalid media file.'], 400);
    //                                         }
    //                                     }
    //                                 }
    //                             }

    //                             // Store the file names array in the 'field_meta_value'
    //                             $customFieldData['field_meta_value'] = json_encode($mediaFiles);
    //                             break;
    //                             case 'file':
    //                             // Handling for file field
    //                             $filePath = '';

    //                             // Ensure the 'field_value' is set and it's an array
    //                             if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
    //                                 // Retrieve the uploaded file object
    //                                 $file = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');

    //                                 // Ensure the file is a valid PDF
    //                                 if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
    //                                     // Generate unique filename for the file
    //                                     $uniqueFileName = time() . '_' . $file->getClientOriginalName();

    //                                     // Move the file to the uploads/gallery directory
    //                                     $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
    //                                 } else {
    //                                     return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
    //                                 }
    //                             } else {
    //                                 // Handle case where 'field_value' is not set
    //                                 return response()->json(['error' => 'No files uploaded or invalid file data.'], 400);
    //                             }

    //                             // Store the file path directly in the 'field_meta_value'
    //                             $customFieldData['field_meta_value'] = $filePath;
    //                             break;
    //                             default:
    //                             return response()->json(['error' => 'Unsupported field type'], 400);
    //                                     }
                        
    //                                     Customfieldvalue::create($customFieldData);
    //                                 }
    //             }
        
    //         // Prepare the response data
    //         $responseData = $project;
        
    //         $returnRes = [
    //             'status' => true,
    //             'message' => 'Data updated successfully.'
    //         ];
        
    //         return response()->json($returnRes);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    
    //     // this is for delete the record
    //     public function destroyWebsite(Request $request)
    // {

    //     if ($request->header('api-token') == '') {
    //             return response()->json(['error' => 'Please enter api token first.'], 422);
    //     }
        
    //     $requestToken = $request->header('api-token');
        
    //     try {
    //     $id = $request->id;
    //     $project = Developerlist::find($id);
        
    //     if (!$project) {
    //     return response()->json(['message' => 'No Developer found'], 404);
    //     }
        
    //     // Delete specific related records
    //     $project->customFieldValues()->delete();
        
    //     // Delete the project
    //     $project->delete();
        
    //     $returnRes = [
    //     'status' => true,
    //     'message' => 'Data deleted successfully.'
    //     ];
        
    //     return response()->json($returnRes,200);
    //     } catch (\Throwable $th) {
    //     return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }
        
        
    //     // this is for get data by id
    //     public function getdatabyIdWebsite(Request $request)
    // {
    //     try {
    //         if ($request->id == '') {
    //             return response()->json(['error' => 'ID is required'], 400);
    //         }
            
    //         $baseURL = config('app.url');
    //         $basePath = public_path();
            
    //         $projects = Developerlist::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])->where('id', $request->id)->get();
            
    //         $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
    //             $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
    //                 $customField = $customFieldValue->customField;
    //                 $customFieldOption = $customFieldValue->customFieldOption ?? null;
                    
    //                 $fieldValue = $customFieldValue->field_meta_value;
    //                 if ($customField && $customField->field_type == 'checkbox') {
    //                     // For checkbox, explode the value to get an array
    //                     $fieldValueArray = explode(',', $fieldValue);
    //                 } elseif ($customField && $customField->field_type == 'media') {
    //                     // Handling for media field
    //                     // Add baseURL to media file
    //                     $fieldValueArray = json_decode($fieldValue);
    //                     $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
    //                         return $file;
    //                     });
    //                 } else {
    //                     // For other field types or if $customField is null, keep the value as is
    //                     $fieldValueArray = $fieldValue;
    //                 }
        
    //                 // Include all options for the field
    //                 $customFieldOptions = $customField ? $customField->options : null;
                    
    //                 return [
    //                     'custom_field_id' => $customField ? $customField->id : null,
    //                     'field_type' => $customField ? $customField->field_type : null,
    //                     'field_value' => $fieldValueArray,
    //                     'field_name' => $customField ? $customField->field_name : null,
    //                     // 'custom_field_options' => $customFieldOptions,
    //                 ];
    //             });
        
    //             // Prepare property data
    //             $developerData = [
    //                 'id' => $property->id,
    //                 'developer_unique_id' => $property->developer_unique_id,
    //                 'name' => $property->name,
    //                 'description' => $property->description,
    //                 'location_id' => $property->location_id,
    //                 'location_name' => optional($property->location)->name,
    //                 'property_address' => $property->property_address,
    //                 'status' => $property->status,
    //                 'status_reason' => $property->status_reason,
    //                 'user_id' => $property->user_id,
    //                 'listed_by' => optional(optional($property->user)->role)->name,
    //                 'purpose_id' => $property->purpose_id,
    //                 'purpose_id_name' => optional($property->purpose)->name,
    //                 'property_id' => $property->property_id,
    //                 'property_id_name' => optional($property->property)->name,
    //                 'property_status_id' => $property->property_status_id,
    //                 'property_status_id_name' => optional($property->propertystatus)->name,
    //                 'property_type_id' => $property->property_type_id,
    //                 'property_type_id_name' => optional($property->propertyType)->name,
    //                 'custom_field_values' => $formattedCustomFieldValues,
    //             ];
                
    //             return $developerData;
    //         });
            
    //         return response()->json($projectsData);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }


}
