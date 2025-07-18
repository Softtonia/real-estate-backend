<?php

namespace App\Http\Controllers\DeveloperListing;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class DeveloperlistingController extends Controller
{



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

            // featured image handle

            if ($request->hasFile('featured_image')) {
                $file = $request->file('featured_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
                $file->move(public_path('uploads/developers'), $name); // Move file to folder

                // ✅ Store only `/uploads/developers/{file_name}`
                $featuredImage = '/uploads/developers/' . $name;
            } elseif (!empty($request->featured_image) && filter_var($request->featured_image, FILTER_VALIDATE_URL)) {
                $featuredImage = $request->featured_image; // Store URL directly if provided
            } else {
                $featuredImage = null; // If empty, store null
            }

            // Prepare developer data
            $developerData = array_merge($validatedData, [
                'developer_unique_id' => $developer_unique_id,
                'user_id' => $userId,
                'created_by' => $userId, // Store the authenticated user's ID
                'address' => $request->address,
                'featured_image' => $featuredImage,
            ]);

            // ✅ Create the developer listing FIRST
            $developer = Developerlist::create($developerData);



            // ✅ Handle repeater fields AFTER the project is created
            if ($request->has('repeater_fields')) {
                foreach ($request->repeater_fields as $index => $repeaterField) {
                    $customFieldData = [
                        'developer_listing_id' => $developer->id, // ✅ FIXED: Use `developer_listing_id` instead of `properties_listing_id`
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

                        // case 'media':
                        //     if ($request->hasFile("repeater_fields.{$repeaterField['custom_field_id']}.field_value")) {
                        //         $files = $request->file("repeater_fields.{$repeaterField['custom_field_id']}.field_value");
                        //         $fileNames = [];
                        //         foreach ($files as $file) {
                        //             if ($file->isValid()) {
                        //                 $fileName = time() . '_' . $file->getClientOriginalName();
                        //                 $file->move(public_path('uploads/media'), $fileName);
                        //                 $fileNames[] = $fileName;
                        //             }
                        //         }
                        //         $customFieldData['field_meta_value'] = json_encode($fileNames);
                        //     }
                        //     break;

                        // case 'file':
                        //     if ($request->hasFile("repeater_fields.{$repeaterField['custom_field_id']}.field_value")) {
                        //         $file = $request->file("repeater_fields.{$repeaterField['custom_field_id']}.field_value");
                        //         if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                        //             $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                        //             $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                        //             $customFieldData['field_meta_value'] = $filePath;
                        //         } else {
                        //             return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
                        //         }
                        //     }

                        case 'media':
                            if ($request->hasFile("repeater_fields.$index.field_value")) {
                                $files = $request->file("repeater_fields.$index.field_value");
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

                        // ✅ FIXED: Use index to access file
                       case 'file':
                        if ($request->hasFile("repeater_fields.$index.field_value")) {
                            $files = $request->file("repeater_fields.$index.field_value");
                            $filePaths = [];

                            foreach ((array) $files as $file) {
                                if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                    if (in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'doc', 'docx'])) {
                                        $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                        $relativePath = 'storage/uploads/customfield/developers/files/' . $uniqueFileName;

                                        // Store to: storage/app/public/uploads/customfield/properties/files
                                        $file->storeAs('public/uploads/customfield/developers/files', $uniqueFileName);

                                        $filePaths[] = $relativePath;
                                    } else {
                                        return response()->json([
                                            'error' => 'Invalid file format. Only PDF, DOC, DOCX files are allowed.'
                                        ], 400);
                                    }
                                }
                            }

                            $customFieldData['field_meta_value'] = json_encode($filePaths); // Save JSON-encoded array
                        }
                        break;


                        case 'repeater':
                            if (!empty($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $row) {
                                    $uniqueRowId = uniqid('repeater_');
                                    foreach ($row as $subField) {
                                        $repeaterFieldData = [
                                            'developer_listing_id' => $developer->id, // ✅ developer specific
                                            'custom_field_id' => $subField['sub_field_id'],
                                            'custom_field_repeater_id' => $repeaterField['custom_field_id'],
                                            'field_type' => $subField['field_type'],
                                            'unique_id' => $uniqueRowId,
                                        ];

                                        switch ($subField['field_type']) {
                                            case 'text':
                                            case 'textarea':
                                            case 'texteditor':
                                                $repeaterFieldData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'select':
                                            case 'radio':
                                                $option = DB::table('custom_field_repeater_options')
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->where('value', $subField['field_value'])
                                                    ->first();
                                                if ($option) {
                                                    $repeaterFieldData['custom_field_repeater_options_id'] = $option->id;
                                                }
                                                $repeaterFieldData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'checkbox':
                                                $values = explode(',', $subField['field_value']);
                                                $repeaterFieldData['field_meta_value'] = implode(',', $values);
                                                $optionIds = DB::table('custom_field_repeater_options')
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->whereIn('value', $values)
                                                    ->pluck('id')
                                                    ->implode(',');
                                                $repeaterFieldData['custom_field_repeater_options_id'] = $optionIds;
                                                break;

                                            case 'media':
                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    $fileNames = [];
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            $fileName = time() . '_' . $file->getClientOriginalName();
                                                            $file->move(public_path('uploads/media'), $fileName);
                                                            $fileNames[] = $fileName;
                                                        }
                                                    }
                                                    $repeaterFieldData['field_meta_value'] = json_encode($fileNames);
                                                }
                                                break;

                                           case 'file':
                                                if (isset($subField['field_value'])) {
                                                    $files = $subField['field_value'];
                                                    $filePaths = [];

                                                    foreach ((array) $files as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            if (in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'doc', 'docx'])) {
                                                                $fileName = time() . '_' . $file->getClientOriginalName();
                                                                $relativePath = 'storage/uploads/customfield/developers/files/' . $fileName;

                                                                // Store in: storage/app/public/uploads/customfield/properties/files
                                                                $file->storeAs('public/uploads/customfield/developers/files', $fileName);

                                                                $filePaths[] = $relativePath;
                                                            } else {
                                                                return response()->json(['error' => 'Invalid file format in repeater. Only PDF, DOC, DOCX allowed.'], 400);
                                                            }
                                                        }
                                                    }

                                                    $repeaterFieldData['field_meta_value'] = json_encode($filePaths); // Save JSON-encoded paths
                                                }
                                                break;

                                        }

                                        CustomFieldRepeaterValues::create($repeaterFieldData);
                                    }
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
                'data' => $developer
            ], 201);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // this is for listing
    // public function index(Request $request)
    // {

    //     try {
    //         $baseURL = config('app.url');
    //         $basePath = public_path();

    //         // Fetch only listings where live_status is "Approve"
    //         $projects = Developerlist::where('live_status', 'Approve')
    //             ->with([
    //                 'user',
    //                 'propertyType',
    //                 'purpose',
    //                 'property',
    //                 'propertystatus',
    //                 'customFieldValues.customField',
    //                 'customFieldValues.customFieldOption',
    //                 'country', // Add country relationship
    //                 'state',   // Add state relationship
    //                 'city',    // Add city relationship
    //                 'createdBy.role', // ✅ Include creator's role
    //                 'updatedBy.role'  // ✅ Include updater's role
    //             ])
    //             ->get();

    //         $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
    //             $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
    //                 $customField = $customFieldValue->customField;

    //                 $fieldValue = $customFieldValue->field_meta_value;
    //                 if ($customField && $customField->field_type == 'checkbox') {
    //                     $fieldValueArray = explode(',', $fieldValue);
    //                 } elseif ($customField && $customField->field_type == 'media') {
    //                     $fieldValueArray = json_decode($fieldValue);
    //                     $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
    //                         return $baseURL . '/uploads/media/' . $file;
    //                     });
    //                 } else {
    //                     $fieldValueArray = $fieldValue;
    //                 }

    //                 return [
    //                     'custom_field_id' => $customField ? $customField->id : null,
    //                     'field_type' => $customField ? $customField->field_type : null,
    //                     'field_value' => $fieldValueArray,
    //                     'field_name' => $customField ? $customField->field_label : null,
    //                 ];
    //             });

    //             // Prepare property data
    //             return [
    //                 'id' => $property->id,
    //                 'developer_unique_id' => $property->developer_unique_id,
    //                 'name' => $property->name,
    //                 'description' => $property->description,
    //                 'live_status' => $property->live_status,
    //                 'temporary_status' => $property->temporary_status,
    //                 'status_reason' => $property->status_reason,
    //                 'user_id' => $property->user_id,
    //                 'created_by' => $property->created_by,
    //                 'created_by_role' => optional(optional($property->createdBy)->role)->name,
    //                 'updated_by' => $property->updated_by,
    //                 'updated_by_role' => optional(optional($property->updatedBy)->role)->name,
    //                 'listed_by' => optional(optional($property->user)->role)->name,
    //                 'purpose_id' => $property->purpose_id,
    //                 'purpose_id_name' => optional($property->purpose)->name,
    //                 'property_id' => $property->property_id,
    //                 'property_id_name' => optional($property->property)->name,
    //                 'property_status_id' => $property->property_status_id,
    //                 'property_status_id_name' => optional($property->propertystatus)->name,
    //                 'property_type_id' => $property->property_type_id,
    //                 'property_type_id_name' => optional($property->propertyType)->name,

    //                 // Newly added fields
    //                 'address' => $property->address,
    //                 'country_id' => $property->country_id,
    //                 'country_name' => optional($property->country)->name,
    //                 'state_id' => $property->state_id,
    //                 'state_name' => optional($property->state)->name,
    //                 'city_id' => $property->city_id,
    //                 'city_name' => optional($property->city)->name,

    //                 'date' => date('d m Y', strtotime($property->created_at)),
    //                 'time' => date('h:i A', strtotime($property->created_at)),
    //                 'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
    //                 'custom_field_values' => $formattedCustomFieldValues,
    //             ];
    //         });

    //         return response()->json($projectsData);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
    //     }
    // }

    ### new index code ###
    public function index(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Fetch only listings where live_status is "Approve"
            $developers = Developerlist::where('live_status', 'Approve')
                ->with([
                    'user',
                    'propertyType',
                    'purpose',
                    'property',
                    'propertystatus',
                    'customFieldValues.customField.templateValue',
                    'customFieldValues.customFieldOption',
                    'country',
                    'state',
                    'city',
                    'createdBy.role',
                    'updatedBy.role'
                ])->paginate($request->get('per_page', 10));

            $developersData = $developers->map(function ($developer) use ($baseURL) {
                $formattedCustomFieldValues = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL, $developer) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;
                    $fieldType = $customField->field_type ?? null;
                    $fieldValue = $customFieldValue->field_meta_value;

                    $templateData = optional(optional($customField)->templateValue)?->toArray();
                    $templateId = optional(optional($customField)->templateValue)?->id;


                    $fieldValueFormatted = null;
                    $options = [];

                    switch ($fieldType) {
                        case 'checkbox':
                            $ids = explode(',', $customFieldValue->custom_field_options_id);
                            $fieldValueFormatted = DB::table('custom_field_options')
                                ->whereIn('id', $ids)
                                ->pluck('name')
                                ->toArray();
                            break;

                        case 'select':
                        case 'radio':
                            $fieldValueFormatted = optional($customFieldOption)->name ?? null;
                            $options = DB::table('custom_field_options')
                                ->where('custom_field_id', $customFieldValue->custom_field_id)
                                ->get(['name', 'value'])
                                ->map(fn($opt) => [
                                    'name' => $opt->name,
                                    'value' => $opt->value,
                                ])->toArray();
                            break;

                        case 'media':
                            $decoded = json_decode($fieldValue, true);
                            $fieldValueFormatted = is_array($decoded)
                                ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                                : [];
                            break;
                        case 'file':
                            $decoded = json_decode($fieldValue, true);
                            $fieldValueFormatted = is_array($decoded)
                                ? array_map(fn($file) => $baseURL . '/' . $file, $decoded)
                                : [];
                            break;

                        case 'repeater':
                            $nestedRows = DB::table('custom_field_repeater_values')
                                ->where('custom_field_repeater_id', $customField->id)
                                ->where('developer_listing_id', $developer->id)
                                ->get()
                                ->groupBy('unique_id');

                            $repeaterData = [];

                            foreach ($nestedRows as $groupId => $rows) {
                                $groupData = [];

                                foreach ($rows as $row) {
                                    $nestedOptions = [];
                                    $nestedValue = $row->field_meta_value;
                                    $nestedType = $row->field_type;

                                    if (in_array($nestedType, ['select', 'radio'])) {
                                        $opt = DB::table('custom_field_repeater_options')
                                            ->where('id', $row->custom_field_repeater_options_id)
                                            ->first();
                                        $nestedValue = optional($opt)->name ?? $nestedValue;

                                        $nestedOptions = DB::table('custom_field_repeater_options')
                                            ->where('custom_field_repeater_id', $row->custom_field_id)
                                            ->get(['name', 'value'])
                                            ->map(fn($opt) => [
                                                'name' => $opt->name,
                                                'value' => $opt->value,
                                            ])->toArray();
                                    } elseif ($nestedType === 'checkbox') {
                                        $ids = explode(',', $row->custom_field_repeater_options_id);
                                        $nestedValue = DB::table('custom_field_repeater_options')
                                            ->whereIn('id', $ids)
                                            ->pluck('name')
                                            ->toArray();

                                        $nestedOptions = DB::table('custom_field_repeater_options')
                                            ->where('custom_field_repeater_id', $row->custom_field_id)
                                            ->get(['name', 'value'])
                                            ->map(fn($opt) => [
                                                'name' => $opt->name,
                                                'value' => $opt->value,
                                            ])->toArray();
                                    }

                                    elseif ($nestedType === 'file') {
                                    $decoded = is_string($nestedValue) ? json_decode($nestedValue, true) : $nestedValue;
                                    $nestedValue = is_array($decoded)
                                        ? array_map(fn($file) => url($file), $decoded)
                                        : [];
                                } elseif ($nestedType === 'media') {

                                    $decoded = is_string($nestedValue) ? json_decode($nestedValue, true) : $nestedValue;
                                    $nestedValue = is_array($decoded)
                                        ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                                        : [];
                                }



                                    $subField = DB::table('custom_fields')->where('id', $row->custom_field_id)->first();
                                    $template = $subField && $subField->template_id
                                        ? DB::table('custom_field_unique_codes')->where('id', $subField->template_id)->first()
                                        : null;

                                    $groupData[] = [
                                        'sub_field_id' => $row->custom_field_id,
                                        'field_type' => $nestedType,
                                        'field_value' => $nestedValue,
                                        'options' => $nestedOptions,
                                        'template_id' => $subField->template_id ?? null,
                                        'template' => $template,
                                    ];
                                }

                                $repeaterData[] = $groupData;
                            }

                            $fieldValueFormatted = $repeaterData;
                            break;

                        default:
                            $fieldValueFormatted = $fieldValue;
                            break;
                    }

                    $fieldArray = [
                        'custom_field_id' => optional($customField)->id,
                        'field_type' => $fieldType,
                        'field_value' => $fieldValueFormatted,
                        'field_name' => optional($customField)->field_label,
                        'placeholder' => optional($customField)->field_placeholder,
                        'template_id' => $templateId,
                        'template' => $templateData,
                        'options' => $options,
                    ];

                    if ($fieldType === 'checkbox') {
                        $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
                    }

                    return $fieldArray;
                });

                return [
                    'id' => $developer->id,
                    'developer_unique_id' => $developer->developer_unique_id,
                    'name' => $developer->name,
                    'description' => $developer->description,
                    'live_status' => $developer->live_status,
                    'temporary_status' => $developer->temporary_status,
                    'status_reason' => $developer->status_reason,
                    'user_id' => $developer->user_id,
                    'created_by' => $developer->created_by,
                    'created_by_role' => optional(optional($developer->createdBy)->role)->name,
                    'updated_by' => $developer->updated_by,
                    'updated_by_role' => optional(optional($developer->updatedBy)->role)->name,
                    'listed_by' => optional(optional($developer->user)->role)->name,
                    'purpose_id' => $developer->purpose_id,
                    'purpose_id_name' => optional($developer->purpose)->name,
                    'property_id' => $developer->property_id,
                    'property_id_name' => optional($developer->property)->name,
                    'property_status_id' => $developer->property_status_id,
                    'property_status_id_name' => optional($developer->propertystatus)->name,
                    'property_type_id' => $developer->property_type_id,
                    'property_type_id_name' => optional($developer->propertyType)->name,
                    'address' => $developer->address,
                    'country_id' => $developer->country_id,
                    'country_name' => optional($developer->country)->name,
                    'state_id' => $developer->state_id,
                    'state_name' => optional($developer->state)->name,
                    'city_id' => $developer->city_id,
                    'city_name' => optional($developer->city)->name,
                    'date' => date('d m Y', strtotime($developer->created_at)),
                    'time' => date('h:i A', strtotime($developer->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($developer->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json([
                'data' => $developersData,
                'meta' => [
                    'current_page' => $developers->currentPage(),
                    'from' => $developers->firstItem(),
                    'last_page' => $developers->lastPage(),
                    'path' => $request->url(),
                    'per_page' => $developers->perPage(),
                    'to' => $developers->lastItem(),
                    'total' => $developers->total(),
                ],
                'links' => [
                    'first' => $developers->url(1),
                    'last' => $developers->url($developers->lastPage()),
                    'prev' => $developers->previousPageUrl(),
                    'next' => $developers->nextPageUrl(),
                ]

            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
        }
    }

    ### end index code ###

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
                        'field_name' => $customField ? $customField->field_label : null,
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

                    'address' => $property->address,

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
                    'top_featured_id' => $property->top_featured_id,
                    'featured' => $property->top_featured_id !== null, // true if not null, else false
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
            $user = Auth::user();
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
            $developer = Developerlist::find($id);
            if (!$developer) {
                return response()->json(['error' => 'Invalid Developer Id'], 404);
            }

            if ($user->role->name !== 'admin' && $developer->created_by !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only update your own developers.',
                ], 403);
            }



            // Store old image path
            $oldImage = $developer->featured_image;

            // Handle image upload
            if ($request->hasFile('featured_image')) {
                $file = $request->file('featured_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('uploads/developers'), $name);
                $featuredImage = '/uploads/developers/' . $name;

                // ✅ Delete old image if exists and not a URL
                if (!empty($oldImage) && File::exists(public_path($oldImage))) {
                    File::delete(public_path($oldImage));
                }
            } elseif (!empty($request->featured_image) && filter_var($request->featured_image, FILTER_VALIDATE_URL)) {
                $featuredImage = $request->featured_image;
            } else {
                $featuredImage = $oldImage; // Keep old if nothing new provided
            }

            // Handle file uploads
            $fileFields = ['property_video', 'virtual_tour', 'video_thumbnail', 'brochure'];
            foreach ($fileFields as $fileField) {
                if ($request->hasFile($fileField)) {
                    $file = $request->file($fileField);
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $file->move(public_path('uploads/' . $fileField), $fileName);
                    $developer->$fileField = $fileName;
                }
            }

            // Update developer data and store updated_by
            $developer->update([
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
                'featured_image' => $featuredImage,
            ]);

            // Handle repeater fields (custom fields)
            if ($request->has('repeater_fields')) {
                // Delete existing custom and repeater field values
                Customfieldvalue::where('developer_listing_id', $developer->id)->delete();
                CustomFieldRepeaterValues::where('developer_listing_id', $developer->id)->delete();

                foreach ($request->repeater_fields as $repeaterField) {
                    $customFieldData = [
                        'developer_listing_id' => $developer->id,
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
                            $customFieldData['custom_field_options_id'] = DB::table('custom_field_options')
                                ->whereIn('value', $values)
                                ->where('custom_field_id', $repeaterField['custom_field_id'])
                                ->pluck('id')
                                ->implode(',');
                            break;

                        case 'media':
                            if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                $fileNames = [];
                                foreach ($repeaterField['field_value'] as $file) {
                                    if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                        $fileName = time() . '_' . $file->getClientOriginalName();
                                        $file->move(public_path('uploads/media'), $fileName);
                                        $fileNames[] = $fileName;
                                    }
                                }
                                $customFieldData['field_meta_value'] = json_encode($fileNames);
                            }
                            break;

                        case 'file':
                            if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                $filePaths = [];
                                foreach ($repeaterField['field_value'] as $file) {
                                    if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                        if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                            $fileName = time() . '_' . $file->getClientOriginalName();
                                            $relativePath = 'storage/uploads/customfield/developers/files/' . $fileName;

                                            // Store the file in the desired folder
                                            $file->storeAs('public/uploads/customfield/developers/files', $fileName); // goes to storage/app/public/uploads/customFiles

                                            $filePaths[] = $relativePath;
                                        } else {
                                            return response()->json(['error' => 'Invalid file format. Only PDF, DOC, DOCX allowed.'], 400);
                                        }
                                    }
                                }
                                $customFieldData['field_meta_value'] = json_encode($filePaths); // store array of relative paths
                            }
                            break;

                        case 'repeater':
                            if (!empty($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $row) {
                                    $uniqueRowId = uniqid('repeater_');
                                    foreach ($row as $subField) {
                                        $repeaterFieldData = [
                                            'custom_field_id' => $subField['sub_field_id'],
                                            'custom_field_repeater_id' => $repeaterField['custom_field_id'],
                                            'field_type' => $subField['field_type'],
                                            'unique_id' => $uniqueRowId,
                                            'developer_listing_id' => $developer->id,
                                        ];

                                        switch ($subField['field_type']) {
                                            case 'text':
                                            case 'textarea':
                                            case 'texteditor':
                                                $repeaterFieldData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'select':
                                            case 'radio':
                                                $option = DB::table('custom_field_repeater_options')
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->where('value', $subField['field_value'])
                                                    ->first();
                                                if ($option) {
                                                    $repeaterFieldData['custom_field_repeater_options_id'] = $option->id;
                                                }
                                                $repeaterFieldData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'checkbox':
                                                $values = explode(',', $subField['field_value']);
                                                $repeaterFieldData['field_meta_value'] = implode(',', $values);
                                                $optionIds = DB::table('custom_field_repeater_options')
                                                    ->whereIn('value', $values)
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->pluck('id')
                                                    ->implode(',');
                                                $repeaterFieldData['custom_field_repeater_options_id'] = $optionIds;
                                                break;

                                            case 'media':
                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    $fileNames = [];
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            $fileName = time() . '_' . $file->getClientOriginalName();
                                                            $file->move(public_path('uploads/media'), $fileName);
                                                            $fileNames[] = $fileName;
                                                        }
                                                    }
                                                    $repeaterFieldData['field_meta_value'] = json_encode($fileNames);
                                                }
                                                break;

                                            case 'file':
                                                 if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    $filePaths = [];
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                                                $fileName = time() . '_' . $file->getClientOriginalName();
                                                                $relativePath = 'storage/uploads/customfield/developers/files/' . $fileName;

                                                                $file->storeAs('public/uploads/customfield/developers/files', $fileName);
                                                                $filePaths[] = $relativePath;
                                                            } else {
                                                                return response()->json(['error' => 'Invalid file format in repeater. Only PDF, DOC, DOCX files allowed.'], 400);
                                                            }
                                                        }
                                                    }
                                                    $repeaterFieldData['field_meta_value'] = json_encode($filePaths); // store array of relative paths
                                                }
                                                break;
                                        }

                                        CustomFieldRepeaterValues::create($repeaterFieldData);
                                    }
                                }
                            }
                            break;
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

        try {
            $user = Auth::user();
            $id = $request->id;
            $developer = Developerlist::find($id);

            if (!$developer) {
                return response()->json(['message' => 'No Developer found'], 404);
            }

            if ($user->role->name !== 'admin' && $developer->created_by !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only delete your own developers.',
                ], 403);
            }

            $filePath = public_path('uploads/featured_image/' . $developer->featured_image);

            // Delete the file if it exists
            if (File::exists($filePath)) {
                File::delete($filePath);
            }

            // Delete specific related records
            $developer->customFieldValues()->delete();

            // Delete the project
            $developer->delete();

            $returnRes = [
                'status' => true,
                'message' => 'Data deleted successfully.'
            ];

            return response()->json($returnRes, 200);
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
    // public function getdatabyId(Request $request)
    // {
    //     // dd($request->all());
    //     try {
    //         if (!$request->id) {
    //             return response()->json(['error' => 'ID is required'], 400);
    //         }

    //         $baseURL = config('app.url');

    //         $developer = Developerlist::with([
    //             'country',
    //             'state',
    //             'city',
    //             'user',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption',
    //             'createdBy.role',
    //             'updatedBy.role'
    //         ])->where('id', $request->id)->first();

    //         if (!$developer) {
    //             return response()->json(['error' => 'Developer not found'], 200);
    //         }

    //         // ✅ Handle Created By and Updated By
    //         $createdByData = $developer->createdBy ? [
    //             'id' => $developer->createdBy->id,
    //             'name' => $developer->createdBy->first_name,
    //             'email' => $developer->createdBy->email,
    //             'role' => optional($developer->createdBy->role)->name,
    //         ] : null;

    //         $updatedByData = $developer->updatedBy ? [
    //             'id' => $developer->updatedBy->id,
    //             'name' => $developer->updatedBy->first_name,
    //             'email' => $developer->updatedBy->email,
    //             'role' => optional($developer->updatedBy->role)->name,
    //         ] : null;

    //         if (!empty($developer->featured_image)) {
    //             $developer->featured_image = filter_var($developer->featured_image, FILTER_VALIDATE_URL)
    //                 ? $developer->featured_image
    //                 : url($developer->featured_image);
    //         }


    //         // ✅ Fetch repeater fields dynamically
    //         $repeaterFields = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
    //             $customField = optional($customFieldValue->customField);
    //             $fieldType = $customField->field_type ?? 'unknown';
    //             $fieldValue = $customFieldValue->field_meta_value ?? '';
    //             $allAvailableOptions = [];

    //             // ✅ Fetch all available options dynamically
    //             $availableOptions = DB::table('custom_field_options')
    //                 ->where('custom_field_id', $customFieldValue->custom_field_id)
    //                 ->where('status', 1)
    //                 ->get(['id', 'name', 'value']);

    //             if ($availableOptions->isNotEmpty()) {
    //                 foreach ($availableOptions as $option) {
    //                     $allAvailableOptions[] = [
    //                         'name' => $option->name,
    //                         'value' => $option->value,
    //                     ];
    //                 }
    //             }

    //             // ✅ Handle different field types correctly
    //             if (in_array($fieldType, ['select', 'radio'])) {
    //                 $customFieldOption = DB::table('custom_field_options')
    //                     ->where('id', $customFieldValue->custom_field_options_id)
    //                     ->where('status', 1)
    //                     ->first();
    //                 $fieldValue = optional($customFieldOption)->name;
    //             } elseif ($fieldType === 'checkbox') {
    //                 $optionIds = explode(',', $customFieldValue->custom_field_options_id);
    //                 $fieldValue = DB::table('custom_field_options')
    //                     ->whereIn('id', $optionIds)
    //                     ->where('status', 1)
    //                     ->pluck('name')
    //                     ->toArray();
    //             } elseif (in_array($fieldType, ['media', 'file'])) {
    //                 $fieldValue = json_decode($fieldValue, true) ?? [];
    //                 if (!empty($fieldValue)) {
    //                     $fieldValue = array_map(fn($fileName) => $baseURL . '/uploads/media/' . $fileName, (array) $fieldValue);
    //                 }
    //             }

    //             return [
    //                 'custom_field_id' => $customFieldValue->custom_field_id,
    //                 'field_label' => $customField->field_label ?? 'Unknown Field',
    //                 'placeholder' => $customField->field_placeholder,
    //                 'field_type' => $fieldType,
    //                 'field_value' => $fieldValue,
    //                 'options' => $allAvailableOptions,
    //             ];
    //         });

    //         return response()->json([
    //             'id' => $developer->id,
    //             'developer_unique_id' => $developer->developer_unique_id,
    //             'name' => $developer->name,
    //             'address' => $developer->address,
    //             'description' => $developer->description,
    //             'country_id' => $developer->country_id,
    //             'country_name' => optional($developer->country)->name,
    //             'state_id' => $developer->state_id,
    //             'state_name' => optional($developer->state)->name,
    //             'city_id' => $developer->city_id,
    //             'city_name' => optional($developer->city)->name,

    //             'featured_image' => $developer->featured_image,
    //             'live_status' => $developer->live_status,
    //             'status_reason' => $developer->status_reason,
    //             'temporary_status' => $developer->temporary_status,
    //             'user_id' => $developer->user_id,
    //             'created_by' => $createdByData,
    //             'updated_by' => $updatedByData,
    //             'listed_by' => optional(optional($developer->user)->role)->name,
    //             'purpose_id' => $developer->purpose_id,
    //             'purpose_id_name' => optional($developer->purpose)->name,
    //             'property_id' => $developer->property_id,
    //             'property_id_name' => optional($developer->property)->name,
    //             'property_status_id' => $developer->property_status_id,
    //             'property_status_id_name' => optional($developer->propertystatus)->name,
    //             'property_type_id' => $developer->property_type_id,
    //             'property_type_id_name' => optional($developer->propertyType)->name,
    //             'total_view' => 0, // ✅ Removed analytics() call
    //             'date' => $developer->created_at ? $developer->created_at->format('d m Y') : null,
    //             'time' => $developer->created_at ? $developer->created_at->format('h:i A') : null,
    //             'timestamp' => $developer->created_at ? $developer->created_at->format('d m Y h:i A') : null,
    //             'repeater_fields' => $repeaterFields,
    //         ]);

    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    ################ new code get data by id ##################
    // public function getdatabyId(Request $request)
    // {
    //     try {
    //         if (!$request->id) {
    //             return response()->json(['error' => 'ID is required'], 400);
    //         }

    //         $baseURL = config('app.url');

    //         $developer = Developerlist::with([
    //             'country',
    //             'state',
    //             'city',
    //             'user',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption',
    //             'createdBy.role',
    //             'updatedBy.role'
    //         ])->where('id', $request->id)->first();

    //         if (!$developer) {
    //             return response()->json(['error' => 'Developer not found'], 200);
    //         }

    //         $createdByData = $developer->createdBy ? [
    //             'id' => $developer->createdBy->id,
    //             'name' => $developer->createdBy->first_name,
    //             'email' => $developer->createdBy->email,
    //             'role' => optional($developer->createdBy->role)->name,
    //         ] : null;

    //         $updatedByData = $developer->updatedBy ? [
    //             'id' => $developer->updatedBy->id,
    //             'name' => $developer->updatedBy->first_name,
    //             'email' => $developer->updatedBy->email,
    //             'role' => optional($developer->updatedBy->role)->name,
    //         ] : null;

    //         if (!empty($developer->featured_image)) {
    //             $developer->featured_image = filter_var($developer->featured_image, FILTER_VALIDATE_URL)
    //                 ? $developer->featured_image
    //                 : url($developer->featured_image);
    //         }

    //         $repeaterFields = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL, $developer) {
    //             $customField = optional($customFieldValue->customField);
    //             $fieldType = $customField->field_type ?? 'unknown';
    //             $fieldValue = $customFieldValue->field_meta_value ?? '';
    //             $allAvailableOptions = [];

    //             if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
    //                 $availableOptions = DB::table('custom_field_options')
    //                     ->where('custom_field_id', $customFieldValue->custom_field_id)
    //                     ->get(['id', 'name', 'value']);

    //                 foreach ($availableOptions as $option) {
    //                     $allAvailableOptions[] = [
    //                         'name' => $option->name,
    //                         'value' => $option->value,
    //                     ];
    //                 }
    //             }

    //             if ($fieldType === 'repeater') {
    //                 $nestedRows = DB::table('custom_field_repeater_values')
    //                     ->where('custom_field_repeater_id', $customFieldValue->custom_field_id)
    //                     ->where('developer_listing_id', $developer->id) // 👈 adjust if your foreign key is different
    //                     ->get()
    //                     ->groupBy('unique_id');

    //                 $repeaterData = [];

    //                 foreach ($nestedRows as $groupId => $rows) {
    //                     $groupData = [];

    //                     foreach ($rows as $row) {
    //                         $value = $row->field_meta_value;
    //                         $fieldTypeNested = $row->field_type;
    //                         $nestedOptions = [];

    //                         if (in_array($fieldTypeNested, ['select', 'radio'])) {
    //                             $option = DB::table('custom_field_repeater_options')
    //                                 ->where('id', $row->custom_field_repeater_options_id)
    //                                 ->first();
    //                             $value = optional($option)->name ?? $value;

    //                             $nestedOptions = DB::table('custom_field_repeater_options')
    //                                 ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                 ->get(['name', 'value'])
    //                                 ->map(fn($opt) => [
    //                                     'name' => $opt->name,
    //                                     'value' => $opt->value,
    //                                 ])->toArray();
    //                         } elseif ($fieldTypeNested === 'checkbox') {
    //                             $ids = explode(',', $row->custom_field_repeater_options_id);
    //                             $value = DB::table('custom_field_repeater_options')
    //                                 ->whereIn('id', $ids)
    //                                 ->pluck('name')
    //                                 ->toArray();

    //                             $nestedOptions = DB::table('custom_field_repeater_options')
    //                                 ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                 ->get(['name', 'value'])
    //                                 ->map(fn($opt) => [
    //                                     'name' => $opt->name,
    //                                     'value' => $opt->value,
    //                                 ])->toArray();
    //                         } elseif ($fieldTypeNested === 'file') {
    //                             $value = $value ? url($value) : null;
    //                         } elseif ($fieldTypeNested === 'media') {
    //                             $decoded = json_decode($value, true);
    //                             $value = is_array($decoded)
    //                                 ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
    //                                 : [];
    //                         }

    //                         $groupData[] = [
    //                             'sub_field_id' => $row->custom_field_id,
    //                             'field_type' => $fieldTypeNested,
    //                             'field_value' => $value,
    //                             'options' => $nestedOptions,
    //                         ];
    //                     }

    //                     $repeaterData[] = $groupData;
    //                 }

    //                 return [
    //                     'custom_field_id' => $customFieldValue->custom_field_id,
    //                     'field_label' => $customField->field_label ?? 'Unknown Field',
    //                     'placeholder' => $customField->field_placeholder,
    //                     'field_type' => $fieldType,
    //                     'field_value' => $repeaterData,
    //                     'options' => [],
    //                 ];
    //             }

    //             if (in_array($fieldType, ['select', 'radio'])) {
    //                 $customFieldOption = DB::table('custom_field_options')
    //                     ->where('id', $customFieldValue->custom_field_options_id)
    //                     ->first();
    //                 $fieldValue = optional($customFieldOption)->name;
    //             } elseif ($fieldType === 'checkbox') {
    //                 $optionIds = explode(',', $customFieldValue->custom_field_options_id);
    //                 $fieldValue = DB::table('custom_field_options')
    //                     ->whereIn('id', $optionIds)
    //                     ->pluck('name')
    //                     ->toArray();

    //             } elseif ($fieldType === 'media') {
    //                 $fieldValue = json_decode($fieldValue, true) ?? [];
    //                 if (!empty($fieldValue)) {
    //                     $fieldValue = array_map(fn($fileName) => $baseURL . '/uploads/media/' . $fileName, (array) $fieldValue);
    //                 }
    //             } elseif ($fieldType === 'file') {
    //                 $fieldValue = is_string($fieldValue) && !empty($fieldValue)
    //                     ? $baseURL . '/storage/' . ltrim($fieldValue, '/')
    //                     : null;
    //             }

    //             return [
    //                 'custom_field_id' => $customFieldValue->custom_field_id,
    //                 'field_label' => $customField->field_label ?? 'Unknown Field',
    //                 'placeholder' => $customField->field_placeholder,
    //                 'field_type' => $fieldType,
    //                 'field_value' => $fieldValue,
    //                 'options' => $allAvailableOptions,
    //             ];
    //         });

    //         return response()->json([
    //             'id' => $developer->id,
    //             'developer_unique_id' => $developer->developer_unique_id,
    //             'name' => $developer->name,
    //             'address' => $developer->address,
    //             'description' => $developer->description,
    //             'country_id' => $developer->country_id,
    //             'country_name' => optional($developer->country)->name,
    //             'state_id' => $developer->state_id,
    //             'state_name' => optional($developer->state)->name,
    //             'city_id' => $developer->city_id,
    //             'city_name' => optional($developer->city)->name,
    //             'featured_image' => $developer->featured_image,
    //             'live_status' => $developer->live_status,
    //             'status_reason' => $developer->status_reason,
    //             'temporary_status' => $developer->temporary_status,
    //             'user_id' => $developer->user_id,
    //             'created_by' => $createdByData,
    //             'updated_by' => $updatedByData,
    //             'listed_by' => optional(optional($developer->user)->role)->name,
    //             'purpose_id' => $developer->purpose_id,
    //             'purpose_id_name' => optional($developer->purpose)->name,
    //             'property_id' => $developer->property_id,
    //             'property_id_name' => optional($developer->property)->name,
    //             'property_status_id' => $developer->property_status_id,
    //             'property_status_id_name' => optional($developer->propertystatus)->name,
    //             'property_type_id' => $developer->property_type_id,
    //             'property_type_id_name' => optional($developer->propertyType)->name,
    //             'total_view' => 0,
    //             'date' => $developer->created_at ? $developer->created_at->format('d m Y') : null,
    //             'time' => $developer->created_at ? $developer->created_at->format('h:i A') : null,
    //             'timestamp' => $developer->created_at ? $developer->created_at->format('d m Y h:i A') : null,
    //             'repeater_fields' => $repeaterFields,
    //         ]);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    public function getdatabyId(Request $request)
    {
        try {
            if (!$request->id) {
                return response()->json(['error' => 'ID is required'], 400);
            }

            $user = Auth::user();
            $createdBy = $user->id;

            $baseURL = config('app.url');

            $developer = Developerlist::with([
                'country',
                'state',
                'city',
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

            if (!$developer) {
                return response()->json(['error' => 'Developer not found'], 200);
            }



            // ✅ Check access permissions
            if ($user->role->name !== 'admin' && $developer->created_by !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only view your own developers.',
                ], 403);
            }

            $createdByData = $developer->createdBy ? [
                'id' => $developer->createdBy->id,
                'name' => $developer->createdBy->first_name,
                'email' => $developer->createdBy->email,
                'role' => optional($developer->createdBy->role)->name,
            ] : null;

            $updatedByData = $developer->updatedBy ? [
                'id' => $developer->updatedBy->id,
                'name' => $developer->updatedBy->first_name,
                'email' => $developer->updatedBy->email,
                'role' => optional($developer->updatedBy->role)->name,
            ] : null;

            if (!empty($developer->featured_image)) {
                $developer->featured_image = filter_var($developer->featured_image, FILTER_VALIDATE_URL)
                    ? $developer->featured_image
                    : url($developer->featured_image);
            }

            $repeaterFields = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL, $developer) {
                $customField = optional($customFieldValue->customField);
                $fieldType = $customField->field_type ?? 'unknown';
                $fieldValue = $customFieldValue->field_meta_value ?? '';
                $allAvailableOptions = [];

                if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
                    $availableOptions = DB::table('custom_field_options')
                        ->where('custom_field_id', $customFieldValue->custom_field_id)
                        ->get(['id', 'name', 'value']);

                    foreach ($availableOptions as $option) {
                        $allAvailableOptions[] = [
                            'name' => $option->name,
                            'value' => $option->value,
                        ];
                    }
                }

                if ($fieldType === 'repeater') {
                    $nestedRows = DB::table('custom_field_repeater_values')
                        ->where('custom_field_repeater_id', $customFieldValue->custom_field_id)
                        ->where('developer_listing_id', $developer->id)
                        ->get()
                        ->groupBy('unique_id');

                    $repeaterData = [];

                    foreach ($nestedRows as $groupId => $rows) {
                        $groupData = [];

                        foreach ($rows as $row) {
                            $value = $row->field_meta_value;
                            $fieldTypeNested = $row->field_type;
                            $nestedOptions = [];

                            // ✅ Fetch sub-field label and placeholder
                            $repeaterMeta = DB::table('custom_field_repeaters')
                                ->where('id', $row->custom_field_id)
                                ->first();

                            $fieldLabel = optional($repeaterMeta)->field_label ?? 'Unknown';
                            $fieldPlaceholder = optional($repeaterMeta)->field_placeholder ?? null;

                            if (in_array($fieldTypeNested, ['select', 'radio'])) {
                                $option = DB::table('custom_field_repeater_options')
                                    ->where('id', $row->custom_field_repeater_options_id)
                                    ->first();
                                $value = optional($option)->name ?? $value;

                                $nestedOptions = DB::table('custom_field_repeater_options')
                                    ->where('custom_field_repeater_id', $row->custom_field_id)
                                    ->get(['name', 'value'])
                                    ->map(fn($opt) => [
                                        'name' => $opt->name,
                                        'value' => $opt->value,
                                    ])->toArray();
                            } elseif ($fieldTypeNested === 'checkbox') {
                                $ids = explode(',', $row->custom_field_repeater_options_id);
                                $value = DB::table('custom_field_repeater_options')
                                    ->whereIn('id', $ids)
                                    ->pluck('name')
                                    ->toArray();

                                $nestedOptions = DB::table('custom_field_repeater_options')
                                    ->where('custom_field_repeater_id', $row->custom_field_id)
                                    ->get(['name', 'value'])
                                    ->map(fn($opt) => [
                                        'name' => $opt->name,
                                        'value' => $opt->value,
                                    ])->toArray();
                            } elseif ($fieldTypeNested === 'file') {
                                    $decoded = is_string($value) ? json_decode($value, true) : $value;
                                    $value = is_array($decoded)
                                        ? array_map(fn($file) => url($file), $decoded)
                                        : [];
                                } elseif ($fieldTypeNested === 'media') {

                                    $decoded = is_string($value) ? json_decode($value, true) : $value;
                                    $value = is_array($decoded)
                                        ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                                        : [];
                                }

                            $groupData[] = [
                                'sub_field_id' => $row->custom_field_id,
                                'field_type' => $fieldTypeNested,
                                'field_label' => $fieldLabel,
                                'placeholder' => $fieldPlaceholder,
                                'field_value' => $value,
                                'options' => $nestedOptions,
                            ];
                        }

                        $repeaterData[] = $groupData;
                    }

                    return [
                        'custom_field_id' => $customFieldValue->custom_field_id,
                        'field_label' => $customField->field_label ?? 'Unknown Field',
                        'placeholder' => $customField->field_placeholder,
                        'field_type' => $fieldType,
                        'field_value' => $repeaterData,
                        'options' => [],
                    ];
                }

                if (in_array($fieldType, ['select', 'radio'])) {
                    $customFieldOption = DB::table('custom_field_options')
                        ->where('id', $customFieldValue->custom_field_options_id)
                        ->first();
                    $fieldValue = optional($customFieldOption)->name;
                } elseif ($fieldType === 'checkbox') {
                    $optionIds = explode(',', $customFieldValue->custom_field_options_id);
                    $fieldValue = DB::table('custom_field_options')
                        ->whereIn('id', $optionIds)
                        ->pluck('name')
                        ->toArray();
                } elseif ($fieldType === 'file') {
                        $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                        $fieldValue = is_array($decoded)
                            ? array_map(fn($file) => url($file), $decoded)
                            : [];

                    } elseif ($fieldType === 'media') {
                        $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                        $fieldValue = is_array($decoded)
                            ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                            : [];
                    }

                $fieldArray = [
                    'custom_field_id' => $customFieldValue->custom_field_id,
                    'field_label' => $customField->field_label ?? 'Unknown Field',
                    'placeholder' => $customField->field_placeholder,
                    'field_type' => $fieldType,
                    'field_value' => $fieldValue,
                    'options' => $allAvailableOptions,
                ];

                if ($fieldType === 'checkbox') {
                    $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
                }

                return $fieldArray;
            });

            return response()->json([
                'id' => $developer->id,
                'developer_unique_id' => $developer->developer_unique_id,
                'name' => $developer->name,
                'address' => $developer->address,
                'description' => $developer->description,
                'country_id' => $developer->country_id,
                'country_name' => optional($developer->country)->name,
                'state_id' => $developer->state_id,
                'state_name' => optional($developer->state)->name,
                'city_id' => $developer->city_id,
                'city_name' => optional($developer->city)->name,
                'featured_image' => $developer->featured_image,
                'live_status' => $developer->live_status,
                'status_reason' => $developer->status_reason,
                'temporary_status' => $developer->temporary_status,
                'user_id' => $developer->user_id,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'listed_by' => optional(optional($developer->user)->role)->name,
                'purpose_id' => $developer->purpose_id,
                'purpose_id_name' => optional($developer->purpose)->name,
                'property_id' => $developer->property_id,
                'property_id_name' => optional($developer->property)->name,
                'property_status_id' => $developer->property_status_id,
                'property_status_id_name' => optional($developer->propertystatus)->name,
                'property_type_id' => $developer->property_type_id,
                'property_type_id_name' => optional($developer->propertyType)->name,
                'total_view' => 0,
                'date' => $developer->created_at ? $developer->created_at->format('d m Y') : null,
                'time' => $developer->created_at ? $developer->created_at->format('h:i A') : null,
                'timestamp' => $developer->created_at ? $developer->created_at->format('d m Y h:i A') : null,
                'repeater_fields' => $repeaterFields,
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    ##############  end new code get data by id ##############

    // bulk
    public function bulkDelete(Request $request)
    {

        // if ($request->header('api-token') == '') {
        //     return response()->json(['error' => 'Please enter api token first.'], 422);
        // }

        // $requestToken = $request->header('api-token');

        // $expectedToken = config('constants.API_TOKEN');

        // if ($requestToken !== $expectedToken) {
        //     return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
        // }

        try {

            $user = Auth::user();
            $createdBy = $user->id;
            // Find the builder by ID
            $delete_ids = explode(',', $request->id);

            foreach ($delete_ids as $row) {
                $purpose = Developerlist::findOrFail($row);

                // Check access: Only admin or creator can delete
                if (
                    strtolower(optional($user->role)->name) !== 'admin' &&
                    $purpose->created_by !== $createdBy
                ) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized: You can only delete your own developers.',
                    ], 403);
                }

                $filePath = public_path('uploads/featured_image/' . $purpose->featured_image);

                // Delete the file if it exists
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
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
    public function getUserDeveloper(Request $request)
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
            $developerData = [];

            // Validate: Ensure at least one of country_id, state_id, city_id is provided and not null
            $validator = Validator::make($request->all(), [
                'country_id' => 'required|integer|exists:countries,id',
                'state_id' => 'required|integer|exists:states,id',
                'city_id' => 'required|integer|exists:cities,id',
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'message' => 'country_id, state_id, and city_id are all required.',
                    'errors' => $validator->errors(),      // field-wise detail
                ], 422);
            }

            // Build query dynamically based on provided parameters
            $query = Developerlist::query();

            if (!empty($request->country_id) && !empty($request->state_id) && !empty($request->city_id)) {
                $query->where('country_id', $request->country_id)
                    ->where('state_id', $request->state_id)
                    ->where('city_id', $request->city_id);
            }

            // Fetch matching projects
            $developers = $query->get();

            // Return error if no data is found
            if ($developers->isEmpty()) {
                return response()->json(['error' => 'No developers found for the given location.'], 200);
            }

            // Process results
            foreach ($developers as $row) {
                $developerData[] = [
                    'id' => $row->id,
                    'name' => $row->name,
                ];
            }

            return response()->json($developerData);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function updateTemporaryStatus(Request $request)
    {
        try {
            // Validate the request
            $validatedData = $request->validate([
                'developer_id' => 'required|exists:developer_listings,id',
                'temporary_status' => 'required|string',  // Temporary status is required
            ]);

            // Fetch allowed enum values dynamically
            $enumValues = DB::select("SHOW COLUMNS FROM developer_listings WHERE Field = 'temporary_status'");

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

            // Find the developer and update the status
            $developer = Developerlist::findOrFail($request->developer_id);
            $developer->temporary_status = $request->temporary_status;
            $developer->save();

            return response()->json([
                'status' => true,
                'message' => 'Temporary status updated successfully',
                'data' => $developer
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile()
            ], 500);
        }
    }


    // developer search by name and developer_unique_id
    public function developerSearch(Request $request)
    {
        try {
            $search = $request->input('search'); // name query from frontend
            $baseURL = config('app.url');


            $developers = Developerlist::when($search, function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%')->orWhere('developer_unique_id', 'like', '%' . $search . '%');
            })
                ->with([
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
                    'createdBy.role',
                    'updatedBy.role'
                ])
                ->get();

            $developersData = $developers->map(function ($developer) use ($baseURL) {
                $formattedCustomFieldValues = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
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
                    'id' => $developer->id,
                    'developer_unique_id' => $developer->developer_unique_id,
                    'name' => $developer->name,
                    'description' => $developer->description,
                    'live_status' => $developer->live_status,
                    'temporary_status' => $developer->temporary_status,
                    'status_reason' => $developer->status_reason,
                    'user_id' => $developer->user_id,
                    'created_by' => $developer->created_by,
                    'created_by_role' => optional(optional($developer->createdBy)->role)->name,
                    'updated_by' => $developer->updated_by,
                    'updated_by_role' => optional(optional($developer->updatedBy)->role)->name,
                    'listed_by' => optional(optional($developer->user)->role)->name,
                    'purpose_id' => $developer->purpose_id,
                    'purpose_id_name' => optional($developer->purpose)->name,
                    'property_id' => $developer->property_id,
                    'property_id_name' => optional($developer->property)->name,
                    'property_status_id' => $developer->property_status_id,
                    'property_status_id_name' => optional($developer->propertystatus)->name,
                    'property_type_id' => $developer->property_type_id,
                    'property_type_id_name' => optional($developer->propertyType)->name,
                    'address' => $developer->address,
                    'country_id' => $developer->country_id,
                    'country_name' => optional($developer->country)->name,
                    'state_id' => $developer->state_id,
                    'state_name' => optional($developer->state)->name,
                    'city_id' => $developer->city_id,
                    'city_name' => optional($developer->city)->name,
                    'date' => date('d m Y', strtotime($developer->created_at)),
                    'time' => date('h:i A', strtotime($developer->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($developer->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json($developersData);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
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



    ### No Auth ###

    public function getdatabyIdNoAuth(Request $request)
    {
        try {
            if (!$request->id) {
                return response()->json(['error' => 'ID is required'], 400);
            }

            $baseURL = config('app.url');

            $developer = Developerlist::with([
                'country',
                'state',
                'city',
                'user.role',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'customFieldValues.customField.templateValue',
                'customFieldValues.customFieldOption',
                'createdBy.role',
                'updatedBy.role'
            ])->where('live_status', 'Approve')->where('id', $request->id)->first();

            if (!$developer) {
                return response()->json(['error' => 'Developer not found'], 200);
            }

            $createdByData = $developer->createdBy ? [
                'id' => $developer->createdBy->id,
                'name' => $developer->createdBy->first_name,
                'email' => $developer->createdBy->email,
                'role' => optional($developer->createdBy->role)->name,
            ] : null;

            $updatedByData = $developer->updatedBy ? [
                'id' => $developer->updatedBy->id,
                'name' => $developer->updatedBy->first_name,
                'email' => $developer->updatedBy->email,
                'role' => optional($developer->updatedBy->role)->name,
            ] : null;

            if (!empty($developer->featured_image)) {
                $developer->featured_image = filter_var($developer->featured_image, FILTER_VALIDATE_URL)
                    ? $developer->featured_image
                    : url($developer->featured_image);
            }

            $repeaterFields = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL, $developer) {
                $customField = optional($customFieldValue->customField);
                $fieldType = $customField->field_type ?? 'unknown';
                $fieldValue = $customFieldValue->field_meta_value ?? '';
                $template = $customField->templateValue ?? null;
                $allAvailableOptions = [];

                if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
                    $availableOptions = DB::table('custom_field_options')
                        ->where('custom_field_id', $customFieldValue->custom_field_id)
                        ->get(['id', 'name', 'value']);

                    foreach ($availableOptions as $option) {
                        $allAvailableOptions[] = [
                            'name' => $option->name,
                            'value' => $option->value,
                        ];
                    }
                }

                if ($fieldType === 'repeater') {
                    $nestedRows = DB::table('custom_field_repeater_values')
                        ->where('custom_field_repeater_id', $customFieldValue->custom_field_id)
                        ->where('developer_listing_id', $developer->id)
                        ->get()
                        ->groupBy('unique_id');

                    $repeaterData = [];

                    foreach ($nestedRows as $groupId => $rows) {
                        $groupData = [];

                        foreach ($rows as $row) {
                            $value = $row->field_meta_value;
                            $fieldTypeNested = $row->field_type;
                            $nestedOptions = [];

                            $repeaterMeta = DB::table('custom_field_repeaters')
                                ->where('id', $row->custom_field_id)
                                ->first();

                            $fieldLabel = optional($repeaterMeta)->field_label ?? 'Unknown';
                            $fieldPlaceholder = optional($repeaterMeta)->field_placeholder ?? null;

                            if (in_array($fieldTypeNested, ['select', 'radio'])) {
                                $option = DB::table('custom_field_repeater_options')
                                    ->where('id', $row->custom_field_repeater_options_id)
                                    ->first();
                                $value = optional($option)->name ?? $value;

                                $nestedOptions = DB::table('custom_field_repeater_options')
                                    ->where('custom_field_repeater_id', $row->custom_field_id)
                                    ->get(['name', 'value'])
                                    ->map(fn($opt) => [
                                        'name' => $opt->name,
                                        'value' => $opt->value,
                                    ])->toArray();
                            } elseif ($fieldTypeNested === 'checkbox') {
                                $ids = explode(',', $row->custom_field_repeater_options_id);
                                $value = DB::table('custom_field_repeater_options')
                                    ->whereIn('id', $ids)
                                    ->pluck('name')
                                    ->toArray();

                                $nestedOptions = DB::table('custom_field_repeater_options')
                                    ->where('custom_field_repeater_id', $row->custom_field_id)
                                    ->get(['name', 'value'])
                                    ->map(fn($opt) => [
                                        'name' => $opt->name,
                                        'value' => $opt->value,
                                    ])->toArray();
                            }elseif ($fieldTypeNested === 'file') {
                                $decoded = is_string($value) ? json_decode($value, true) : $value;
                                $value = is_array($decoded)
                                    ? array_map(fn($file) => url($file), $decoded)
                                    : [];
                            } elseif ($fieldTypeNested === 'media') {
                                $decoded = json_decode($value, true);
                                $value = is_array($decoded)
                                    ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                                    : [];
                            }

                            // ✅ Fetch template info for nested field
                            $subField = DB::table('custom_fields')->where('id', $row->custom_field_id)->first();
                            $subTemplate = $subField && $subField->template_id
                                ? DB::table('custom_field_unique_codes')->where('id', $subField->template_id)->first()
                                : null;

                            $groupData[] = [
                                'sub_field_id' => $row->custom_field_id,
                                'field_type' => $fieldTypeNested,
                                'field_label' => $fieldLabel,
                                'placeholder' => $fieldPlaceholder,
                                'field_value' => $value,
                                'options' => $nestedOptions,
                                'template_id' => $subField->template_id ?? null,
                                'template' => $subTemplate,
                            ];
                        }

                        $repeaterData[] = $groupData;
                    }

                    return [
                        'custom_field_id' => $customFieldValue->custom_field_id,
                        'field_label' => $customField->field_label ?? 'Unknown Field',
                        'placeholder' => $customField->field_placeholder,
                        'field_type' => $fieldType,
                        'field_value' => $repeaterData,
                        'template_id' => $template->id ?? null,
                        'template' => $template,
                        'options' => [],
                    ];
                }

                if (in_array($fieldType, ['select', 'radio'])) {
                    $customFieldOption = DB::table('custom_field_options')
                        ->where('id', $customFieldValue->custom_field_options_id)
                        ->first();
                    $fieldValue = optional($customFieldOption)->name;
                } elseif ($fieldType === 'checkbox') {
                    $optionIds = explode(',', $customFieldValue->custom_field_options_id);
                    $fieldValue = DB::table('custom_field_options')
                        ->whereIn('id', $optionIds)
                        ->pluck('name')
                        ->toArray();
                }elseif ($fieldType === 'file') {
                    $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                    $fieldValue = is_array($decoded)
                        ? array_map(fn($file) => url($file), $decoded)
                        : [];
                } elseif ($fieldType === 'media') {
                    $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                    $fieldValue = is_array($decoded)
                        ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                        : [];

                }

                $fieldArray = [
                    'custom_field_id' => $customFieldValue->custom_field_id,
                    'field_label' => $customField->field_label ?? 'Unknown Field',
                    'placeholder' => $customField->field_placeholder,
                    'field_type' => $fieldType,
                    'field_value' => $fieldValue,
                    'template_id' => $template->id ?? null,
                    'template' => $template,
                    'options' => $allAvailableOptions,
                ];

                if ($fieldType === 'checkbox') {
                    $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
                }

                return $fieldArray;
            });

            return response()->json([
                'id' => $developer->id,
                'developer_unique_id' => $developer->developer_unique_id,
                'name' => $developer->name,
                'address' => $developer->address,
                'description' => $developer->description,
                'country_id' => $developer->country_id,
                'country_name' => optional($developer->country)->name,
                'state_id' => $developer->state_id,
                'state_name' => optional($developer->state)->name,
                'city_id' => $developer->city_id,
                'city_name' => optional($developer->city)->name,
                'featured_image' => $developer->featured_image,
                'live_status' => $developer->live_status,
                'status_reason' => $developer->status_reason,
                'temporary_status' => $developer->temporary_status,
                'user_id' => $developer->user_id,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'listed_by' => optional(optional($developer->user)->role)->name,
                'purpose_id' => $developer->purpose_id,
                'purpose_id_name' => optional($developer->purpose)->name,
                'property_id' => $developer->property_id,
                'property_id_name' => optional($developer->property)->name,
                'property_status_id' => $developer->property_status_id,
                'property_status_id_name' => optional($developer->propertystatus)->name,
                'property_type_id' => $developer->property_type_id,
                'property_type_id_name' => optional($developer->propertyType)->name,
                'total_view' => 0,
                'date' => $developer->created_at ? $developer->created_at->format('d m Y') : null,
                'time' => $developer->created_at ? $developer->created_at->format('h:i A') : null,
                'timestamp' => $developer->created_at ? $developer->created_at->format('d m Y h:i A') : null,
                'repeater_fields' => $repeaterFields,
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

}
