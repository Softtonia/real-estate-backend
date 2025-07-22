<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Propertylist;
use App\Models\User;
use App\Models\Status;
use App\Models\Amenity;
use App\Models\PropertyType;
use App\Models\Location;
use App\Models\PropertyAnalytic;
use App\Models\Customfieldvalue;
use App\Models\AmenitiesCategory;
use App\Models\Keyword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PropertylistingController extends Controller
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

            $property_unique_id = 'URP' . rand(111111, 999999);

            // Upload files to public/uploads/
            $propertyData = [
                'property_unique_id' => $property_unique_id,
                'user_id' => $userId,
                'name' => $request->name,
                'description' => $request->description,
                'location_id' => $request->location_id,
                'property_address' => $request->property_address,
                'purpose_id' => $request->purpose_id,
                'property_id' => $request->property_id,
                'property_status_id' => $request->property_status_id,
                'property_type_id' => json_encode($request->property_type_id),
                'status' => 'pending',
                'project_id' =>  $request->project_id,
            ];

            // Handle file uploads
            foreach (['property_video', 'virtual_tour', 'video_thumbnail', 'featured_image', 'brochure'] as $fileField) {
                if ($request->hasFile($fileField)) {
                    $file = $request->file($fileField);
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $file->move(public_path('uploads/' . $fileField), $fileName);
                    $propertyData[$fileField] = $fileName;
                }
            }

            $property = Propertylist::create($propertyData);

            $lastInsertId = $property->id;

            if (!empty($request->keyword)) {
                $explod_keywords = $request->keyword;
                foreach ($explod_keywords as $row) {
                    $inserData = [
                        'property_id' => $lastInsertId,
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
                        'properties_listing_id' => $property->id,
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
            $responseData = $property;

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
        // dd(123);
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

            $properties = Propertylist::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus','project', 'customFieldValues.customField', 'customFieldValues.customFieldOption','keywords'])
            ->where('user_id', $userId)
            ->where('status','approved')
            ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
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
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y',strtotime($property->created_at)),
                    'time' => date('h:m A',strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A',strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // Function to append base URL to gallery images
    private function appendBaseURL($gallery, $baseURL)
    {
        return array_map(function($image) use ($baseURL) {
        return $baseURL . '/public/uploads/gallery/'. $image;
        }, $gallery);
    }


    // Function to correct file paths for images and videos
    private function correctFilePath($filePath, $baseURL, $basePath,$Fname)
    {
        $publicPath = $basePath . '/public/';
        if (strpos($filePath, $publicPath) !== false) {
        $relativePath = str_replace($publicPath, '', $filePath);
        return $baseURL . '/' . $relativePath;
        }
        return $baseURL . '/public/uploads/'.$Fname .'/'. $filePath;
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

            if (!Propertylist::where('id', $id)->exists()) {
                return response()->json(['error' => 'Invalid Property Id'], 404);
            }

            $property = Propertylist::findOrFail($id); // Find the property by ID

            // Validate the request data
            $validatedData = $request->validate([
                'name' => 'required|string',
                'description' => 'required|string',
                'location_id' => 'required|exists:locations,id',
                'property_address' => 'string',
                'purpose_id' => 'nullable',
                'property_id' => 'nullable',
                'property_status_id' => 'nullable',
                'property_type_id' => 'nullable|array',
                'project_id' => 'nullable',
            ]);

		// Encode property_type_id to JSON if it's an array
            if ($request->has('property_type_id')) {
                $property['property_type_id'] = json_encode($request->property_type_id);
            }

            $validatedData['user_id'] = $userId;

            // Handle file uploads
            foreach (['property_video', 'virtual_tour', 'video_thumbnail', 'featured_image', 'brochure'] as $fileField) {
                if ($request->hasFile($fileField)) {
                    $file = $request->file($fileField);
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $file->move(public_path('uploads/' . $fileField), $fileName);
                    $validatedData[$fileField] = $fileName;
                }
            }

            $property->update($validatedData);

            $lastInsertId = $id;

            if(!empty($request->keyword)){
                Keyword::where('property_id',$lastInsertId)->delete();
                $explod_keywords = $request->keyword;
                foreach($explod_keywords as $row){
                    $inserData = [
                        'property_id' => $lastInsertId,
                        'keyword' => $row,
                    ];

                    Keyword::create($inserData);
                }
            }

            // Handle repeater field values
            if ($request->has('repeater_fields')) {
                Customfieldvalue::where('properties_listing_id', $property->id)->delete();
                    $repeaterFields = $request->repeater_fields;

                    foreach ($repeaterFields as $repeaterFieldIndex => $repeaterField) {
                        $customFieldData = [
                            'properties_listing_id' => $property->id,
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
            $responseData = $property;

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
            $property = Propertylist::find($id);

            if (!$property) {
            return response()->json(['message' => 'Data not found'], 404);
            }

            // Delete specific related records
            $property->customFieldValues()->delete();

            // Delete the property
            $property->delete();

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
            if ($request->id == '') {
                return response()->json(['error' => 'ID is required'], 400);
            }

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = Propertylist::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus','project', 'customFieldValues.customField', 'customFieldValues.customFieldOption','keywords'])->where('id', $request->id)->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
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
                        'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'posted_on' => date('d M, Y',strtotime($property->created_at)),
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }






    // this of for stote property analytics
    public function storePropertyAnalytics(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
            'user_id' => 'required|string',
            'property_id' => 'nullable',
            'type' => 'required',
            ]);

            PropertyAnalytic::create($validatedData);

            return response()->json([
                'status' => true,
                'message' => 'Analytics store successfully',
            ], 201);
        }catch (\Throwable $th) {
        // Log or handle the exception
        return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // This is for list of property analytics
    public function listPropertyAnalytics()
    {
        $analytics = PropertyAnalytic::with('user', 'property')->get();

        $formattedAnalytics = [];
        foreach ($analytics as $analytic) {
            $propertyId = $analytic->property ? $analytic->property->id : null;
            $propertyTitle = $analytic->property ? $analytic->property->name : 'Unknown';
            $userName = $analytic->user ? $analytic->user->fullname : 'Unknown';
            $formattedAnalytics[] = [
                'id' => $analytic->id,
                'property_id' => $propertyId,
                'property_title' => $propertyTitle,
                'user_name' => $userName,
                'type' => $analytic->type,
            ];
        }


        // Return formatted analytics data as JSON response
        return response()->json($formattedAnalytics);
    }

    // This is for view list of property analytics
    public function viewPropertyAnalytics(Request $request)
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

        $propertyIdsArr = Propertylist::where('user_id',$userId)->pluck('id')->toArray();

        // Get unique property IDs
        $propertyIds = PropertyAnalytic::whereIn('property_id',$propertyIdsArr)->pluck('property_id')->unique();

        // Initialize arrays to store property labels, total views, and property names
        $propertyLabels = [];
        $totalViews = [];
        $propertyNames = [];

        // Loop through each unique property ID
        foreach ($propertyIds as $property_id) {
            // Check if property exists
            $property = Propertylist::find($property_id);
            if (!$property) {
                // Skip if property does not exist
                continue;
            }

            // Get total view count for the property
            $totalViews[] = $this->getPropertyViewsCount($property_id);

            // Push property label into array
            $propertyLabels[] = $property_id;

            // Push property name into array
            $propertyNames[] = $property->name;
        }

        // Return formatted analytics data as JSON response
        return response()->json([
            'id' => '1',
            'property_ids' => $propertyLabels,
            'values' => $totalViews,
            'labels' => $propertyNames,
        ]);
    }



   // This is for fetch view count
    protected function getPropertyViewsCount($propertyId)
    {
        // Get the count of views for the specified property ID
        return PropertyAnalytic::where('property_id', $propertyId)
            ->where('type', 'view')
            ->count();
    }


}
