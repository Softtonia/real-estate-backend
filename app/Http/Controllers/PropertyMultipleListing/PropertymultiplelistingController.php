<?php

namespace App\Http\Controllers\PropertyMultipleListing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Propertylist;
use App\Models\Projectlist;
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
use Illuminate\Http\File;

class PropertymultiplelistingController extends Controller
{
    public function index()
{
    try {
    // Get all property types with associated property details
    $propertyTypes = PropertyType::with('property')->get();
    
    // Initialize an array to store property type data
    $propertyTypesData = [];
    
    // Iterate over each property type and extract id, name, slug, display order, property id, property name, and image
    foreach ($propertyTypes as $propertyType) {
    $propertyName = $propertyType->property ? $propertyType->property->name : null;
    $assignPropertyCount =  Propertylist::where('property_type_id',$propertyType->id)->count();
    
    $propertyTypesData[] = [
    'id' => $propertyType->id,
    'name' => $propertyType->name,
    'slug' => $propertyType->slug,
    'display_property_types_order' => $propertyType->display_property_types_order,
    'property_id' => optional($propertyType->property)->id,
    'property_name' => $propertyName,
    'image' => $propertyType->image,
    'propertyCount' => $assignPropertyCount,
    ];
    }
    
    // Return the property type data as JSON response
    return response()->json($propertyTypesData);
    } catch (\Throwable $th) {
    // Handle any exceptions and return an error response
    return response()->json(['error' => $th->getMessage()], 500);
    }
}
    public function store(Request $request)
    {
        if ($request->header('api-token') == '') {
            return response()->json(['error' => 'Please enter api token first.'], 422);
        }

        $requestToken = $request->header('api-token');
        
        if(isset($request->purpose_id)){
            
            $requiredFields = DB::table('custom_fields')->where('model','purpose')->where('post_type','property_list')->where('required', 'yes')->pluck('id')->toArray();
        }
        
        
        if(isset($request->property_id)){
            
            $requiredFields = DB::table('custom_fields')->where('model','property')->where('post_type','property_list')->where('required', 'yes')->pluck('id')->toArray();
        }
        
        
        if(isset($request->property_type_id)){
            
            $requiredFields = DB::table('custom_fields')->where('model','property_type')->where('post_type','property_list')->where('required', 'yes')->pluck('id')->toArray();
        }
        
        
        if(isset($request->property_status_id)){
            
            $requiredFields = DB::table('custom_fields')->where('model','property_status')->where('post_type','property_list')->where('required', 'yes')->pluck('id')->toArray();
        }
        

        $validationErrors = [];

        if ($requiredFields) {
            $repeaterFields = $request->repeater_fields;

            foreach ($requiredFields as $row) {
                
                // Check if the field is required and has a null value
                if ($row->required == 'yes') {
                    $customFieldId = $row->id;
                    $customFieldName = DB::table('custom_fields')->where('id', $customFieldId)->value('field_name');
                    $validationErrors[$customFieldId] = $customFieldName . ' is required';
                }
            }

            if (!empty($validationErrors)) {
                return response()->json(['errors' => $validationErrors], 422);
            }
        }

        try {
            $userId = null;

            $userData = User::where('api_token', $requestToken)->first();
            if (!$userData) {
                return response()->json(['error' => 'Invalid API token'], 401);
            }
            $userId = $userData->id;

            // Validate that the user exists in the database
            if ($userId && !User::where('id', $userId)->exists()) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $property_unique_id = 'URP' . rand(111111, 999999);

            // Upload files to public/uploads/
            $propertyData = [
                'property_unique_id' => $property_unique_id,
                'user_id' => '1',
                'name' => $request->name,
                'description' => $request->description,
                'location_id' => $request->location_id,
                'property_address' => $request->property_address,
                'purpose_id' => $request->purpose_id,
                'property_id' => $request->property_id,
                'property_status_id' => $request->property_status_id,
                // 'property_type_id' => json_encode($request->property_type_id),
                'property_type_id' => $request->property_type_id,
                'status' => 'approved',
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
                $explod_keywords = explode(',', $request->keyword);
                foreach ($explod_keywords as $row) {
                    $inserData = [
                        'property_id' => $lastInsertId,
                        'keyword' => $row,
                    ];

                    Keyword::create($inserData);
                }
            }

            // Handle repeater field values
            if ($request->has('repeater_fields')) {
                $repeaterFields = $request->repeater_fields;

                foreach ($repeaterFields as $repeaterFieldIndex => $repeaterField) {
                    $customFieldData = [
                        'properties_listing_id' => $property->id,
                        'custom_field_id' => $repeaterField['custom_field_id'],
                    ];

                    // Fetch the custom field details
                    $customField = DB::table('custom_fields')
                        ->where('id', $repeaterField['custom_field_id'])
                        ->first();

                    // Check the field type and handle accordingly
                    switch ($repeaterField['field_type']) {
                        case 'text':
                        case 'textarea':
                        case 'texteditor':
                            $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                            break;
                        case 'select':
                        case 'radio':
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
                            $values = explode(',', $repeaterField['field_value']);
                            $customFieldData['field_meta_value'] = implode(',', $values);

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
                            // Fetch media limit and size from custom_fields table
                            $mediaLimit = $customField->media_limit;
                            $mediaSize = $customField->media_size;

                            if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
                                $mediaArray = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');

                                // Check if the uploaded files count is less than media_limit
                                if (count($mediaArray) > $mediaLimit) {
                                    return response()->json(['error' => 'Exceeded media limit.'], 400);
                                }

                                $totalSize = 0;
                                $mediaFiles = [];
                                foreach ($mediaArray as $media) {
                                    if ($media->isValid()) {
                                        $totalSize += $media->getSize();
                                        $fileName = time() . '_' . $media->getClientOriginalName();
                                        $media->move(public_path('uploads/media'), $fileName);
                                        $mediaFiles[] = $fileName;
                                    } else {
                                        return response()->json(['error' => 'Invalid media file.'], 400);
                                    }
                                }

                                // Check if the total size of the uploaded files exceeds media_size
                                if ($totalSize > ($mediaSize * 1024 * 1024)) {
                                    return response()->json(['error' => 'Exceeded total media size limit.'], 400);
                                }

                                $customFieldData['field_meta_value'] = json_encode($mediaFiles);
                            } else {
                                return response()->json(['error' => 'No media files uploaded or invalid file data.'], 400);
                            }
                            break;
                        case 'file':
                            $filePath = '';

                            if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
                                $file = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');

                                if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                                    $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                    $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                                } else {
                                    return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
                                }
                            } else {
                                return response()->json(['error' => 'No files uploaded or invalid file data.'], 400);
                            }

                            $customFieldData['field_meta_value'] = $filePath;
                            break;

                        default:
                            return response()->json(['error' => 'Unsupported field type'], 400);
                    }

                    Customfieldvalue::create($customFieldData);
                }
            }

            $responseData = $property;

            $returnRes = [
                'status' => true,
                'message' => 'Data added successfully.',
                'data' => $request->all(),
                'code' => 200,
                
            ];

            return response()->json($returnRes, 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }       

    // this is for update the record 
    public function update(Request $request)
    {
        if ($request->header('api-token') == '') {
            return response()->json(['error' => 'Please enter api token first.'], 422);
        }
        
        $requestToken = $request->header('api-token');
        // $expectedToken = config('constants.API_TOKEN');
        
        if (!$requestToken) {
            return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
        }

        // Fetch required custom fields
        $requiredFields = DB::table('custom_fields')->where('required', 'yes')->pluck('id')->toArray();

        $validationErrors = [];

        if ($request->has('repeater_fields')) {
            $repeaterFields = $request->repeater_fields;

            foreach ($repeaterFields as $repeaterFieldIndex => $repeaterField) {
                $customFieldId = $repeaterField['custom_field_id'];
                $fieldValue = $repeaterField['field_value'];

                // Check if the field is required and has a null value
                if (in_array($customFieldId, $requiredFields) && is_null($fieldValue)) {
                    $customFieldName = DB::table('custom_fields')->where('id', $customFieldId)->value('field_name');
                    $validationErrors[$customFieldName] = $customFieldName . ' is required';
                }
            }

            if (!empty($validationErrors)) {
                return response()->json(['errors' => $validationErrors], 422);
            }
        }
        
        try {
            $userId = null;
        
            $userData = User::where('api_token', $requestToken)->first();
        
            $userId = $userData->id;
        
            // Validate that the user exists in the database
            if ($userId && !User::where('id', $userId)->exists()) {
                return response()->json(['error' => 'User not found'], 404);
            }
        
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
                'property_type_id' => 'nullable',
                'project_id' => 'nullable',
            ]);
        
            // $validatedData['user_id'] = $userId;
        
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
                $explod_keywords = explode(',', $request->keyword);
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
                                $filePath = '';

                                // Ensure the 'field_value' is set and it's an array
                                if ($request->hasFile('repeater_fields.' . $repeaterFieldIndex . '.field_value')) {
                                    // Retrieve the uploaded file object
                                    $file = $request->file('repeater_fields.' . $repeaterFieldIndex . '.field_value');

                                    // Ensure the file is a valid PDF
                                    if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                                        // Generate unique filename for the file
                                        $uniqueFileName = time() . '_' . $file->getClientOriginalName();

                                        // Move the file to the uploads/gallery directory
                                        $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                                    } else {
                                        return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
                                    }
                                } else {
                                    // Handle case where 'field_value' is not set
                                    return response()->json(['error' => 'No files uploaded or invalid file data.'], 400);
                                }

                                // Store the file path directly in the 'field_meta_value'
                                $customFieldData['field_meta_value'] = $filePath;
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
    // Check if the API token is provided
    if ($request->header('api-token') == '') {
        return response()->json(['error' => 'Please enter API token first.'], 422);
    }
    
    $requestToken = $request->header('api-token');
    $expectedToken = $requestToken;

    // Validate the API token
    if ($requestToken !== $expectedToken) {
        return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
    }

    $userData = User::where('api_token', $requestToken)->first();
    
    if (!$userData) {
        return response()->json(['error' => 'Invalid API token'], 401);
    }
   
    try {
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

        return response()->json($returnRes, 200);
    } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage()], 500);
    }
}

        
        
   


}
