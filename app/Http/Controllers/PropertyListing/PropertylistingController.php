<?php

namespace App\Http\Controllers\PropertyListing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PropertyList;
use App\Models\ProjectList;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;



class PropertylistingController extends Controller
{

    // this is for store the data
    public function store(Request $request)
    {
        // \Log::info($request->all());

        try {
            // Fetch authenticated user
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            // Fetch required fields dynamically from `model_fields` JSON column
            $requiredFields = DB::table('custom_fields')
                ->where('post_type', 'property_list')
                ->where('required', 'yes')
                ->get();

            $requiredFieldIds = [];

            foreach ($requiredFields as $field) {
                if (!empty($field->model_fields)) {
                    $modelFields = json_decode($field->model_fields, true);
                    foreach ($modelFields as $modelField) {
                        if (
                            isset($request->{$modelField['model']}) &&
                            in_array($request->{$modelField['model']}, $modelField['condition'])
                        ) {
                            $requiredFieldIds[] = $field->id;
                        }
                    }
                }
            }

            // Validation errors array
            $validationErrors = [];
            foreach ($requiredFieldIds as $customFieldId) {
                $customFieldName = DB::table('custom_fields')->where('id', $customFieldId)->value('field_name');
                if (empty($request->input("custom_fields.{$customFieldId}"))) {
                    $validationErrors[$customFieldId] = $customFieldName . ' is required';
                }
            }

            if (!empty($validationErrors)) {
                return response()->json(['errors' => $validationErrors], 422);
            }

            // Validate status_reason only when live_status is "Reject"
            $request->validate([
                'live_status' => 'required|in:Approve,Disapprove,Reject,Under Review,Modify Review',
                'status_reason' => $request->live_status === 'Reject' ? 'required|string' : 'nullable',
            ]);

            // Set `live_status` for non-admin users
            if ($user->role->name !== 'admin') {
                $request->merge(['live_status' => 'Under Review']);
            }

            // Generate a unique property ID
            $property_unique_id = 'URP' . rand(111111, 999999);
            // Handle `featured_image` (Store as `/uploads/properties/{file_name}`)

            if ($request->hasFile('featured_image')) {
                $file = $request->file('featured_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
                $file->move(public_path('uploads/properties'), $name); // Move file to folder

                // ✅ Store only `/uploads/properties/{file_name}`
                $featuredImage = '/uploads/properties/' . $name;
            } elseif (!empty($request->featured_image) && filter_var($request->featured_image, FILTER_VALIDATE_URL)) {
                $featuredImage = $request->featured_image; // Store URL directly if provided
            } else {
                $featuredImage = null; // If empty, store null
            }

            // Store property data
            $propertyData = [
                'property_unique_id' => $property_unique_id,
                'name' => $request->name,
                'description' => $request->description,
                'property_address' => $request->property_address,
                'purpose_id' => $request->purpose_id,
                'property_id' => $request->property_id,
                'property_status_id' => $request->property_status_id,
                'property_type_id' => $request->property_type_id,
                'live_status' => $request->live_status,
                'project_id' => $request->project_id,
                'country_id' => $request->country_id,
                'state_id' => $request->state_id,
                'city_id' => $request->city_id,
                'status_reason' => $request->status_reason,
                'created_by' => $user->id,
                'temporary_status' => $request->temporary_status,
                'featured_image' => $featuredImage, // ✅ Store as `/uploads/properties/{file_name}`
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

            // Create the property
            $property = PropertyList::create($propertyData);

            // Store keywords
            if (!empty($request->keyword)) {
                $keywords = explode(',', $request->keyword);
                foreach ($keywords as $keyword) {
                    Keyword::create(['property_id' => $property->id, 'keyword' => $keyword]);
                }
            }

            // Handle repeater fields
            if ($request->has('repeater_fields')) {
                foreach ($request->repeater_fields as $repeaterField) {
                    $customFieldData = [
                        'properties_listing_id' => $property->id,
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
                'data' => $property,
            ], 201);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }




    // this is for listing
    public function indexByAdmin(Request $request)
    {
        try {
            $baseURL = url('/'); // ✅ Get full base URL dynamically

            // Fetch all projects in descending order by created_at
            $projects = PropertyList::with([
                'location',
                'user',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'customFieldValues.customField',
                'customFieldValues.customFieldOption',
                'importKeywords',
                'developer.userDetails',
                'createdBy.role',
                'updatedBy.role'
            ])
                ->orderBy('created_at', 'desc') // 🔹 Sorting by latest first
                ->get();

            $projectsData = $projects->map(function ($property) use ($baseURL) {
                return [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'live_status' => $property->live_status,
                    'status_reason' => $property->status_reason,
                    'temporary_status' => $property->temporary_status,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'created_by' => $property->created_by,
                    'created_by_role' => optional($property->createdBy)->role->name ?? 'N/A',
                    'updated_by' => $property->updated_by,
                    'updated_by_role' => optional($property->updatedBy)->role->name ?? 'N/A',
                    'listed_by' => optional(optional($property->user)->role)->name ?? 'N/A',
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:i A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
                    'keyword' => $property->importKeywords,
                    'featured_image' => !empty($property->featured_image)
                        ? (filter_var($property->featured_image, FILTER_VALIDATE_URL)
                            ? $property->featured_image // ✅ If it's already a full URL, use as is
                            : $baseURL . $property->featured_image) // ✅ Convert relative path to full URL
                        : null,
                ];
            });

            return response()->json($projectsData);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()
            ], 500);
        }
    }





    public function index(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Fetch only properties where live_status is "Approve"
            $properties = PropertyList::with([
                'location',
                'user',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'project',
                'customFieldValues.customField',
                'customFieldValues.customFieldOption',
                'importKeywords'
            ])
                ->where('live_status', 'Approve') // Filter properties
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

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
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'live_status' => $property->live_status,
                    'temporary_status' => $property->temporary_status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'created_by' => $property->created_by,
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
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'keyword' => $property->importKeywords,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }




    // Function to append base URL to gallery images
    private function appendBaseURL($gallery, $baseURL)
    {
        return array_map(function ($image) use ($baseURL) {
            return $baseURL . '/public/uploads/gallery/' . $image;
        }, $gallery);
    }



    // Function to correct file paths for images and videos
    private function correctFilePath($filePath, $baseURL, $basePath, $Fname)
    {
        $publicPath = $basePath . '/public/';
        if (strpos($filePath, $publicPath) !== false) {
            $relativePath = str_replace($publicPath, '', $filePath);
            return $baseURL . '/' . $relativePath;
        }
        return $baseURL . '/public/uploads/' . $Fname . '/' . $filePath;
    }



    public function update(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            // Check if the user is an admin or not
            $isAdmin = $user->role->name === 'admin';

            // If not admin, set live_status to 'Modify Review'
            if (!$isAdmin) {
                $request->merge(['live_status' => 'Modify Review']);
            }

            // Validate request data
            $validatedData = $request->validate([
                'id' => 'required|exists:properties_listing,id',
                'name' => 'required|string',
                'description' => 'required|string',
                'property_address' => 'nullable|string',
                'purpose_id' => 'nullable|exists:purposes,id',
                'property_id' => 'nullable|exists:properties,id',
                'property_status_id' => 'nullable',
                'property_type_id' => 'nullable',
                'project_id' => 'nullable|exists:projects,id',
            ]);

            // Find the property by ID
            $property = PropertyList::findOrFail($request->id);

            // ✅ Handle `featured_image` properly
            if ($request->hasFile('featured_image')) {
                $file = $request->file('featured_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
                $file->move(public_path('uploads/properties'), $name);
                $validatedData['featured_image'] = '/uploads/properties/' . $name; // Store only relative path
            } elseif (!empty($request->featured_image) && filter_var($request->featured_image, FILTER_VALIDATE_URL)) {
                $validatedData['featured_image'] = $request->featured_image; // Store full URL if provided
            }

            // Handle file uploads for other fields
            foreach (['property_video', 'virtual_tour', 'video_thumbnail', 'brochure'] as $fileField) {
                if ($request->hasFile($fileField)) {
                    $file = $request->file($fileField);
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $file->move(public_path('uploads/' . $fileField), $fileName);
                    $validatedData[$fileField] = '/uploads/' . $fileField . '/' . $fileName; // Store only relative path
                }
            }

            // Update property data
            $property->update(array_merge($validatedData, [
                'country_id' => $request->country_id,
                'state_id' => $request->state_id,
                'city_id' => $request->city_id,
                'live_status' => $request->live_status,
                'status_reason' => $request->status_reason,
                'updated_by' => $user->id,
                'temporary_status' => $request->temporary_status,
            ]));

            // Handle keywords
            if (!empty($request->keyword)) {
                Keyword::where('property_id', $property->id)->delete();
                foreach (explode(',', $request->keyword) as $keyword) {
                    Keyword::create(['property_id' => $property->id, 'keyword' => $keyword]);
                }
            }

            // Handle repeater field values
            if ($request->has('repeater_fields')) {
                Customfieldvalue::where('properties_listing_id', $property->id)->delete();
                foreach ($request->repeater_fields as $repeaterField) {
                    $customFieldData = [
                        'properties_listing_id' => $property->id,
                        'custom_field_id' => $repeaterField['custom_field_id'],
                    ];

                    // Handle different field types
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
                            $customFieldData['custom_field_options_id'] = DB::table('custom_field_options')
                                ->whereIn('value', $values)
                                ->where('custom_field_id', $repeaterField['custom_field_id'])
                                ->pluck('id')
                                ->implode(',');
                            break;
                    }

                    Customfieldvalue::create($customFieldData);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Data updated successfully.',
                'data' => $property
            ]);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // this is for delete the record
    public function destroy(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        try {
            $id = $request->id;
            $property = PropertyList::find($id);

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


    // this is foe get data by id
    public function getdatabyId(Request $request)
    {
        try {
            if (empty($request->id)) {
                return response()->json(['error' => 'ID is required'], 400);
            }

            $baseURL = config('app.url'); // Base URL for constructing full paths

            $property = PropertyList::with([
                'location',
                'user',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'project',
                'customFieldValues.customField',
                'customFieldValues.customFieldOption',
                'importKeywords',
                'createdBy',
                'updatedBy'
            ])
                ->where('id', $request->id)
                ->first();

            if (!$property) {
                return response()->json(['error' => 'Property not found'], 404);
            }

            // ✅ Handle Created By and Updated By
            $createdByData = optional($property->createdBy) ? [
                'id' => optional($property->createdBy)->id,
                'name' => optional($property->createdBy)->name,
                'email' => optional($property->createdBy)->email,
                'role' => optional(optional($property->createdBy)->role)->name,
            ] : null;

            $updatedByData = optional($property->updatedBy) ? [
                'id' => optional($property->updatedBy)->id,
                'name' => optional($property->updatedBy)->name,
                'email' => optional($property->updatedBy)->email,
                'role' => optional(optional($property->updatedBy)->role)->name,
            ] : null;

            // ✅ Ensure `featured_image` shows full URL
            if (!empty($property->featured_image)) {
                $property->featured_image = filter_var($property->featured_image, FILTER_VALIDATE_URL)
                    ? $property->featured_image
                    : url($property->featured_image);
            }

            // ✅ Fetch repeater fields dynamically
            $repeaterFields = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                $customField = optional($customFieldValue->customField);
                $fieldType = $customField->field_type ?? 'unknown';
                $fieldValue = $customFieldValue->field_meta_value ?? '';
                $allAvailableOptions = [];

                // ✅ Fetch all available options dynamically
                $availableOptions = DB::table('custom_field_options')
                    ->where('custom_field_id', $customFieldValue->custom_field_id)
                    ->where('status', 1) // Only fetch active options
                    ->get(['id', 'name', 'value']);

                if ($availableOptions->isNotEmpty()) {
                    foreach ($availableOptions as $option) {
                        $allAvailableOptions[] = [
                            'name' => $option->name,
                            'value' => $option->value,
                        ];
                    }
                }

                // ✅ Handle multiple field types correctly
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
                        $fieldValue = array_map(fn($fileName) => $baseURL . '/uploads/media/' . $fileName, (array) $fieldValue);
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
                'id' => $property->id,
                'property_unique_id' => $property->property_unique_id,
                'property_name' => $property->name,
                'description' => $property->description,
                'country_id' => $property->country_id,
                'state_id' => $property->state_id,
                'city_id' => $property->city_id,
                'location_id' => $property->location_id,
                'location_name' => optional($property->location)->name,
                'address' => $property->property_address,
                'live_status' => $property->live_status,
                'temporary_status' => $property->temporary_status,
                'status_reason' => $property->status_reason,
                'user_id' => $property->user_id,
                'listed_by' => optional(optional($property->user)->role)->name,
                'featured_image' => $property->featured_image, // ✅ Full URL
                'purpose_id' => $property->purpose_id,
                'purpose_id_name' => optional($property->purpose)->name,
                'property_id' => $property->property_id,
                'property_id_name' => optional($property->property)->name,
                'property_status_id' => $property->property_status_id,
                'property_status_id_name' => optional($property->propertystatus)->name,
                'property_type_id' => $property->property_type_id,
                'property_type_id_name' => optional($property->propertyType)->name,
                'posted_on' => date('d M, Y', strtotime($property->created_at)),
                'project_id' => $property->project_id,
                'project_id_name' => optional($property->project)->name,
                'total_view' => $property->analytics()->count(),
                'keyword' => $property->importKeywords->pluck('id')->toArray() ?? null,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'repeater_fields' => $repeaterFields, // ✅ Include repeater fields in response with options
            ]);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function getUserProperties(Request $request)
    {
        try {
            // Authenticate the user
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized.'], 401);
            }

            // Fetch properties where created_by or updated_by matches the user ID
            $properties = PropertyList::where('created_by', $user->id)
                ->orWhere('updated_by', $user->id)
                ->get();

            // If no properties found, return empty array
            if ($properties->isEmpty()) {
                return response()->json(['message' => 'No properties found.'], 200);
            }

            return response()->json([
                'status' => true,
                'message' => 'Properties retrieved successfully.',
                'data' => $properties
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function getPropertyStatuses()
    {
        try {
            // Get live_status enum values dynamically from the properties_listing table
            $enumValues = DB::select("SHOW COLUMNS FROM properties_listing WHERE Field = 'live_status'");

            if (!empty($enumValues)) {
                // Extract enum values from the Type column (e.g., enum('Approve','Disapprove','Reject','Under Review','Modify Review'))
                preg_match("/^enum\((.*)\)$/", $enumValues[0]->Type, $matches);
                $statuses = array_map(function ($value) {
                    return trim($value, "'");
                }, explode(',', $matches[1]));

                return response()->json([
                    'status' => true,
                    'message' => 'Live statuses retrieved successfully',
                    'data' => $statuses
                ], 200);
            }

            return response()->json([
                'status' => false,
                'message' => 'No live statuses found in database'
            ], 404);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile()
            ], 500);
        }
    }



    public function updateTemporaryStatus(Request $request)
    {
        try {
            // Validate the request
            $validatedData = $request->validate([
                'property_id' => 'required|exists:properties_listing,id',  // Ensure property exists
                'temporary_status' => 'required|string',  // Temporary status is required
            ]);

            // Fetch allowed enum values dynamically
            $enumValues = DB::select("SHOW COLUMNS FROM properties_listing WHERE Field = 'temporary_status'");

            if (!empty($enumValues)) {
                $type = $enumValues[0]->Type; // Get enum type definition
                preg_match('/enum\((.*)\)/', $type, $matches);
                $allowedStatuses = str_getcsv($matches[1], ",", "'");
            } else {
                return response()->json(['error' => 'Failed to fetch temporary_status values'], 500);
            }

            // Check if provided status is valid
            if (!in_array($request->temporary_status, $allowedStatuses)) {
                return response()->json([
                    'error' => "Invalid temporary_status. Allowed values: " . implode(', ', $allowedStatuses)
                ], 422);
            }

            // Find the property and update the status
            $property = PropertyList::findOrFail($request->property_id);
            $property->temporary_status = $request->temporary_status;
            $property->save();

            return response()->json([
                'status' => true,
                'message' => 'Temporary status updated successfully',
                'data' => $property
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile()
            ], 500);
        }
    }


    public function getTemporaryStatuses()
    {
        try {
            // Fetch enum values dynamically from database
            $enumValues = DB::select("SHOW COLUMNS FROM properties_listing WHERE Field = 'temporary_status'");

            if (!empty($enumValues)) {
                $type = $enumValues[0]->Type; // Get type definition (enum('value1', 'value2', ...))
                preg_match('/enum\((.*)\)/', $type, $matches); // Extract enum values
                $statuses = str_getcsv($matches[1], ",", "'"); // Convert to array
            } else {
                return response()->json(['error' => 'Failed to fetch temporary_status values'], 500);
            }

            return response()->json([
                'status' => true,
                'message' => 'Temporary statuses retrieved successfully',
                'data' => $statuses
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile()
            ], 500);
        }
    }

    public function updatePropertyStatus(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'id' => 'required|exists:properties_listing,id',  // Ensure the property exists in the table
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
            $propertyData = PropertyList::where('id', $request->id)->first();

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


    // this is for all project listing by location iD
//     public function getAllProjectByLocationId(Request $request)
//     {
//         try {
//             $baseURL = config('app.url');
//             $basePath = public_path();
//             $projectData= [];

    //             $location = Location::where('id',$request->location_id)->first();

    //             if(!$location){
//                 return response()->json(['error' => 'Location not found'], 404);
//             }

    //             $projects = ProjectList::where('location_id',$request->location_id)->get();


    //             if(count($projects)){
//                 foreach($projects as $row){
//                     $projectData[] = [ // Append to array using []
//                       'id' => $row->id,
//                       'name' => $row->name,
//                     ];

    //                 }
//             }

    //             return response()->json($projectData);

    //         } catch (\Throwable $th) {
//             return response()->json(['error' => $th->getMessage()], 500);
//         }
// }
    public function getAllProjectByLocationId(Request $request)
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
            $query = ProjectList::query();

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


    // this is for comapny project listing by location iD
    public function getComapnyProjectByLocationId(Request $request)
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
                return response()->json(['error' => 'Company not found'], 404);
            }

            $userId = $userData->id;

            $baseURL = config('app.url');
            $basePath = public_path();
            $projectData = [];

            $location = Location::where('id', $request->location_id)->first();

            if (!$location) {
                return response()->json(['error' => 'Location not found'], 404);
            }

            $user = User::where('id', $userId)->first();

            $projects = ProjectList::where('location_id', $request->location_id)
                ->where('user_id', $userId)
                ->get();

            if (count($projects)) {
                foreach ($projects as $row) {
                    $projectData[] = [ // Append to array using []
                        'id' => $row->id,
                        'name' => $row->name,
                    ];
                }
            }

            return response()->json($projectData);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function bulkDelete(Request $request)
    {

        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (strpos($authorizationHeader, 'Bearer ') !== 0) {
            return response()->json(['error' => 'Invalid token format.'], 422);
        }

        // Extract the token by removing "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        try {
            // Find the builder by ID
            $delete_ids = explode(',', $request->id);

            foreach ($delete_ids as $row) {
                $purpose = PropertyList::findOrFail($row);
                // Delete the builder record
                $purpose->customFieldValues()->delete();
                // Delete the property
                $purpose->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Lisitng bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'purpose not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }



}
