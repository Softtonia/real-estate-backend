<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Projectlist;
use App\Models\ProjectAnalytic;
use App\Models\User;
use App\Models\Status;
use App\Models\Amenity;
use App\Models\PropertyType;
use App\Models\Location;
use App\Models\Customfieldvalue; 
use App\Models\AmenitiesCategory;
use App\Models\Keyword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectlistingController extends Controller
{
    
    
        // this is for store the data
        public function store(Request $request)
    {       
            
        try {

            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');

            $userId = null;
        
            $userData = User::where('api_token', $requestToken)->first();
        
            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userId = $userData->id;
                
            
                $project_unique_id = 'Project' . rand(111111, 999999);
            
                // Upload files to public/uploads/
                $projectData = [
                    'project_unique_id' => $project_unique_id,
                    'user_id' => $userId,
                    'name' => $request->name,
                    'description' => $request->description,
                    'location_id' => $request->location_id,
                    'purpose_id' => $request->purpose_id,
                    'property_id' => $request->property_id,
                    'property_status_id' => $request->property_status_id,
                    'property_type_id' => $request->property_type_id,
                    'status' => 'pending',
                    'project_status' => 1,
                    'developer_id' => $request->developer_id ?? null
                ];
        
                // Handle file uploads
                foreach (['property_video', 'virtual_tour', 'video_thumbnail', 'featured_image', 'brochure'] as $fileField) {
                    if ($request->hasFile($fileField)) {
                        $file = $request->file($fileField);
                        $fileName = time() . '_' . $file->getClientOriginalName();
                        $file->move(public_path('uploads/' . $fileField), $fileName);
                        $projectData[$fileField] = $fileName;
                    }
                }
            
                $project = Projectlist::create($projectData);

                $lastInsertId = $project->id;

                if (!empty($request->keyword)) {
                    $explod_keywords = $request->keyword;
                    foreach ($explod_keywords as $row) {
                        $inserData = [
                            'project_id' => $lastInsertId,
                            'keyword' => $row,
                        ];

                        Keyword::create($inserData);
                    }
                }
            
                // Handle repeater field values pawan
                if ($request->has('repeater_fields')) {
                    $repeaterFields = $request->repeater_fields;
        
                    foreach ($repeaterFields as $repeaterFieldIndex => $repeaterField) {
                        $customFieldData = [
                            'project_listing_id' => $project->id,
                            'custom_field_id' => $repeaterField['custom_field_id'],
                        ];
        
                        // Check the field type and handle accordingly
                        switch ($repeaterField['field_type']) {
                            case 'text':
                            case 'textarea':
                            case 'texteditor':
                                $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                                break;
                            case 'select':
                            case 'radio':
                                // Retrieve the custom_field_options_id from the custom_field_options table
                                $customFieldOptions = DB::table('custom_field_options')
                                    ->where('custom_field_id', $repeaterField['custom_field_id'])
                                    ->where('value', $repeaterField['field_value'])
                                    ->first();
        
                                if ($customFieldOptions) {
                                    $customFieldData['custom_field_options_id'] = $customFieldOptions->id;
                                }
                                $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                                break;
                            case 'checkbox':
                                // For checkbox, assume multiple values can be selected
                                $values = explode(',', $repeaterField['field_value']); // Split the string into an array
        
                                // Convert array to string
                                $customFieldData['field_meta_value'] = implode(',', $values);
        
                                // Retrieve custom_field_options_id for each selected value
                                $customFieldOptionsIds = [];
                                foreach ($values as $value) {
                                    $customFieldOption = DB::table('custom_field_options')
                                        ->where('custom_field_id', $repeaterField['custom_field_id'])
                                        ->where('value', $value)
                                        ->first();
        
                                    if ($customFieldOption) {
                                        $customFieldOptionsIds[] = $customFieldOption->id;
                                    }
                                }
                                $customFieldData['custom_field_options_id'] = implode(',', $customFieldOptionsIds);
                                break;
                            case 'media':
                                // Handling for media field
                                if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
                                    $mediaArray = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');
                                    $mediaFiles = [];
        
                                    foreach ($mediaArray as $media) {
                                        // Ensure each file is valid and handle accordingly
                                        if ($media->isValid()) {
                                            $fileName = time() . '_' . $media->getClientOriginalName();
                                            $media->move(public_path('uploads/media'), $fileName);
                                            $mediaFiles[] = $fileName;
                                        } else {
                                            // Handle invalid files
                                            return response()->json(['error' => 'Invalid media file.'], 400);
                                        }
                                    }
        
                                    // Store the file paths array in the 'field_meta_value'
                                    $customFieldData['field_meta_value'] = json_encode($mediaFiles);
                                } else {
                                    // Handle case where 'field_value' is not set
                                    return response()->json(['error' => 'No media files uploaded or invalid file data.'], 400);
                                }
                                break;
                            case 'file':
                                // Handling for file field
                                $filePaths = [];
                                
                                // Ensure the 'field_value' is set and it's an array
                                if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
                                    // Retrieve the array of uploaded file objects
                                    $files = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');
                                    
                                    // Process each uploaded file
                                    foreach ($files as $file) {
                                        // Ensure each file is a valid PDF
                                        if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                                            // Generate unique filename for each file
                                            $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                            
                                            // Move the file to the uploads/gallery directory
                                            $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                                            
                                            // Store the file path in the array
                                            $filePaths[] = $filePath;
                                        } else {
                                            // Handle case where an uploaded file is not a valid PDF
                                            return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
                                        }
                                    }
                                } else {
                                    // Handle case where 'field_value' is not set
                                    return response()->json(['error' => 'No files uploaded or invalid file data.'], 400);
                                }
                                
                                // Store the array of file paths in the 'field_meta_value'
                                $customFieldData['field_meta_value'] = json_encode($filePaths);
                                break;
                            default:
                                return response()->json(['error' => 'Unsupported field type'], 400);
                        }
        
                        Customfieldvalue::create($customFieldData);
                    }
                }
            
                // Prepare the response data
                $responseData = $project;
            
                $returnRes = [
                    'status' => true,
                    'message' => 'Data added successfully.',
                    'data' => $request->all(),
                ];
            
                return response()->json($returnRes, 201);
            } catch (\Throwable $th) {
                // Log or handle the exception
                return response()->json(['error' => $th->getMessage()], 500);
            }
    }


        // this is for listing
        public function index(Request $request)
    {
        try {

            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');

            $userId = null;
        
            $userData = User::where('api_token', $requestToken)->first();
        
            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userId = $userData->id;

            $baseURL = config('app.url');
            $basePath = public_path();
            
            $projects = Projectlist::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption','keywords'])->where('user_id',$userId)->get();
            
            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;
                    
                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }
        
                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;
                    
                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });
        
                // Prepare property data
                $projectData = [
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y',strtotime($property->created_at)),
                    'time' => date('h:m A',strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A',strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
                
                return $projectData;
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
            
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');


            $userId = null;
        
            $userData = User::where('api_token', $requestToken)->first();
        
            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userId = $userData->id;
        
            $id = $request->id;
        
            if (!Projectlist::where('id', $id)->exists()) {
                return response()->json(['error' => 'Invalid Property Id'], 404);
            }
        
            $project = Projectlist::findOrFail($id); 

            $lastInsertId = $id;

            if(!empty($request->keyword)){
                Keyword::where('project_id',$lastInsertId)->delete();
                $explod_keywords = $request->keyword;
                foreach($explod_keywords as $row){
                    $inserData = [
                        'project_id' => $lastInsertId,
                        'keyword' => $row,
                    ];

                    Keyword::create($inserData);
                }
            }
        
            // Validate the request data
            $validatedData = $request->validate([
                'location_id' => 'required|exists:locations,id',
                'purpose_id' => 'nullable',
                'property_id' => 'nullable',
                'property_status_id' => 'nullable',
                'property_type_id' => 'nullable',
                'name' => 'nullable|string',
                'description' => 'nullable|string',
                'developer_id' => $request->developer_id ?? null
            ]);

        
            // Handle file uploads
            foreach (['property_video', 'virtual_tour', 'video_thumbnail', 'featured_image', 'brochure'] as $fileField) {
                if ($request->hasFile($fileField)) {
                    $file = $request->file($fileField);
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $file->move(public_path('uploads/' . $fileField), $fileName);
                    $validatedData[$fileField] = $fileName;
                }
            }
        
            $project->update($validatedData);
        
            // Handle repeater field values
            if ($request->has('repeater_fields')) {
                Customfieldvalue::where('project_listing_id', $project->id)->delete();
                    $repeaterFields = $request->repeater_fields;
        
                    foreach ($repeaterFields as $repeaterFieldIndex => $repeaterField) {
                        $customFieldData = [
                            'project_listing_id' => $project->id,
                            'custom_field_id' => $repeaterField['custom_field_id'],
                        ];
        
                        // Check the field type and handle accordingly
                        switch ($repeaterField['field_type']) {
                            case 'text':
                            case 'textarea':
                            case 'texteditor':
                                $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                                break;
                            case 'select':
                            case 'radio':
                                // Retrieve the custom_field_options_id from the custom_field_options table
                                $customFieldOptions = DB::table('custom_field_options')
                                    ->where('custom_field_id', $repeaterField['custom_field_id'])
                                    ->where('value', $repeaterField['field_value'])
                                    ->first();
        
                                if ($customFieldOptions) {
                                    $customFieldData['custom_field_options_id'] = $customFieldOptions->id;
                                }
                                $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                                break;
                            case 'checkbox':
                                // For checkbox, assume multiple values can be selected
                                $values = explode(',', $repeaterField['field_value']); // Split the string into an array
        
                                // Convert array to string
                                $customFieldData['field_meta_value'] = implode(',', $values);
        
                                // Retrieve custom_field_options_id for each selected value
                                $customFieldOptionsIds = [];
                                foreach ($values as $value) {
                                    $customFieldOption = DB::table('custom_field_options')
                                        ->where('custom_field_id', $repeaterField['custom_field_id'])
                                        ->where('value', $value)
                                        ->first();
        
                                    if ($customFieldOption) {
                                        $customFieldOptionsIds[] = $customFieldOption->id;
                                    }
                                }
                                $customFieldData['custom_field_options_id'] = implode(',', $customFieldOptionsIds);
                                break;
                            case 'media':
                // Handling for media field
                $mediaFiles = [];

                // Check if file names are provided as strings
                if (is_array($repeaterField['field_value'])) {
                    foreach ($repeaterField['field_value'] as $value) {
                        // Check if the value is a string (file name)
                        if (is_string($value)) {
                            // Add the file name to the array
                            $mediaFiles[] = $value;
                        } else {
                            // Handle binary data (assuming it's a file object)
                            if ($value->isValid()) {
                                $fileName = time() . '_' . $value->getClientOriginalName();
                                $value->move(public_path('uploads/media'), $fileName);
                                $mediaFiles[] = $fileName;
                            } else {
                                // Handle invalid files
                                return response()->json(['error' => 'Invalid media file.'], 400);
                            }
                        }
                    }
                }

                // Store the file names array in the 'field_meta_value'
                $customFieldData['field_meta_value'] = json_encode($mediaFiles);
                break;

                case 'file':
                    // Handling for file field
                    $filePaths = [];
                    
                    // Ensure the 'field_value' is set and it's an array
                    if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
                        // Retrieve the array of uploaded file objects
                        $files = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');
                        
                        // Process each uploaded file
                        foreach ($files as $file) {
                            // Ensure each file is a valid PDF
                            if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                                // Generate unique filename for each file
                                $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                
                                // Move the file to the uploads/gallery directory
                                $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                                
                                // Store the file path in the array
                                $filePaths[] = $filePath;
                            } else {
                                // Handle case where an uploaded file is not a valid PDF
                                return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
                            }
                        }
                    } else {
                        // Handle case where 'field_value' is not set
                        return response()->json(['error' => 'No files uploaded or invalid file data.'], 400);
                    }
                    
                    // Store the array of file paths in the 'field_meta_value'
                    $customFieldData['field_meta_value'] = json_encode($filePaths);
                    break;
                default:
                return response()->json(['error' => 'Unsupported field type'], 400);
                        }
        
                        Customfieldvalue::create($customFieldData);
                    }
                }
        
            // Prepare the response data
            $responseData = $project;
        
            $returnRes = [
                'status' => true,
                'message' => 'Data updated successfully.'
            ];
        
            return response()->json($returnRes);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    
        // this is for delete the record
        public function destroy(Request $request)
    {
        
        try {

            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');


            $userId = null;
        
            $userData = User::where('api_token', $requestToken)->first();
        
            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userId = $userData->id;

            $id = $request->id;
            $project = Projectlist::find($id);
            
            if (!$project) {
            return response()->json(['message' => 'No project found'], 404);
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
        
        
        // this is foe get data by id
        public function getdatabyId(Request $request)
    {
        try {

            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');


            $userId = null;
        
            $userData = User::where('api_token', $requestToken)->first();
        
            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userId = $userData->id;

            if ($request->id == '') {
                return response()->json(['error' => 'ID is required'], 400);
            }
            
            $baseURL = config('app.url');
            $basePath = public_path();
            
            $projects = Projectlist::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption','keywords'])->where('id', $request->id)->get();
            
            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;
                    
                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }
        
                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;
                    
                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });
        
                // Prepare property data
                $projectData = [
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'total_view' => $property->analytics()->count(),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
                
                return $projectData;
            });
            
            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


        
        // this is for update property status
        public function updateProjectStatus(Request $request)
    {
        
            try {

                if ($request->header('api-token') == '') {
                    return response()->json(['error' => 'Please enter api token first.'], 422);
                }

                $requestToken = $request->header('api-token');


                $userId = null;
            
                $userData = User::where('api_token', $requestToken)->first();
            
                // Validate that the user exists in the database
                if (!$userData) {
                    return response()->json(['error' => 'User not found'], 404);
                }

                $userId = $userData->id;

                // Validate the request data
                $validatedData = $request->validate([
                    'project_id' => 'required',
                    'project_status' => 'required',
                ]);
                
            } catch (\Illuminate\Validation\ValidationException $e) {
                    // Return validation error response
                    return response()->json(['error' => $e->errors()], 422);
            }
        
        
        
                // Check if the setting record exists
                $projectData = Projectlist::where('id',$request->project_id)->first();

        
                if (!$projectData) {
                  return response()->json([
                    'status' => false,
                    'message' => 'Invalid Project Id',
                ], 401);  
                } 


                if($request->project_status == 'active'){
                   $project_status_val = '1';
                }else{
                    $project_status_val = '0';
                }
        
                $projectData->project_status = $project_status_val;
                $projectData->update();
        
                // Return the updated or newly created setting data as JSON response
                return response()->json([
                    'status' => true,
                    'message' => 'Status updated successfully',
                ], 201);
    }


    // this is for overview
    public function overviewOfProject(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userId = $userData->id;

            $totalProjectCount = Projectlist::where('user_id', $userId)->count();
            $approvedCount = Projectlist::where('user_id', $userId)->where('status', 'approved')->count();
            $rejectCount = Projectlist::where('user_id', $userId)->where('status', 'reject')->count();
            $pendingCount = Projectlist::where('user_id', $userId)->where('status', 'pending')->count();

            // Construct the return data
            $return = [
                'total_project_count' => $totalProjectCount,
                'approved_project_count' => $approvedCount,
                'reject_project_count' => $rejectCount,
                'pending_project_count' => $pendingCount,
            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
    


        // this of for stote project analytics
    public function storeProjectAnalytics(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
            'user_id' => 'required|string',
            'project_id' => 'nullable',
            'type' => 'required',
            ]);

            ProjectAnalytic::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Analytics store successfully',
            ], 201);
        }catch (\Throwable $th) {
        // Log or handle the exception
        return response()->json(['error' => $th->getMessage()], 500);
        }
    }
        

    // This is for list of project analytics
    public function listProjectAnalytics()
    {
        $analytics = ProjectAnalytic::with('user', 'project')->get();

        $formattedAnalytics = [];
        foreach ($analytics as $analytic) {
            $projectId = $analytic->project ? $analytic->project->id : null;
            $projectTitle = $analytic->project ? $analytic->project->name : 'Unknown';
            $userName = $analytic->user ? $analytic->user->fullname : 'Unknown';
            $formattedAnalytics[] = [
                'id' => $analytic->id,
                'project_id' => $projectId,
                'property_title' => $projectTitle,
                'user_name' => $userName,
                'type' => $analytic->type,
            ];
        }


        // Return formatted analytics data as JSON response
        return response()->json($formattedAnalytics);
    }

    // This is for view list of property analytics
    public function viewProjectAnalytics(Request $request)
    {

        if ($request->header('api-token') == '') {
            return response()->json(['error' => 'Please enter api token first.'], 422);
        }

        $requestToken = $request->header('api-token');


        $userId = null;

        $userData = User::where('api_token', $requestToken)->first();

        // Validate that the user exists in the database
        if (!$userData) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $userId = $userData->id;

        $projectIdsArr = Projectlist::where('user_id',$userId)->pluck('id')->toArray();

        // Get unique property IDs
        $projectIds = ProjectAnalytic::whereIn('project_id',$projectIdsArr)->pluck('project_id')->unique();

        // Initialize arrays to store property labels, total views, and property names
        $projectLabels = [];
        $totalViews = [];
        $projectNames = [];

        // Loop through each unique property ID
        foreach ($projectIds as $project_id) {
            // Check if property exists
            $property = Projectlist::find($project_id);
            if (!$property) {
                // Skip if property does not exist
                continue;
            }

            // Get total view count for the property
            $totalViews[] = $this->getProjectViewsCount($project_id);

            // Push property label into array
            $projectLabels[] = $project_id;

            // Push property name into array
            $projectNames[] = $property->name;
        }

        // Return formatted analytics data as JSON response
        return response()->json([
            'id' => '1',
            'project_ids' => $projectLabels,
            'values' => $totalViews,
            'labels' => $projectNames,
        ]);
    }



    // This is for fetch view count
    protected function getProjectViewsCount($projectId)
    {
        // Get the count of views for the specified property ID
        return ProjectAnalytic::where('project_id', $projectId)
            ->where('type', 'view')
            ->count();
    }



}