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
use App\Models\ProjectList;


class DeveloperlistingController extends Controller
{



    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            // Get the user ID
            $userId = $user->id;

            $userRole = $user->role->name;

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
                
                'property_type_id' => 'nullable|array',
                'property_type_id.*' => 'exists:property_types,id',

                'property_status_id' => 'nullable|array',
                'property_status_id.*' => 'exists:status,id',

                'live_status' => 'nullable|in:Approve,Disapprove,Reject,Under Review,Modify Review',
                'status_reason' => $request->live_status === 'Reject' ? 'required|string|max:500' : 'nullable',
                'area_locality' => 'nullable|string',
                'colony' => 'nullable|string',
                'street_address' => 'nullable|string',
                'pin_code' => 'required|numeric|digits:6',
                'user_id' => 'sometimes|exists:users,id'

            ]);

            if (!$validatedData) {
                return response()->json(['error' => 'Validation failed'], 422);
            }

            // Ensure `status_reason` is null if live_status is not "Reject"
            $validatedData['status_reason'] = $request->live_status === 'Reject' ? $request->status_reason : null;


             // prefix get
            $prefix = DB::table('site_settings')->value('developer_prefix');
            $prefix = $prefix ?? 'URPD';

            // last developer_unique_id  (descending order )
            $lastId = DB::table('developer_listings')
                ->orderBy('id', 'desc')
                ->value('developer_unique_id');

            if ($lastId) {

                $number = (int) str_replace($prefix, '', $lastId);

                // increment
                $newNumber = $number + 1;
            } else {

                $newNumber = 000001;
            }

            // final developer id
            $developer_unique_id = $prefix . $newNumber;

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

            // user_id handle
            if ($userRole === 'admin') {
                $validatedData['user_id'] = $request->user_id ?? Auth::user()->id;
                ;
            } else {
                $validatedData['user_id'] = $userId;
            }

            // if (!empty($request->property_type_id) && is_array($request->property_type_id)) {
            //     $validatedData['property_type_id'] = $request->property_type_id ?? [];
            // }

            // if (!empty($request->property_status_id) && is_array($request->property_status_id)) {
            //     $validatedData['property_status_id'] = $request->property_status_id ?? [];
            // }

            // ✅ Convert property_type_id and property_status_id to JSON strings (safe)
            if ($request->has('property_type_id')) {
                if (is_array($request->property_type_id)) {
                    $validatedData['property_type_id'] = json_encode($request->property_type_id);
                } elseif (is_string($request->property_type_id)) {
                    // Already a stringified array, ensure valid JSON
                    $decoded = json_decode($request->property_type_id, true);
                    $validatedData['property_type_id'] = json_last_error() === JSON_ERROR_NONE
                        ? $request->property_type_id
                        : json_encode([$request->property_type_id]);
                } else {
                    $validatedData['property_type_id'] = json_encode([]);
                }
            }

            if ($request->has('property_status_id')) {
                if (is_array($request->property_status_id)) {
                    $validatedData['property_status_id'] = json_encode($request->property_status_id);
                } elseif (is_string($request->property_status_id)) {
                    $decoded = json_decode($request->property_status_id, true);
                    $validatedData['property_status_id'] = json_last_error() === JSON_ERROR_NONE
                        ? $request->property_status_id
                        : json_encode([$request->property_status_id]);
                } else {
                    $validatedData['property_status_id'] = json_encode([]);
                }
            }



            // Prepare developer data
            $developerData = array_merge($validatedData, [
                'developer_unique_id' => $developer_unique_id,
                // 'user_id' => $userId,
                'created_by' => $userId, // Store the authenticated user's ID
                'address' => $request->address,
                'featured_image' => $featuredImage,
            ]);

            // ✅ Create the developer listing FIRST
            $developer = Developerlist::create($developerData);

            // Store keywords
            if (!empty($request->keyword)) {
                $keywords = explode(',', $request->keyword);
                foreach ($keywords as $keyword) {
                    Keyword::create(['developer_id' => $developer->id, 'keyword' => $keyword]);
                }
            }



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
                        case 'number':
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
                                            case 'number':
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
   

    ### new index code ###
    public function index(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Fetch only listings where live_status is "Approve"
            $developers = Developerlist::where('live_status', 'Approve')
                ->when($request->country_id, function ($query) use ($request) {
                    return $query->where('country_id', $request->country_id);
                })
                ->when($request->state_id, function ($query) use ($request) {
                    return $query->where('state_id', $request->state_id);
                })
                ->when($request->city_id, function ($query) use ($request) {
                    return $query->where('city_id', $request->city_id);
                })
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
                    'updatedBy.role',
                    'importKeywords'
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
                                    } elseif ($nestedType === 'file') {
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

                 //  Decode property_type_id safely

            $propertyTypeIds = is_array($developer->property_type_id)
                ? array_map('intval', $developer->property_type_id)
                : ((is_string($developer->property_type_id) && ($decoded = json_decode($developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_type_id)) ? [(int)$developer->property_type_id] : []));

            //  Decode property_status_id safely
            $propertyStatusIds = is_array($developer->property_status_id)
                ? array_map('intval', $developer->property_status_id)
                : ((is_string($developer->property_status_id) && ($decoded = json_decode($developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_status_id)) ? [(int)$developer->property_status_id] : []));

            //  Fetch id + name together
            $propertyTypes = !empty($propertyTypeIds)
                ? PropertyType::whereIn('id', $propertyTypeIds)
                    ->get(['id as property_type_id', 'name as property_type_name'])
                    ->toArray()
                : [];

            $propertyStatuses = !empty($propertyStatusIds)
                ? Status::whereIn('id', $propertyStatusIds)
                    ->get(['id as property_status_id', 'name as property_status_name'])
                    ->toArray()
                : [];


                return [
                    'id' => $developer->id,
                    'developer_unique_id' => $developer->developer_unique_id,
                    'name' => $developer->name,
                    'description' => $developer->description,
                    'live_status' => $developer->live_status,
                    'temporary_status' => $developer->temporary_status,
                    'status_reason' => $developer->status_reason,
                    'user_id' => $developer->user_id,
                    'user' => $developer->user_id ? [
                        'id' => $developer->user->id,
                        'name' => $developer->user->first_name,
                        'email' => $developer->user->email,
                        'role' => optional($developer->user->role)->name,
                    ] :null,

                    'created_by' => $developer->created_by,
                    'created_by_role' => optional(optional($developer->createdBy)->role)->name,
                    'updated_by' => $developer->updated_by,
                    'updated_by_role' => optional(optional($developer->updatedBy)->role)->name,
                    'listed_by' => optional(optional($developer->user)->role)->name,
                    'purpose_id' => $developer->purpose_id,
                    'purpose_id_name' => optional($developer->purpose)->name,
                    'property_id' => $developer->property_id,
                    'property_id_name' => optional($developer->property)->name,
                  'property_type' => $propertyTypes,
                   'property_status' => $propertyStatuses,
                    'address' => $developer->address,
                    'country_id' => $developer->country_id,
                    'country_name' => optional($developer->country)->name,
                    'state_id' => $developer->state_id,
                    'state_name' => optional($developer->state)->name,
                    'city_id' => $developer->city_id,
                    'city_name' => optional($developer->city)->name,
                    'area_locality' => $developer->area_locality,
                    'colony' => $developer->colony,
                    'street_address' => $developer->street_address,
                    'pin_code' => $developer->pin_code,
                    'date' => date('d m Y', strtotime($developer->created_at)),
                    'time' => date('h:i A', strtotime($developer->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($developer->created_at)),
                    'keyword' => $developer->importKeywords,
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
            $perPage = $request->get('per_page', 10);

            // Fetch all listings without filtering live_status
            $developers = Developerlist::with([
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
                'importKeywords',
                'createdBy.role', // ✅ Include creator's role
                'updatedBy.role'  // ✅ Include updater's role
            ])->paginate($perPage);

            $projectsData = $developers->map(function ($developer) use ($baseURL, $basePath) {
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
                        'field_name' => $customField ? $customField->field_label : null,
                    ];
                });

              // Since $casts already converts them to arrays, just ensure they are arrays
           //  Decode property_type_id safely

            $propertyTypeIds = is_array($developer->property_type_id)
                ? array_map('intval', $developer->property_type_id)
                : ((is_string($developer->property_type_id) && ($decoded = json_decode($developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_type_id)) ? [(int)$developer->property_type_id] : []));

            //  Decode property_status_id safely
            $propertyStatusIds = is_array($developer->property_status_id)
                ? array_map('intval', $developer->property_status_id)
                : ((is_string($developer->property_status_id) && ($decoded = json_decode($developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_status_id)) ? [(int)$developer->property_status_id] : []));

            //  Fetch id + name together
            $propertyTypes = !empty($propertyTypeIds)
                ? PropertyType::whereIn('id', $propertyTypeIds)
                    ->get(['id as property_type_id', 'name as property_type_name'])
                    ->toArray()
                : [];

            $propertyStatuses = !empty($propertyStatusIds)
                ? Status::whereIn('id', $propertyStatusIds)
                    ->get(['id as property_status_id', 'name as property_status_name'])
                    ->toArray()
                : [];




                return [
                    'id' => $developer->id,
                    'developer_unique_id' => $developer->developer_unique_id,
                    'name' => $developer->name,
                    'description' => $developer->description,
                    'live_status' => $developer->live_status,
                    'temporary_status' => $developer->temporary_status,
                    'status_reason' => $developer->status_reason,
                    'user_id' => $developer->user_id,
                    'user' => ($developer->user && $developer->user_id) ? [
                        'id' => $developer->user->id,
                        'name' => $developer->user->first_name,
                        'email' => $developer->user->email,
                        'role' => optional($developer->user->role)->name,
                    ] :null,
                    'listed_by' => optional(optional($developer->user)->role)->name,

                    'created_by' => $developer->created_by,
                    'created_by_role' => optional(optional($developer->createdBy)->role)->name, // ✅ Creator role
                    'updated_by' => $developer->updated_by,
                    'updated_by_role' => optional(optional($developer->updatedBy)->role)->name, // ✅ Updater role

                    'purpose_id' => $developer->purpose_id,
                    'purpose_id_name' => optional($developer->purpose)->name,
                    'property_id' => $developer->property_id,
                    'property_id_name' => optional($developer->property)->name,
                    
                   'property_type' => $propertyTypes,
                   'property_status' => $propertyStatuses,



                    'address' => $developer->address,

                    'country_id' => $developer->country_id,
                    'country_name' => optional($developer->country)->name,
                    'state_id' => $developer->state_id,
                    'state_name' => optional($developer->state)->name,
                    'city_id' => $developer->city_id,
                    'city_name' => optional($developer->city)->name,
                    'area_locality' => $developer->area_locality,
                    'colony' => $developer->colony,
                    'street_area' => $developer->street_address,
                    'pin_code' => $developer->pin_code,

                    'date' => date('d m Y', strtotime($developer->created_at)),
                    'time' => date('h:i A', strtotime($developer->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($developer->created_at)),

                    'keyword' => $developer->importKeywords,

                    'custom_field_values' => $formattedCustomFieldValues,
                    'top_featured_id' => $developer->top_featured_id,
                    'featured' => $developer->top_featured_id !== null, // true if not null, else false
                ];
            });

            return response()->json([
                'data'=>$projectsData,
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
            ],200);
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

            // user_id handle
            if ($userData->role->name != 'admin') {
                $request->merge(['user_id' => $userId]);
            } else {
                $request->merge(['user_id' => $request->user_id]);
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
                return response()->json(['error' => 'Invalid Developer Id'], 200);
            }

            if ($user->role->name !== 'admin' && $developer->created_by !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only update your own developers.',
                ], 403);
            }

          // ✅ Validate array fields safely
            $validatedData = $request->validate([
                'property_type_id'   => 'nullable|array',
                'property_type_id.*' => 'exists:property_types,id',
                'property_status_id'   => 'nullable|array',
                'property_status_id.*' => 'exists:status,id',
            ]);

            // ✅ Convert array fields to JSON before merging into request
            if ($request->has('property_type_id')) {
                if (is_array($validatedData['property_type_id'])) {
                    $validatedData['property_type_id'] = json_encode($validatedData['property_type_id']);
                } elseif (is_string($validatedData['property_type_id'])) {
                    $decoded = json_decode($validatedData['property_type_id'], true);
                    $validatedData['property_type_id'] =
                        (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                        ? $validatedData['property_type_id']
                        : json_encode([$validatedData['property_type_id']]);
                } else {
                    $validatedData['property_type_id'] = json_encode([]);
                }
            }

            if ($request->has('property_status_id')) {
                if (is_array($validatedData['property_status_id'])) {
                    $validatedData['property_status_id'] = json_encode($validatedData['property_status_id']);
                } elseif (is_string($validatedData['property_status_id'])) {
                    $decoded = json_decode($validatedData['property_status_id'], true);
                    $validatedData['property_status_id'] =
                        (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                        ? $validatedData['property_status_id']
                        : json_encode([$validatedData['property_status_id']]);
                } else {
                    $validatedData['property_status_id'] = json_encode([]);
                }
            }

            // ✅ Merge back safely to request (so you can reuse $request->all() later)
            $request->merge([
                'property_type_id' => $validatedData['property_type_id'] ?? null,
                'property_status_id' => $validatedData['property_status_id'] ?? null,
            ]);




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
                'property_status_id' => $request->property_status_id ?? null,
                'property_type_id' => $request->property_type_id ?? null,
                'live_status' => $request->live_status,
                'status_reason' => $request->status_reason,
                'temporary_status' => $request->temporary_status,
                'updated_by' => $userId,  // Store the user ID in the updated_by field
                'address' => $request->address,
                'featured_image' => $featuredImage,
                'area_locality' => $request->area_locality,
                'colony' => $request->colony,
                'street_address' => $request->street_address,
                'pin_code' => $request->pin_code,
                'user_id' => $request->user_id,
            ]);

            // Handle keywords
            if (!empty($request->keyword)) {
                Keyword::where('developer_id', $developer->id)->delete();
                foreach (explode(',', $request->keyword) as $keyword) {
                    Keyword::create(['developer_id' => $developer->id, 'keyword' => $keyword]);
                }
            }

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
                        case 'number':
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

                            $existingMedia = isset($repeaterField['existing_value']) ? json_decode($repeaterField['existing_value'], true) : [];
                            $fileNames = is_array($existingMedia) ? $existingMedia : [];

                            if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $file) {
                                    if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                        $fileName = time() . '_' . $file->getClientOriginalName();
                                        $file->move(public_path('uploads/media'), $fileName);
                                        $fileNames[] = $fileName;
                                    } elseif (is_string($file)) {
                                        $fileNames[] = $file;
                                    }
                                }
                            }

                            if (!empty($fileNames)) {
                                $customFieldData['field_meta_value'] = json_encode($fileNames);
                            }
                            break;

                        case 'file':
                            $existingFiles = isset($repeaterField['existing_value']) ? json_decode($repeaterField['existing_value'], true) : [];
                            $filePaths = is_array($existingFiles) ? $existingFiles : [];

                            if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                // $filePaths = [];
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
                                    } elseif (is_string($file)) {
                                        $filePaths[] = $file;
                                    }
                                }
                            }

                            if (!empty($filePaths)) {
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
                                            case 'number':
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
                                                $existingMedia = isset($subField['existing_value']) ? json_decode($subField['existing_value'], true) : [];
                                                $fileNames = is_array($existingMedia) ? $existingMedia : [];

                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    // $fileNames = [];
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            $fileName = time() . '_' . $file->getClientOriginalName();
                                                            $file->move(public_path('uploads/media'), $fileName);
                                                            $fileNames[] = $fileName;
                                                        } elseif (is_string($file)) {
                                                            $fileNames[] = $file;
                                                        }
                                                    }
                                                }

                                                if (!empty($fileNames)) {
                                                    $repeaterFieldData['field_meta_value'] = json_encode($fileNames);

                                                }
                                                break;

                                            case 'file':
                                                $existingFiles = isset($subField['existing_value']) ? json_decode($subField['existing_value'], true) : [];
                                                $filePaths = is_array($existingFiles) ? $existingFiles : [];

                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    // $filePaths = [];
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
                                                        } elseif (is_string($file)) {
                                                            $filePaths[] = $file; // already existing file path
                                                        }
                                                    }
                                                }
                                                if (!empty($filePaths)) {
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
                'updatedBy.role',
                'importKeywords'
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
                                $existingValue = is_array($decoded) ? $decoded : [];
                                $value = is_array($decoded)
                                    ? array_map(fn($file) => url($file), $decoded)
                                    : [];
                            } elseif ($fieldTypeNested === 'media') {

                                $decoded = is_string($value) ? json_decode($value, true) : $value;
                                $existingValue = is_array($decoded) ? $decoded : [];
                                $value = is_array($decoded)
                                    ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                                    : [];
                            }

                            $subField = [
                                'sub_field_id' => $row->custom_field_id,
                                'field_type' => $fieldTypeNested,
                                'field_label' => $fieldLabel,
                                'placeholder' => $fieldPlaceholder,
                                'field_value' => $value,
                                'options' => $nestedOptions,
                            ];

                            // ✅ Add existing_value for media/file
                            if (in_array($fieldTypeNested, ['file', 'media'])) {
                                $subField['existing_value'] = $existingValue ?? [];
                            }

                            $groupData[] = $subField;
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

                    $existingValue = is_array($decoded) ? $decoded : [];

                } elseif ($fieldType === 'media') {
                    $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                    $fieldValue = is_array($decoded)
                        ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                        : [];

                    $existingValue = is_array($decoded) ? $decoded : [];
                }

                $fieldArray = [
                    'custom_field_id' => $customFieldValue->custom_field_id,
                    'field_label' => $customField->field_label ?? 'Unknown Field',
                    'placeholder' => $customField->field_placeholder,
                    'field_type' => $fieldType,
                    'field_value' => $fieldValue,
                    'options' => $allAvailableOptions,
                ];

                // Add existing_value only for media or file
                if (in_array($fieldType, ['media', 'file'])) {
                    $fieldArray['existing_value'] = $existingValue ?? null;
                }

                if ($fieldType === 'checkbox') {
                    $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
                }

                return $fieldArray;
            });


                   //  Decode property_type_id safely

            $propertyTypeIds = is_array($developer->property_type_id)
                ? array_map('intval', $developer->property_type_id)
                : ((is_string($developer->property_type_id) && ($decoded = json_decode($developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_type_id)) ? [(int)$developer->property_type_id] : []));

            //  Decode property_status_id safely
            $propertyStatusIds = is_array($developer->property_status_id)
                ? array_map('intval', $developer->property_status_id)
                : ((is_string($developer->property_status_id) && ($decoded = json_decode($developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_status_id)) ? [(int)$developer->property_status_id] : []));

            //  Fetch id + name together
            $propertyTypes = !empty($propertyTypeIds)
                ? PropertyType::whereIn('id', $propertyTypeIds)
                    ->get(['id as property_type_id', 'name as property_type_name'])
                    ->toArray()
                : [];

            $propertyStatuses = !empty($propertyStatusIds)
                ? Status::whereIn('id', $propertyStatusIds)
                    ->get(['id as property_status_id', 'name as property_status_name'])
                    ->toArray()
                : [];

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
                'area_locality' => $developer->area_locality,
                'colony' => $developer->colony,
                'street_address' => $developer->street_address,
                'pin_code' => $developer->pin_code,
                'featured_image' => $developer->featured_image,
                'live_status' => $developer->live_status,
                'status_reason' => $developer->status_reason,
                'temporary_status' => $developer->temporary_status,
                'user_id' => $developer->user_id,
                'user' => $developer->user_id  ? [
                        'id' => $developer->user->id,
                        'name' => $developer->user->first_name,
                        'email' => $developer->user->email,
                        'role' => optional($developer->user->role)->name,
                    ] :null,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'listed_by' => optional(optional($developer->user)->role)->name,
                'purpose_id' => $developer->purpose_id,
                'purpose_id_name' => optional($developer->purpose)->name,
                'property_id' => $developer->property_id,
                'property_id_name' => optional($developer->property)->name,

                'property_type' => $propertyTypes,
                'property_status' => $propertyStatuses,
                'total_view' => 0,
                'date' => $developer->created_at ? $developer->created_at->format('d m Y') : null,
                'time' => $developer->created_at ? $developer->created_at->format('h:i A') : null,
                'timestamp' => $developer->created_at ? $developer->created_at->format('d m Y h:i A') : null,
                'keyword' => $developer->importKeywords->map(function ($kw) {
                    return [
                        'id' => $kw->id,
                        'name' => $kw->keyword_name
                    ];
                }),
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
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized.'], 401);
            }

            $isAdmin = $user->role->name === 'admin';

            // Base query
            $query = Developerlist::query();

            if ($isAdmin) {
                // Admin logic
                if ($request->has('user_id') && is_numeric($request->user_id)) {
                    $query->where(function ($q) use ($request) {
                        $q->where('created_by', $request->user_id)
                            ->orWhere('updated_by', $request->user_id);
                    });
                }
                // else: no filter, show all projects
            } else {
                // Non-admin user can only see their own projects
                $query->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhere('updated_by', $user->id);
                });
            }

            $developers = $query->get();

            if ($developers->isEmpty()) {
                return response()->json(['message' => 'No Developer found.'], 200);
            }

            // Format featured_image URL
            $developers = $developers->map(function ($developer) {

                 // Decode property_type_id safely
                    $propertyTypeIds = is_array($developer->property_type_id)
                        ? array_map('intval', $developer->property_type_id)
                        : ((is_string($developer->property_type_id) &&
                            ($decoded = json_decode($developer->property_type_id, true)) &&
                            json_last_error() === JSON_ERROR_NONE)
                            ? array_map('intval', $decoded)
                            : ((is_numeric($developer->property_type_id))
                                ? [(int)$developer->property_type_id]
                                : []));

                    //  Decode property_status_id safely
                    $propertyStatusIds = is_array($developer->property_status_id)
                        ? array_map('intval', $developer->property_status_id)
                        : ((is_string($developer->property_status_id) &&
                            ($decoded = json_decode($developer->property_status_id, true)) &&
                            json_last_error() === JSON_ERROR_NONE)
                            ? array_map('intval', $decoded)
                            : ((is_numeric($developer->property_status_id))
                                ? [(int)$developer->property_status_id]
                                : []));

                    //  Fetch id + name for each
                    $propertyTypes = !empty($propertyTypeIds)
                        ? PropertyType::whereIn('id', $propertyTypeIds)
                            ->get(['id as property_type_id', 'name as property_type_name'])
                            ->toArray()
                        : [];

                    $propertyStatuses = !empty($propertyStatusIds)
                        ? Status::whereIn('id', $propertyStatusIds)
                            ->get(['id as property_status_id', 'name as property_status_name'])
                            ->toArray()
                        : [];


                if (!empty($developer->featured_image)) {
                    $developer->featured_image = filter_var($developer->featured_image, FILTER_VALIDATE_URL)
                        ? $developer->featured_image
                        : url(ltrim($developer->featured_image, '/'));
                }

                //  Append property type & status
                $developer->property_type = $propertyTypes;
                $developer->property_status = $propertyStatuses;
                
                return $developer;
            });

            return response()->json([
                'status' => true,
                'message' => 'Developer retrieved successfully.',
                'data' => $developers
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    public function getAllDeveloperByLocationId(Request $request)
    {
        try {


            $user = Auth::user();
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

            // If user is admin and user_id is provided, filter by that user's developers
            if ($user->role->name === 'admin') {
                if (!empty($request->user_id)) {
                    $query->where('created_by', $request->user_id);
                }
            } else {
                // Non-admin: restrict to their own developers
                $query->where('created_by', $user->id);
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
            $user = Auth::user();
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
            $isAdmin = isset($user->role->name) && $user->role->name === 'admin';
            $isOwner = $developer->created_by == $user->id;

            if (!$isAdmin && !$isOwner) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only update your own developers.',
                ], 403);
            }
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
            $user = Auth::user(); // Get the authenticated user
            $search = $request->input('search'); // name query from frontend
            $perPage = $request->input('per_page', 10); // Default to 10 if not provided
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
                    'updatedBy.role',
                    'importKeywords'
                ])
                ->paginate($perPage);


            $isAdmin = isset($user->role->name) && $user->role->name === 'admin';
            if (!$isAdmin) {
                $developers = $developers->filter(function ($developer) use ($user) {
                    return $developer->created_by == $user->id;
                })->values(); // Reset keys
            }


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
                    } elseif ($customField && $customField->field_type == 'file') {
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/' . $file;
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

                    //  Decode property_type_id safely

                $propertyTypeIds = is_array($developer->property_type_id)
                    ? array_map('intval', $developer->property_type_id)
                    : ((is_string($developer->property_type_id) && ($decoded = json_decode($developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($developer->property_type_id)) ? [(int)$developer->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($developer->property_status_id)
                    ? array_map('intval', $developer->property_status_id)
                    : ((is_string($developer->property_status_id) && ($decoded = json_decode($developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($developer->property_status_id)) ? [(int)$developer->property_status_id] : []));

                //  Fetch id + name together
                $propertyTypes = !empty($propertyTypeIds)
                    ? PropertyType::whereIn('id', $propertyTypeIds)
                        ->get(['id as property_type_id', 'name as property_type_name'])
                        ->toArray()
                    : [];

                $propertyStatuses = !empty($propertyStatusIds)
                    ? Status::whereIn('id', $propertyStatusIds)
                        ->get(['id as property_status_id', 'name as property_status_name'])
                        ->toArray()
                    : [];

                return [
                    'id' => $developer->id,
                    'developer_unique_id' => $developer->developer_unique_id,
                    'name' => $developer->name,
                    'description' => $developer->description,
                    'live_status' => $developer->live_status,
                    'temporary_status' => $developer->temporary_status,
                    'status_reason' => $developer->status_reason,
                    'user_id' => $developer->user_id,
                    'user' => $developer->user_id  ? [
                        'id' => $developer->user->id,
                        'name' => $developer->user->first_name,
                        'email' => $developer->user->email,
                        'role' => optional($developer->user->role)->name,
                    ] :null,
                    'created_by' => $developer->created_by,
                    'created_by_role' => optional(optional($developer->createdBy)->role)->name,
                    'updated_by' => $developer->updated_by,
                    'updated_by_role' => optional(optional($developer->updatedBy)->role)->name,
                    'listed_by' => optional(optional($developer->user)->role)->name,
                    'purpose_id' => $developer->purpose_id,
                    'purpose_id_name' => optional($developer->purpose)->name,
                    'property_id' => $developer->property_id,
                    'property_id_name' => optional($developer->property)->name,
                    'property_type' => $propertyTypes,
                    'property_status' => $propertyStatuses,

                    'address' => $developer->address,
                    'country_id' => $developer->country_id,
                    'country_name' => optional($developer->country)->name,
                    'state_id' => $developer->state_id,
                    'state_name' => optional($developer->state)->name,
                    'city_id' => $developer->city_id,
                    'city_name' => optional($developer->city)->name,
                    'area_locality' => $developer->area_locality,
                    'colony' => $developer->colony,
                    'street_address' => $developer->street_address,
                    'pin_code' => $developer->pin_code,
                    'date' => date('d m Y', strtotime($developer->created_at)),
                    'time' => date('h:i A', strtotime($developer->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($developer->created_at)),
                    'keyword' => $developer->importKeywords,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => $isAdmin
                    ? 'Admin: Showing all matched developers.'
                    : 'Showing only your own matched developers.',
                'data' => $developersData,
                'meta' => [
                    'current_page' => $developers->currentPage(),
                    'last_page' => $developers->lastPage(),
                    'per_page' => $developers->perPage(),
                    'total' => $developers->total(),
                ],
                'link' => [
                    'first' => $developers->url(1),
                    'last' => $developers->url($developers->lastPage()),
                    'prev' => $developers->previousPageUrl(),
                    'next' => $developers->nextPageUrl(),
                ],
            ]);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
        }
    }


    // ====================================================




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
                'updatedBy.role',
                'importKeywords'
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
                            } elseif ($fieldTypeNested === 'file') {
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
                    'template_id' => $template->id ?? null,
                    'template' => $template,
                    'options' => $allAvailableOptions,
                ];

                if ($fieldType === 'checkbox') {
                    $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
                }

                return $fieldArray;
            });


                   //  Decode property_type_id safely

            $propertyTypeIds = is_array($developer->property_type_id)
                ? array_map('intval', $developer->property_type_id)
                : ((is_string($developer->property_type_id) && ($decoded = json_decode($developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_type_id)) ? [(int)$developer->property_type_id] : []));

            //  Decode property_status_id safely
            $propertyStatusIds = is_array($developer->property_status_id)
                ? array_map('intval', $developer->property_status_id)
                : ((is_string($developer->property_status_id) && ($decoded = json_decode($developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_status_id)) ? [(int)$developer->property_status_id] : []));

            //  Fetch id + name together
            $propertyTypes = !empty($propertyTypeIds)
                ? PropertyType::whereIn('id', $propertyTypeIds)
                    ->get(['id as property_type_id', 'name as property_type_name'])
                    ->toArray()
                : [];

            $propertyStatuses = !empty($propertyStatusIds)
                ? Status::whereIn('id', $propertyStatusIds)
                    ->get(['id as property_status_id', 'name as property_status_name'])
                    ->toArray()
                : [];

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
                'area_locality' => $developer->area_locality,
                'colony' => $developer->colony,
                'street_address' => $developer->street_address,
                'pin_code' => $developer->pin_code,
                'featured_image' => $developer->featured_image,
                'live_status' => $developer->live_status,
                'status_reason' => $developer->status_reason,
                'temporary_status' => $developer->temporary_status,
                'user_id' => $developer->user_id,
                'user' => $developer->user_id  ? [
                        'id' => $developer->user->id,
                        'name' => $developer->user->first_name,
                        'email' => $developer->user->email,
                        'role' => optional($developer->user->role)->name,
                    ] :null,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'listed_by' => optional(optional($developer->user)->role)->name,
                'purpose_id' => $developer->purpose_id,
                'purpose_id_name' => optional($developer->purpose)->name,
                'property_id' => $developer->property_id,
                'property_id_name' => optional($developer->property)->name,
                'property_type' => $propertyTypes,
                'property_status' => $propertyStatuses,
                'total_view' => 0,
                'date' => $developer->created_at ? $developer->created_at->format('d m Y') : null,
                'time' => $developer->created_at ? $developer->created_at->format('h:i A') : null,
                'timestamp' => $developer->created_at ? $developer->created_at->format('d m Y h:i A') : null,
                'keyword' => $developer->importKeywords,
                'repeater_fields' => $repeaterFields,
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function getDevelopersByUserId(Request $request, $userId)
    {
        try {
            // Developer Owner check (exclude admin)
            $developerOwner = User::where('id', $userId)
                ->whereHas('role', function ($q) {
                    $q->where('name', '!=', 'admin');
                })
                ->first();

            if (!$developerOwner) {
                return response()->json(['error' => 'User not found or is admin.'], 200);
            }

            $baseURL = config('app.url');

            // Base query (without user relations)
            $query = Developerlist::with([
                'purpose',
                'propertyType',
                'propertystatus',
                'property',
                'property',
                'country',
                'state',
                'city',
                'customFieldValues.customField.templateValue',
                'customFieldValues.customFieldOption',
            ])
                ->where('live_status', 'Approve')
                ->where(function ($q) use ($userId) {
                    $q->where('created_by', $userId)
                        ->orWhere('updated_by', $userId);
                });

            // Purpose filter
            if ($request->filled('purpose_id')) {
                $query->where('purpose_id', $request->purpose_id);
            }

            // Pagination
            $perPage = $request->get('per_page', 10);
            $developers = $query->paginate($perPage);

            // Purpose-wise count
            $purposeCounts = DB::table('developer_listings as p')
                ->join('purposes as pu', 'p.purpose_id', '=', 'pu.id')
                ->select('p.purpose_id', 'pu.name as purpose_name', DB::raw('COUNT(*) as total'))
                ->where(function ($q) use ($userId) {
                    $q->where('p.created_by', $userId)
                        ->orWhere('p.updated_by', $userId);
                })
                ->groupBy('p.purpose_id', 'pu.name')
                ->get();

            // Format properties data (without user info)
            $formattedDevelopers = $developers->getCollection()->map(function ($developer) use ($baseURL) {
                $featuredImage = !empty($developer->featured_image)
                    ? (filter_var($developer->featured_image, FILTER_VALIDATE_URL)
                    ? $developer->featured_image
                    : url(ltrim($developer->featured_image, '/')))
                    : null;

                $propertyTypeNames = null;
                if (!empty($developer->property_type_id)) {
                    $ids = explode(',', $developer->property_type_id);
                    $propertyTypeNames = PropertyType::whereIn('id', $ids)
                        ->pluck('name')
                        ->toArray();
                }

                // ✅ Custom fields (copied from q1)
                $formattedCustomFieldValues = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL, $developer) {
                    $customField = optional($customFieldValue->customField);
                    $templateData = $customField->templateValue ?? null;
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
                            ->where('developer_listing_id', $developer->id) //
                            ->get()
                            ->groupBy('unique_id');

                        $repeaterData = [];

                        foreach ($nestedRows as $groupId => $rows) {
                            $groupData = [];
                            foreach ($rows as $row) {
                                $value = $row->field_meta_value;
                                $fieldTypeNested = $row->field_type;
                                $nestedOptions = [];

                                $nestedCustomField = DB::table('custom_fields')
                                    ->where('id', $row->custom_field_id)
                                    ->first();

                                $templateDetails = DB::table('custom_field_unique_codes')
                                    ->where('id', $nestedCustomField->template_id ?? null)
                                    ->first();

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
                                    'template_id' => $nestedCustomField->template_id ?? null,
                                    'template' => $templateDetails ?? null,
                                    'field_type' => $fieldTypeNested,
                                    'field_value' => $value,
                                    'options' => $nestedOptions,
                                ];
                            }

                            $repeaterData[] = $groupData;
                        }

                        return [
                            'custom_field_id' => $customFieldValue->custom_field_id,
                            'template_id' => $customField->template_id ?? null,
                            'template' => $templateData ?? null,
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
                        'template_id' => $customField->template_id ?? null,
                        'template' => $templateData ?? null,
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


                   //  Decode property_type_id safely

                $propertyTypeIds = is_array($developer->property_type_id)
                    ? array_map('intval', $developer->property_type_id)
                    : ((is_string($developer->property_type_id) && ($decoded = json_decode($developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($developer->property_type_id)) ? [(int)$developer->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($developer->property_status_id)
                    ? array_map('intval', $developer->property_status_id)
                    : ((is_string($developer->property_status_id) && ($decoded = json_decode($developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($developer->property_status_id)) ? [(int)$developer->property_status_id] : []));

                //  Fetch id + name together
                $propertyTypes = !empty($propertyTypeIds)
                    ? PropertyType::whereIn('id', $propertyTypeIds)
                        ->get(['id as property_type_id', 'name as property_type_name'])
                        ->toArray()
                    : [];

                $propertyStatuses = !empty($propertyStatusIds)
                    ? Status::whereIn('id', $propertyStatusIds)
                        ->get(['id as property_status_id', 'name as property_status_name'])
                        ->toArray()
                    : [];


                return [
                    'id' => $developer->id,
                    'name' => $developer->name ?? null,
                    'description' => $developer->description ?? null,
                    'purpose_id' => $developer->purpose_id ?? null,
                    'purpose_name' => $developer->purpose->name ?? null,
                    'featured_image' => $featuredImage,
                    'country_id' => $developer->country_id ?? null,
                    'state_id' => $developer->state_id ?? null,
                    'city_id' => $developer->city_id ?? null,
                    'country_name' => $developer->country->name ?? null,
                    'state_name' => $developer->state->name ?? null,
                    'city_name' => $developer->city->name ?? null,
                    'area_locality' => $developer->area_locality ?? null,
                    'colony' => $developer->colony ?? null,
                    'street_address' => $developer->street_address ?? null,
                    'pin_code' => $developer->pin_code ?? null,
                    'property_type' => $propertyTypes ?? null,
                    'property_status' => $propertyStatuses ?? null,
                    'property_id' => $developer->property_id ?? null,
                    'property_name' => $developer->property->name ?? null,
                    'created_by' => $developer->created_by ?? null,
                    'updated_by' => $developer->updated_by ?? null,
                    'created_at' => $developer->created_at ?? null,
                    'updated_at' => $developer->updated_at ?? null,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Developers retrieved successfully.',
                'data' => [
                        'properties' => $formattedDevelopers,
                        'pagination' => [
                                'total' => $developers->total(),
                                'per_page' => $developers->perPage(),
                                'current_page' => $developers->currentPage(),
                                'last_page' => $developers->lastPage(),
                            ],
                        'purpose_counts' => $purposeCounts
                    ]
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }




    public function getRelatedDevelopersByDeveloperId(Request $request, $developerId)
    {
        try {
            // Reference project find
            $referenceDeveloper = Developerlist::find($developerId);

            if (!$referenceDeveloper) {
                return response()->json(['status' => false, 'message' => 'Reference developer not found.'], 200);
            }

            // Viewer for masking logic
            $viewer = auth('sanctum')->user() ?? User::where('api_token', $request->bearerToken())->first();

            // Convert reference property's property_type_id to an array
            $referencePropertyTypes = explode(',', $referenceDeveloper->property_type_id);

            $baseURL = config('app.url');

            // Query build
            $query = Developerlist::with([
                'purpose',
                'propertyType',
                'propertystatus',
                'property',
                'user.role:id,name',
                'user.country:id,name as country_name',
                'user.state:id,name as state_name',
                'user.city:id,name as city_name',
                'user.userDetails',
                'country',
                'state',
                'city',
                'customFieldValues.customField.templateValue',
                'customFieldValues.customFieldOption',

            ])->where('live_status', '=', 'Approve')
                ->where('id', '!=', $referenceDeveloper->id) // same property skip
                ->where('purpose_id', $referenceDeveloper->purpose_id)
                ->where('property_id', $referenceDeveloper->property_id)
                ->where(function ($q) use ($referencePropertyTypes) {
                    // Match any of the property types in the reference property
                    foreach ($referencePropertyTypes as $type) {
                        $q->orWhere('property_type_id', 'like', "%{$type}%");
                    }
                })
                ->where('area_locality', 'like', '%' . $referenceDeveloper->area_locality . '%');

            $perPage = $request->get('per_page', 10);
            $developers = $query->paginate($perPage);

            $formattedDevelopers = $developers->getCollection()->map(function ($developer) use ($baseURL) {
                $featuredImage = !empty($developer->featured_image)
                    ? (filter_var($developer->featured_image, FILTER_VALIDATE_URL)
                    ? $developer->featured_image
                    : url(ltrim($developer->featured_image, '/')))
                    : null;

                // Convert "1,2" IDs to names
                $propertyTypeNames = null;
                if (!empty($developer->property_type_id)) {
                    $ids = explode(',', $developer->property_type_id);
                    $propertyTypeNames = PropertyType::whereIn('id', $ids)
                        ->pluck('name')
                        ->toArray();
                }

                $formattedCustomFieldValues = $developer->customFieldValues->map(function ($customFieldValue) use ($baseURL, $developer) {
                    $customField = optional($customFieldValue->customField);
                    $templateData = $customField->templateValue ?? null;
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
                            ->where('developer_listing_id', $developer->id) // ✅ for project
                            ->get()
                            ->groupBy('unique_id');

                        $repeaterData = [];

                        foreach ($nestedRows as $groupId => $rows) {
                            $groupData = [];
                            foreach ($rows as $row) {
                                $value = $row->field_meta_value;
                                $fieldTypeNested = $row->field_type;
                                $nestedOptions = [];

                                $nestedCustomField = DB::table('custom_fields')
                                    ->where('id', $row->custom_field_id)
                                    ->first();

                                $templateDetails = DB::table('custom_field_unique_codes')
                                    ->where('id', $nestedCustomField->template_id ?? null)
                                    ->first();

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
                                    'template_id' => $nestedCustomField->template_id ?? null,
                                    'template' => $templateDetails ?? null,
                                    'field_type' => $fieldTypeNested,
                                    'field_value' => $value,
                                    'options' => $nestedOptions,
                                ];
                            }

                            $repeaterData[] = $groupData;
                        }

                        return [
                            'custom_field_id' => $customFieldValue->custom_field_id,
                            'template_id' => $customField->template_id ?? null,
                            'template' => $templateData ?? null,
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
                        'template_id' => $customField->template_id ?? null,
                        'template' => $templateData ?? null,
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

                return [
                    'id' => $developer->id,
                    'name' => $developer->name ?? null,
                    'purpose_id' => $developer->purpose_id ?? null,
                    'purpose_name' => $developer->purpose->name ?? null,
                    'featured_image' => $featuredImage,
                    'property_type_name' => $propertyTypeNames ? implode(', ', $propertyTypeNames) : null,
                    'custom_field_values' => $formattedCustomFieldValues,

                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Related developers retrieved successfully.',
                'data' => [
                        'properties' => $formattedDevelopers,
                        'pagination' => [
                                'total' => $developers->total(),
                                'per_page' => $developers->perPage(),
                                'current_page' => $developers->currentPage(),
                                'last_page' => $developers->lastPage(),
                            ],
                    ]
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'error' => $th->getMessage()], 500);
        }
    }



    //  public function getAllProjectsByDeveloper(Request $request)
    // {
    //     try {
    //         $baseURL = config('app.url');
    //         $basePath = public_path();

    //           $validator = Validator::make($request->all(), [
    //                'developer_id' => 'required|exists:developer_listings,id',
    //             ]);

    //             if ($validator->fails()) {
    //                 return response()->json([
    //                     'status' => false,
    //                     'message' => 'Validation error.',
    //                     'errors' => $validator->errors(),
    //                 ], 422);
    //             }

    //         $projects = ProjectList::with([
    //             'user',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'customFieldValues.customField.templateValue',
    //             'customFieldValues.customFieldOption',
    //             'importKeywords',
    //             'developer',
    //             'country',
    //             'state',
    //             'city'
    //         ])
    //         ->where('developer_id', $request->developer_id)
           
    //         ->paginate($request->get('per_page', 10));

    //         if (!$projects) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'No  projects found for this developer.',
    //                 'data' => [],
    //             ], 200);
    //         }
    //         // Purpose counts

    //         $projectsData = $projects->map(function ($project) use ($baseURL) {
    //             $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL, $project) {
    //                 $customField = $customFieldValue->customField;
    //                 $customFieldOption = $customFieldValue->customFieldOption ?? null;
    //                 $fieldType = $customField->field_type ?? null;
    //                 $fieldValue = $customFieldValue->field_meta_value;

    //                 $templateData = optional(optional($customField)->templateValue)?->toArray();
    //                 $templateId = optional(optional($customField)->templateValue)?->id;

    //                 $fieldValueFormatted = null;
    //                 $options = [];

    //                 switch ($fieldType) {
    //                     case 'checkbox':
    //                         $ids = explode(',', $customFieldValue->custom_field_options_id);
    //                         $fieldValueFormatted = DB::table('custom_field_options')
    //                             ->whereIn('id', $ids)
    //                             ->pluck('name')
    //                             ->toArray();
    //                         break;

    //                     case 'select':
    //                     case 'radio':
    //                         $fieldValueFormatted = optional($customFieldOption)->name ?? null;
    //                         $options = DB::table('custom_field_options')
    //                             ->where('custom_field_id', $customFieldValue->custom_field_id)
    //                             ->get(['name', 'value'])
    //                             ->map(fn($opt) => [
    //                                 'name' => $opt->name,
    //                                 'value' => $opt->value,
    //                             ])->toArray();
    //                         break;

    //                     case 'media':
    //                         $decoded = json_decode($fieldValue, true);
    //                         $fieldValueFormatted = is_array($decoded)
    //                             ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
    //                             : [];
    //                         break;

    //                     case 'file':
    //                         $decoded = json_decode($fieldValue, true);
    //                         $fieldValueFormatted = is_array($decoded)
    //                             ? array_map(fn($file) => $baseURL . '/' . $file, $decoded)
    //                             : [];
    //                         break;

    //                     case 'repeater':
    //                         $nestedRows = DB::table('custom_field_repeater_values')
    //                             ->where('custom_field_repeater_id', $customField->id)
    //                             ->where('project_listing_id', $property->id)
    //                             ->get()
    //                             ->groupBy('unique_id');

    //                         $repeaterData = [];

    //                         foreach ($nestedRows as $groupId => $rows) {
    //                             $groupData = [];

    //                             foreach ($rows as $row) {
    //                                 $nestedOptions = [];
    //                                 $nestedValue = $row->field_meta_value;
    //                                 $nestedType = $row->field_type;

    //                                 if (in_array($nestedType, ['select', 'radio'])) {
    //                                     $opt = DB::table('custom_field_repeater_options')
    //                                         ->where('id', $row->custom_field_repeater_options_id)
    //                                         ->first();
    //                                     $nestedValue = optional($opt)->name ?? $nestedValue;

    //                                     $nestedOptions = DB::table('custom_field_repeater_options')
    //                                         ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                         ->get(['name', 'value'])
    //                                         ->map(fn($opt) => [
    //                                             'name' => $opt->name,
    //                                             'value' => $opt->value,
    //                                         ])->toArray();
    //                                 } elseif ($nestedType === 'checkbox') {
    //                                     $ids = explode(',', $row->custom_field_repeater_options_id);
    //                                     $nestedValue = DB::table('custom_field_repeater_options')
    //                                         ->whereIn('id', $ids)
    //                                         ->pluck('name')
    //                                         ->toArray();

    //                                     $nestedOptions = DB::table('custom_field_repeater_options')
    //                                         ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                         ->get(['name', 'value'])
    //                                         ->map(fn($opt) => [
    //                                             'name' => $opt->name,
    //                                             'value' => $opt->value,
    //                                         ])->toArray();
    //                                 } elseif ($nestedType === 'file') {
    //                                     $decoded = is_string($nestedValue) ? json_decode($nestedValue, true) : $nestedValue;
    //                                     $nestedValue = is_array($decoded)
    //                                         ? array_map(fn($file) => url($file), $decoded)
    //                                         : [];
    //                                 } elseif ($nestedType === 'media') {
    //                                     $decoded = json_decode($nestedValue, true);
    //                                     $nestedValue = is_array($decoded)
    //                                         ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
    //                                         : [];
    //                                 }

    //                                 // 💡 Get template info for nested field
    //                                 $subField = DB::table('custom_fields')->where('id', $row->custom_field_id)->first();
    //                                 $template = $subField && $subField->template_id
    //                                     ? DB::table('custom_field_unique_codes')->where('id', $subField->template_id)->first()
    //                                     : null;

    //                                 $groupData[] = [
    //                                     'sub_field_id' => $row->custom_field_id,
    //                                     'field_type' => $nestedType,
    //                                     'field_value' => $nestedValue,
    //                                     'options' => $nestedOptions,
    //                                     'template_id' => $subField->template_id ?? null,
    //                                     'template' => $template,
    //                                 ];
    //                             }

    //                             $repeaterData[] = $groupData;
    //                         }

    //                         $fieldValueFormatted = $repeaterData;
    //                         break;

    //                     default:
    //                         $fieldValueFormatted = $fieldValue;
    //                         break;
    //                 }

    //                 $fieldArray = [
    //                     'custom_field_id' => optional($customField)->id,
    //                     'field_type' => $fieldType,
    //                     'field_value' => $fieldValueFormatted,
    //                     'field_name' => optional($customField)->field_label,
    //                     'placeholder' => optional($customField)->field_placeholder,
    //                     'template_id' => $templateId,
    //                     'template' => $templateData,
    //                     'options' => $options,
    //                 ];

    //                 if ($fieldType === 'checkbox') {
    //                     $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
    //                 }

    //                 return $fieldArray;
    //             });

    //              //  Decode property_type_id safely

    //             $propertyTypeIds = is_array($project->property_type_id)
    //                 ? array_map('intval', $project->property_type_id)
    //                 : ((is_string($project->property_type_id) && ($decoded = json_decode($project->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
    //                     ? array_map('intval', $decoded)
    //                     : ((is_numeric($project->property_type_id)) ? [(int)$project->property_type_id] : []));

    //             //  Decode property_status_id safely
    //             $propertyStatusIds = is_array($project->property_status_id)
    //                 ? array_map('intval', $project->property_status_id)
    //                 : ((is_string($project->property_status_id) && ($decoded = json_decode($project->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
    //                     ? array_map('intval', $decoded)
    //                     : ((is_numeric($project->property_status_id)) ? [(int)$project->property_status_id] : []));

    //             //  Fetch id + name together
    //             $propertyTypes = !empty($propertyTypeIds)
    //                 ? PropertyType::whereIn('id', $propertyTypeIds)
    //                     ->get(['id as property_type_id', 'name as property_type_name'])
    //                     ->toArray()
    //                 : [];

    //             $propertyStatuses = !empty($propertyStatusIds)
    //                 ? Status::whereIn('id', $propertyStatusIds)
    //                     ->get(['id as property_status_id', 'name as property_status_name'])
    //                     ->toArray()
    //                 : [];



    //             return [
    //                 'id' => $project->id,
    //                 'project_unique_id' => $project->project_unique_id,
    //                 'name' => $project->name,
    //                 'description' => $project->description,
    //                 'live_status' => $project->live_status,
    //                 'status_reason' => $project->status_reason,
    //                 'project_status' => $project->project_status,

    //                 'user_id' => $project->user_id,
    //                 'user' => $project->user_id ? [
    //                     'id' => $project->user->id,
    //                     'name' => $project->user->first_name,
    //                     'email' => $project->user->email,
    //                     'role' => optional($project->user->role)->name,
    //                 ] :null,
    //                 'created_by' => $project->created_by,
    //                 'updated_by' => $project->updated_by,
    //                 'listed_by' => optional(optional($project->user)->role)->name,
    //                 'purpose_id' => $project->purpose_id,
    //                 'purpose_id_name' => optional($project->purpose)->name,
    //                 'property_id' => $project->property_id,
    //                 'property_id_name' => optional($project->property)->name,
                   
    //                 'property_status '=> $propertyStatuses, 
    //                 'property_type '=> $propertyTypes,
    //                 'total_view' => $project->analytics()->count(),
    //                 'date' => date('d m Y', strtotime($project->created_at)),
    //                 'time' => date('h:i A', strtotime($project->created_at)),
    //                 'timestamp' => date('d m Y h:i A', strtotime($project->created_at)),
    //                 'keyword' => $project->importKeywords,
    //                 'address' => $project->address,
    //                 'country' => $project->country,
    //                 'state' => $project->state,
    //                 'city' => $project->city,
    //                 'area_locality' => $project->area_locality,
    //                 'colony' => $project->colony,
    //                 'street_address' => $project->street_address,
    //                 'pin_code' => $project->pin_code,
    //                 'complete_status' => $project->complete_status,
    //                 'completed_at' => $project->completed_at,
    //                 'custom_field_values' => $formattedCustomFieldValues,
    //                 'developer_id' => $project->developer_id,
    //                  'developer' => $project->developer ? [
    //                     'id' => $project->developer->id,
    //                     'developer_unique_id' => $project->developer->developer_unique_id,
    //                     'name' => $project->developer->name,
    //                     'description' => $project->developer->description,
    //                     'purpose_id' => $project->developer->purpose_id,
    //                     'purpose_id_name' => optional($project->developer->purpose)->name,
    //                     'property_id' => $project->developer->property_id,
    //                     'property_id_name' => optional($project->developer->property)->name,
    //                     'property_status_id' => $project->developer->property_status_id,
    //                     'property_status_id_name' => optional($project->developer->propertystatus)->name,
    //                     'property_type_id' => $project->developer->property_type_id,
    //                     'property_type_id_name' => optional($project->developer->propertyType)->name,
    //                     'country_id' => $project->developer->country_id,
    //                     'country_name' => optional($project->developer->country)->name,
    //                     'state_id' => $project->developer->state_id,
    //                     'state_name' => optional($project->developer->state)->name,
    //                     'city_id' => $project->developer->city_id,
    //                     'city_name' => optional($project->developer->city)->name,
    //                     'address' => $project->developer->address,
    //                     'area_locality' => $project->developer->area_locality,
    //                     'colony' => $project->developer->colony,
    //                     'street_address' => $project->developer->street_address,
    //                     'pin_code' => $project->developer->pin_code,
    //                     'featured_image' => $project->developer->featured_image
    //                         ? url($project->developer->featured_image)
    //                         : null,
    //                     'live_status' => $project->developer->live_status,
    //                     'temporary_status' => $project->developer->temporary_status,
    //                     'status_reason' => $project->developer->status_reason,
    //                     'user_id' => $project->developer->user_id,
    //                     'user' => $project->developer->user_id ? [
    //                         'id' => $project->developer->user->id,
    //                         'name' => $project->developer->user->first_name,
    //                         'email' => $project->developer->user->email,
    //                         'role' => optional($project->developer->user->role)->name,
    //                     ] :null,
    //                     'created_by' => $project->developer->createdBy ? [
    //                         'id' => $project->developer->createdBy->id,
    //                         'name' => $project->developer->createdBy->first_name,
    //                         'email' => $project->developer->createdBy->email,
    //                         'role' => optional($project->developer->createdBy->role)->name,
    //                     ] : null,
    //                     'updated_by' => $project->developer->updatedBy ? [
    //                         'id' => $project->developer->updatedBy->id,
    //                         'name' => $project->developer->updatedBy->first_name,
    //                         'email' => $project->developer->updatedBy->email,
    //                         'role' => optional($project->developer->updatedBy->role)->name,
    //                     ] : null,
    //                     'keyword' => $project->developer->importKeywords ?? [],

    //                 ] : null,
    //             ];
    //         });

    //         return response()->json([
    //             'message' => 'Projects retrieved successfully.',
    //             'data' => $projectsData,
    //             'meta' => [
    //                 'current_page' => $projects->currentPage(),
    //                 'from' => $projects->firstItem(),
    //                 'last_page' => $projects->lastPage(),
    //                 'path' => $request->url(),
    //                 'per_page' => $projects->perPage(),
    //                 'to' => $projects->lastItem(),
    //                 'total' => $projects->total(),
    //             ],
    //             'links' => [
    //                 'first' => $projects->url(1),
    //                 'last' => $projects->url($projects->lastPage()),
    //                 'prev' => $projects->previousPageUrl(),
    //                 'next' => $projects->nextPageUrl(),
    //             ]
    //         ]);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage() . ' on line ' . $th->getLine()], 500);
    //     }
    // }

    public function getAllProjectsByDeveloper(Request $request)
{
    try {
        $baseURL = config('app.url');
        $basePath = public_path();

        $validator = Validator::make($request->all(), [
            'developer_id' => 'required|exists:developer_listings,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🟢 Fetch developer details first
        $developer = DeveloperList::with([
            'purpose', 'propertyType', 'propertystatus', 'country', 'state', 'city', 'user', 'createdBy', 'updatedBy', 'importKeywords'
        ])->find($request->developer_id);

        
            // ✅ Decode developer property_type_id safely
            $developerPropertyTypeIds = is_array($developer->property_type_id)
                ? array_map('intval', $developer->property_type_id)
                : ((is_string($developer->property_type_id) && ($decoded = json_decode($developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_type_id)) ? [(int)$developer->property_type_id] : []));

            // ✅ Decode developer property_status_id safely
            $developerPropertyStatusIds = is_array($developer->property_status_id)
                ? array_map('intval', $developer->property_status_id)
                : ((is_string($developer->property_status_id) && ($decoded = json_decode($developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($developer->property_status_id)) ? [(int)$developer->property_status_id] : []));

            // ✅ Fetch Property Types & Statuses for developer
            $developerPropertyTypes = !empty($developerPropertyTypeIds)
                ? PropertyType::whereIn('id', $developerPropertyTypeIds)
                    ->get(['id as property_type_id', 'name as property_type_name'])
                    ->toArray()
                : [];

            $developerPropertyStatuses = !empty($developerPropertyStatusIds)
                ? Status::whereIn('id', $developerPropertyStatusIds)
                    ->get(['id as property_status_id', 'name as property_status_name'])
                    ->toArray()
                : [];

        $developerInfo = $developer ? [
            'id' => $developer->id,
            'developer_unique_id' => $developer->developer_unique_id,
            'name' => $developer->name,
            'description' => $developer->description,
            'purpose_id' => $developer->purpose_id,
            'purpose_name' => optional($developer->purpose)->name,
            'property_id' => $developer->property_id,
            'property_name' => optional($developer->property)->name,
            'property_status_id' => $developer->property_status_id,
            'property_type_id' => $developer->property_type_id,
            'propertyStatuses' => $developerPropertyStatuses,
            'propertyTypes' => $developerPropertyTypes,
            'country_id' => $developer->country_id,
            'country_name' => optional($developer->country)->name,
            'state_id' => $developer->state_id,
            'state_name' => optional($developer->state)->name,
            'city_id' => $developer->city_id,
            'city_name' => optional($developer->city)->name,
            'address' => $developer->address,
            'area_locality' => $developer->area_locality,
            'colony' => $developer->colony,
            'street_address' => $developer->street_address,
            'pin_code' => $developer->pin_code,
            'featured_image' => $developer->featured_image ? url($developer->featured_image) : null,
            'live_status' => $developer->live_status,
            'temporary_status' => $developer->temporary_status,
            'status_reason' => $developer->status_reason,
            'user_id' => $developer->user_id,
            'user' => $developer->user_id ? [
                'id' => $developer->user->id,
                'name' => $developer->user->first_name,
                'email' => $developer->user->email,
                'role' => optional($developer->user->role)->name,
            ] : null,
            'created_by' => $developer->createdBy ? [
                'id' => $developer->createdBy->id,
                'name' => $developer->createdBy->first_name,
                'email' => $developer->createdBy->email,
                'role' => optional($developer->createdBy->role)->name,
            ] : null,
            'updated_by' => $developer->updatedBy ? [
                'id' => $developer->updatedBy->id,
                'name' => $developer->updatedBy->first_name,
                'email' => $developer->updatedBy->email,
                'role' => optional($developer->updatedBy->role)->name,
            ] : null,
            'keyword' => $developer->importKeywords ?? [],
        ] : null;

        $projects = ProjectList::with([
            'user',
            'propertyType',
            'purpose',
            'property',
            'propertystatus',
            'customFieldValues.customField.templateValue',
            'customFieldValues.customFieldOption',
            'importKeywords',
            'developer',
            'country',
            'state',
            'city'
        ])
        ->where('developer_id', $request->developer_id)
        ->paginate($request->get('per_page', 10));

        if (!$projects) {
            return response()->json([
                'status' => false,
                'message' => 'No projects found for this developer.',
                'developer_info' => $developerInfo,
                'data' => [],
            ], 200);
        }

        // Purpose counts
        $projectsData = $projects->map(function ($project) use ($baseURL) {
            $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL, $project) {
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
                            ->where('project_listing_id', $project->id)
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
                                } elseif ($nestedType === 'file') {
                                    $decoded = is_string($nestedValue) ? json_decode($nestedValue, true) : $nestedValue;
                                    $nestedValue = is_array($decoded)
                                        ? array_map(fn($file) => url($file), $decoded)
                                        : [];
                                } elseif ($nestedType === 'media') {
                                    $decoded = json_decode($nestedValue, true);
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

            // Decode property_type_id safely
            $propertyTypeIds = is_array($project->property_type_id)
                ? array_map('intval', $project->property_type_id)
                : ((is_string($project->property_type_id) && ($decoded = json_decode($project->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($project->property_type_id)) ? [(int)$project->property_type_id] : []));

            $propertyStatusIds = is_array($project->property_status_id)
                ? array_map('intval', $project->property_status_id)
                : ((is_string($project->property_status_id) && ($decoded = json_decode($project->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($project->property_status_id)) ? [(int)$project->property_status_id] : []));

            $propertyTypes = !empty($propertyTypeIds)
                ? PropertyType::whereIn('id', $propertyTypeIds)
                    ->get(['id as property_type_id', 'name as property_type_name'])
                    ->toArray()
                : [];

            $propertyStatuses = !empty($propertyStatusIds)
                ? Status::whereIn('id', $propertyStatusIds)
                    ->get(['id as property_status_id', 'name as property_status_name'])
                    ->toArray()
                : [];


            // Developer
            
             // Decode property_type_id safely
            $developerProjectPropertyTypeIds = is_array($project->developer->property_type_id)
                ? array_map('intval', $project->developer->property_type_id)
                : ((is_string($project->developer->property_type_id) && ($decoded = json_decode($project->developer->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($project->developer->property_type_id)) ? [(int)$project->developer->property_type_id] : []));

            $developerProjectPropertyStatusIds = is_array($project->developer->property_status_id)
                ? array_map('intval', $project->developer->property_status_id)
                : ((is_string($project->developer->property_status_id) && ($decoded = json_decode($project->developer->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                    ? array_map('intval', $decoded)
                    : ((is_numeric($project->developer->property_status_id)) ? [(int)$project->developer->property_status_id] : []));

            $developerProjectPropertyTypes = !empty($developerProjectPropertyTypeIds)
                ? PropertyType::whereIn('id', $developerProjectPropertyTypeIds)
                    ->get(['id as property_type_id', 'name as property_type_name'])
                    ->toArray()
                : [];

            $developerProjectPropertyStatuses = !empty($developerProjectPropertyStatusIds)
                ? Status::whereIn('id', $developerProjectPropertyStatusIds)
                    ->get(['id as property_status_id', 'name as property_status_name'])
                    ->toArray()
                : [];

            return [
                'id' => $project->id,
                'project_unique_id' => $project->project_unique_id,
                'name' => $project->name,
                'description' => $project->description,
                'live_status' => $project->live_status,
                'status_reason' => $project->status_reason,
                'project_status' => $project->project_status,

                'user_id' => $project->user_id,
                'user' => $project->user_id ? [
                    'id' => $project->user->id,
                    'name' => $project->user->first_name,
                    'email' => $project->user->email,
                    'role' => optional($project->user->role)->name,
                ] : null,
                'created_by' => $project->created_by,
                'updated_by' => $project->updated_by,
                'listed_by' => optional(optional($project->user)->role)->name,
                'purpose_id' => $project->purpose_id,
                'purpose_id_name' => optional($project->purpose)->name,
                'property_id' => $project->property_id,
                'property_id_name' => optional($project->property)->name,
                'property_status ' => $propertyStatuses,
                'property_type ' => $propertyTypes,
                'total_view' => $project->analytics()->count(),
                'date' => date('d m Y', strtotime($project->created_at)),
                'time' => date('h:i A', strtotime($project->created_at)),
                'timestamp' => date('d m Y h:i A', strtotime($project->created_at)),
                'keyword' => $project->importKeywords,
                'address' => $project->address,
                'country' => $project->country,
                'state' => $project->state,
                'city' => $project->city,
                'area_locality' => $project->area_locality,
                'colony' => $project->colony,
                'street_address' => $project->street_address,
                'pin_code' => $project->pin_code,
                'complete_status' => $project->complete_status,
                'completed_at' => $project->completed_at,
                'custom_field_values' => $formattedCustomFieldValues,
                'developer_id' => $project->developer_id,
                'developer' => $project->developer ? [
                    'id' => $project->developer->id,
                    'developer_unique_id' => $project->developer->developer_unique_id,
                    'name' => $project->developer->name,
                    'description' => $project->developer->description,
                    'purpose_id' => $project->developer->purpose_id,
                    'purpose_id_name' => optional($project->developer->purpose)->name,
                    'property_id' => $project->developer->property_id,
                    'property_id_name' => optional($project->developer->property)->name,
                    'property_status_id' => $project->developer->property_status_id,
                    'property_type_id' => $project->developer->property_type_id,
                    'propertyStatuses' => $developerProjectPropertyStatuses,
                    'propertyTypes' => $developerProjectPropertyTypes,
                    'country_id' => $project->developer->country_id,
                    'country_name' => optional($project->developer->country)->name,
                    'state_id' => $project->developer->state_id,
                    'state_name' => optional($project->developer->state)->name,
                    'city_id' => $project->developer->city_id,
                    'city_name' => optional($project->developer->city)->name,
                    'address' => $project->developer->address,
                    'area_locality' => $project->developer->area_locality,
                    'colony' => $project->developer->colony,
                    'street_address' => $project->developer->street_address,
                    'pin_code' => $project->developer->pin_code,
                    'featured_image' => $project->developer->featured_image
                        ? url($project->developer->featured_image)
                        : null,
                    'live_status' => $project->developer->live_status,
                    'temporary_status' => $project->developer->temporary_status,
                    'status_reason' => $project->developer->status_reason,
                    'user_id' => $project->developer->user_id,
                    'user' => $project->developer->user_id ? [
                        'id' => $project->developer->user->id,
                        'name' => $project->developer->user->first_name,
                        'email' => $project->developer->user->email,
                        'role' => optional($project->developer->user->role)->name,
                    ] : null,
                    'created_by' => $project->developer->createdBy ? [
                        'id' => $project->developer->createdBy->id,
                        'name' => $project->developer->createdBy->first_name,
                        'email' => $project->developer->createdBy->email,
                        'role' => optional($project->developer->createdBy->role)->name,
                    ] : null,
                    'updated_by' => $project->developer->updatedBy ? [
                        'id' => $project->developer->updatedBy->id,
                        'name' => $project->developer->updatedBy->first_name,
                        'email' => $project->developer->updatedBy->email,
                        'role' => optional($project->developer->updatedBy->role)->name,
                    ] : null,
                    'keyword' => $project->developer->importKeywords ?? [],
                ] : null,
            ];
        });

        return response()->json([
            'message' => 'Projects retrieved successfully.',
            'developer_info' => $developerInfo, // 🟢 top-level developer info
            'data' => $projectsData,
            'meta' => [
                'current_page' => $projects->currentPage(),
                'from' => $projects->firstItem(),
                'last_page' => $projects->lastPage(),
                'path' => $request->url(),
                'per_page' => $projects->perPage(),
                'to' => $projects->lastItem(),
                'total' => $projects->total(),
            ],
            'links' => [
                'first' => $projects->url(1),
                'last' => $projects->url($projects->lastPage()),
                'prev' => $projects->previousPageUrl(),
                'next' => $projects->nextPageUrl(),
            ]
        ]);

    } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage() . ' on line ' . $th->getLine()], 500);
    }
}


}
