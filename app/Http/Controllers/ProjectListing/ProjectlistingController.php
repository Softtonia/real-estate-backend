<?php

namespace App\Http\Controllers\ProjectListing;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldRepeaterValues;
use Illuminate\Http\Request;
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Log;

class ProjectlistingController extends Controller
{


    // this is for store the data
    public function store(Request $request)
    {
        //  \Log::info($request->all());
        try {
            // Get authenticated user from middleware
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized.'], 401);
            }

            $userId = $user->id;
            $userRole = $user->role->name;

            // Ensure non-admin users have live_status set to 'Under Review'
            if ($userRole !== 'admin') {
                $request->merge(['live_status' => 'Under Review']);
            }

            // Validate the request
            $validatedData = $request->validate([
                'purpose_id' => 'nullable',
                'property_id' => 'nullable',
                'property_type_id' => 'nullable|array',
                'property_type_id.*' => 'exists:property_types,id',

                'property_status_id' => 'nullable|array',
                'property_status_id.*' => 'exists:status,id',

                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'developer_id' => 'nullable|exists:developer_listings,id',
                'live_status' => 'in:Approve,Disapprove,Reject,Under Review,Modify Review',
                'temporary_status' => 'nullable|in:active,deactive',
                'country_id' => 'nullable|exists:countries,id',
                'state_id' => 'nullable|exists:states,id',
                'city_id' => 'nullable|exists:cities,id',
                'status_reason' => $request->live_status === 'Reject' ? 'required|string|max:500' : 'nullable',
                'area_locality' => 'nullable|string',
                'colony' => 'nullable|string',
                'street_address' => 'nullable|string',
                'pin_code' => 'required|numeric|digits:6',
                'user_id' => 'sometimes|exists:users,id'

            ]);



            // Set status_reason to null if live_status is not "Reject"
            $validatedData['status_reason'] = $request->live_status === 'Reject' ? $request->status_reason : null;

             // prefix get
            $prefix = DB::table('site_settings')->value('project_prefix');
            $prefix = $prefix ?? 'URPP';

            // last project_unique_id  (descending order )
            $lastId = DB::table('project_listings')
                ->orderBy('id', 'desc')
                ->value('project_unique_id');

            if ($lastId) {

                $number = (int) str_replace($prefix, '', $lastId);

                // increment
                $newNumber = $number + 1;
            } else {

                $newNumber = 000001;
            }

            // final property id
            $project_unique_id = $prefix . $newNumber;

            // Set temporary_status: "active" by default if not provided
            $validatedData['temporary_status'] = $validatedData['temporary_status'] ?? 'active';



            // featured image handle

            if ($request->hasFile('featured_image')) {
                $file = $request->file('featured_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
                $file->move(public_path('uploads/projects'), $name); // Move file to folder

                // ✅ Store only `/uploads/projects/{file_name}`
                $featuredImage = '/uploads/projects/' . $name;
            } elseif (!empty($request->featured_image) && filter_var($request->featured_image, FILTER_VALIDATE_URL)) {
                $featuredImage = $request->featured_image; // Store URL directly if provided
            } else {
                $featuredImage = null; // If empty, store null
            }

            // user_id handle
            if ($userRole === 'admin') {
                $validatedData['user_id'] = $request->user_id ?? $userId;

            } else {
                $validatedData['user_id'] = $userId;
            }


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



            // Prepare project data
            $projectData = array_merge($validatedData, [
                'project_unique_id' => $project_unique_id,
                'created_by' => $userId, // Store the authenticated user's ID
                'address' => $request->address,
                'featured_image' => $featuredImage,
                'area_locality' => $request->area_locality,
                'colony' => $request->colony,
                'street_address' => $request->street_address,
                'pin_code' => $request->pin_code,
            ]);

            // Create project
            $project = ProjectList::create($projectData);
            $lastInsertId = $project->id;

            // Add keywords
            if (!empty($request->keyword)) {
                $explod_keywords = explode(',', $request->keyword);
                foreach ($explod_keywords as $row) {
                    Keyword::create([
                        'project_id' => $lastInsertId,
                        'keyword' => $row,
                    ]);
                }
            }

            // ✅ Fix: Handle repeater fields correctly
            if ($request->has('repeater_fields')) {
                foreach ($request->repeater_fields as $repeaterFieldIndex => $repeaterField) {
                    $customFieldData = [
                        'project_listing_id' => $project->id,
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

                        case 'media':
                            // ✅ Fix: Handle media uploads correctly
                            $mediaFiles = [];

                            if ($request->hasFile("repeater_fields.$repeaterFieldIndex.field_value")) {
                                $files = $request->file("repeater_fields.$repeaterFieldIndex.field_value");

                                foreach ((array) $files as $file) {
                                    if ($file->isValid()) {
                                        $fileName = time() . '_' . $file->getClientOriginalName();
                                        $file->move(public_path('uploads/media'), $fileName);
                                        $mediaFiles[] = $fileName;
                                    }
                                }

                                if (!empty($mediaFiles)) {
                                    $customFieldData['field_meta_value'] = json_encode($mediaFiles);
                                }
                            }
                            break;

                        case 'file':
                            //  Fix: Handle multiple file uploads correctly
                            if ($request->hasFile("repeater_fields.$repeaterFieldIndex.field_value")) {
                                $files = $request->file("repeater_fields.$repeaterFieldIndex.field_value");
                                $filePaths = [];

                                foreach ((array) $files as $file) {
                                    if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                        if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
                                            $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                            $relativePath = 'storage/uploads/customfield/projects/files/' . $uniqueFileName;

                                            // Store to: storage/app/public/uploads/gallery
                                            $file->storeAs('public/uploads/customfield/projects/files', $uniqueFileName);

                                            $filePaths[] = $relativePath;
                                        } else {
                                            return response()->json([
                                                'error' => 'Invalid file format. Only PDF files are allowed.'
                                            ], 400);
                                        }
                                    }
                                }

                                $customFieldData['field_meta_value'] = json_encode($filePaths); // Save JSON-encoded array
                            }
                            break;


                        case 'repeater':
                            if (!empty($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $rowIndex => $row) {
                                    $uniqueRowId = uniqid('repeater_');
                                    foreach ($row as $subField) {
                                        $nestedFieldData = [
                                            'project_listing_id' => $project->id,
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
                                                $nestedFieldData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'select':
                                            case 'radio':
                                                $option = DB::table('custom_field_repeater_options')
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->where('value', $subField['field_value'])
                                                    ->first();
                                                if ($option) {
                                                    $nestedFieldData['custom_field_repeater_options_id'] = $option->id;
                                                }
                                                $nestedFieldData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'checkbox':
                                                $values = explode(',', $subField['field_value']);
                                                $nestedFieldData['field_meta_value'] = implode(',', $values);
                                                $optionIds = DB::table('custom_field_repeater_options')
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->whereIn('value', $values)
                                                    ->pluck('id')
                                                    ->implode(',');
                                                $nestedFieldData['custom_field_repeater_options_id'] = $optionIds;
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
                                                    $nestedFieldData['field_meta_value'] = json_encode($fileNames);
                                                }
                                                break;

                                            case 'file':
                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    $filePaths = [];
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                                                $fileName = time() . '_' . $file->getClientOriginalName();
                                                                $relativePath = 'storage/uploads/customfield/projects/files/' . $fileName;

                                                                $file->storeAs('public/uploads/customfield/projects/files', $fileName);
                                                                $filePaths[] = $relativePath;
                                                            } else {
                                                                return response()->json(['error' => 'Invalid file format in repeater. Only PDF, DOC, DOCX files allowed.'], 400);
                                                            }
                                                        }
                                                    }
                                                    $nestedFieldData['field_meta_value'] = json_encode($filePaths); // store array of relative paths
                                                }
                                                break;
                                        }

                                        // Save nested repeater field value
                                        CustomFieldRepeaterValues::create($nestedFieldData);
                                    }
                                }
                            }
                            break;


                        default:
                            return response()->json(['error' => 'Unsupported field type'], 400);
                    }

                    Customfieldvalue::create($customFieldData);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Project created successfully.',
                'data' => $project,
            ], 201);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


   

    ### new index function 10/07/2025 ####
    public function index(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

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
            ->where('live_status', 'Approve')
            ->when($request->country_id, function ($query) use ($request) {
                return $query->where('country_id', $request->country_id);
            })
            ->when($request->state_id, function ($query) use ($request) {
                return $query->where('state_id', $request->state_id);
            })
            ->when($request->city_id, function ($query) use ($request) {
                return $query->where('city_id', $request->city_id);
            })
            ->paginate($request->get('per_page', 10));

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
                                ->where('project_listing_id', $property->id)
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

                                    // 💡 Get template info for nested field
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

                $propertyTypeIds = is_array($project->property_type_id)
                    ? array_map('intval', $project->property_type_id)
                    : ((is_string($project->property_type_id) && ($decoded = json_decode($project->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_type_id)) ? [(int)$project->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($project->property_status_id)
                    ? array_map('intval', $project->property_status_id)
                    : ((is_string($project->property_status_id) && ($decoded = json_decode($project->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_status_id)) ? [(int)$project->property_status_id] : []));

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
                    ] :null,
                    'created_by' => $project->created_by,
                    'updated_by' => $project->updated_by,
                    'listed_by' => optional(optional($project->user)->role)->name,
                    'purpose_id' => $project->purpose_id,
                    'purpose_id_name' => optional($project->purpose)->name,
                    'property_id' => $project->property_id,
                    'property_id_name' => optional($project->property)->name,
                   
                    'property_status '=> $propertyStatuses, 
                    'property_type '=> $propertyTypes,
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
                        'property_status_id_name' => optional($project->developer->propertystatus)->name,
                        'property_type_id' => $project->developer->property_type_id,
                        'property_type_id_name' => optional($project->developer->propertyType)->name,
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
                        ] :null,
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

    ### end new index function 10/07/2025 ####


    public function getUserProject(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized.'], 401);
            }

            $isAdmin = $user->role->name === 'admin';

            // Base query
            $query = ProjectList::query();

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

            $projects = $query->get();

            if ($projects->isEmpty()) {
                return response()->json(['message' => 'No Project found.'], 200);
            }

            // Format featured_image URL
            $projects = $projects->map(function ($project) {

                  //  Decode property_type_id safely

                $propertyTypeIds = is_array($project->property_type_id)
                    ? array_map('intval', $project->property_type_id)
                    : ((is_string($project->property_type_id) && ($decoded = json_decode($project->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_type_id)) ? [(int)$project->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($project->property_status_id)
                    ? array_map('intval', $project->property_status_id)
                    : ((is_string($project->property_status_id) && ($decoded = json_decode($project->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_status_id)) ? [(int)$project->property_status_id] : []));

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

                
                if (!empty($project->featured_image)) {
                    $project->featured_image = filter_var($project->featured_image, FILTER_VALIDATE_URL)
                        ? $project->featured_image
                        : url(ltrim($project->featured_image, '/'));
                }

                $project->property_type = $propertyTypes;
                $project->property_status = $propertyStatuses;
                return $project;
            });

            return response()->json([
                'status' => true,
                'message' => 'Project retrieved successfully.',
                'data' => $projects
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function indexByAdmin(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();
             $perPage = $request->input('per_page', 10);

            // Fetch projects with related models, including created_by & updated_by user roles
            $projects = ProjectList::with([
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
                'importKeywords',
                'developer',
                'createdBy.role',
                'updatedBy.role',
            ])->paginate($perPage);

            $projectsData = $projects->map(function ($project) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(fn($file) => $baseURL . '/uploads/media/' . $file);
                    } else {
                        $fieldValueArray = $fieldValue;
                    }

                    return [
                        'custom_field_id' => optional($customField)->id,
                        'field_type' => optional($customField)->field_type,
                        'field_value' => $fieldValueArray,
                        'field_name' => optional($customField)->field_label,
                    ];
                });

                  //  Decode property_type_id safely

                $propertyTypeIds = is_array($project->property_type_id)
                    ? array_map('intval', $project->property_type_id)
                    : ((is_string($project->property_type_id) && ($decoded = json_decode($project->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_type_id)) ? [(int)$project->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($project->property_status_id)
                    ? array_map('intval', $project->property_status_id)
                    : ((is_string($project->property_status_id) && ($decoded = json_decode($project->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_status_id)) ? [(int)$project->property_status_id] : []));

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
                    'id' => $project->id,
                    'project_unique_id' => $project->project_unique_id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'address' => $project->address,
                    'country' => $project->country,
                    'state' => $project->state,
                    'city' => $project->city,
                    'area_locality' => $project->area_locality,
                    'colony' => $project->colony,
                    'street_address' => $project->street_address,
                    'pin_code' => $project->pin_code,
                    'live_status' => $project->live_status,
                    'temporary_status' => $project->temporary_status,
                    'status_reason' => $project->status_reason,
                    'project_status' => $project->project_status,
                    'user_id' => $project->user_id,
                    'user' => $project->user_id ? [
                        'id' => $project->user->id,
                        'name' => $project->user->first_name,
                        'email' => $project->user->email,
                        'role' => optional($project->user->role)->name,
                    ] :null,
                    'created_by' => $project->created_by,
                    'created_by_role' => optional($project->createdBy)->role->name ?? 'N/A', // Role name or 'N/A'
                    'updated_by' => $project->updated_by,
                    'updated_by_role' => optional($project->updatedBy)->role->name ?? 'N/A', // Role name or 'N/A'
                    'listed_by' => optional(optional($project->user)->role)->name ?? 'N/A',
                    'purpose_id' => $project->purpose_id,
                    'purpose_id_name' => optional($project->purpose)->name,
                    'property_id' => $project->property_id,
                    'property_id_name' => optional($project->property)->name,
                    'property_status '=> $propertyStatuses,
                    'property_type '=> $propertyTypes,
                    'total_view' => $project->analytics()->count(),
                    'date' => date('d m Y', strtotime($project->created_at)),
                    'time' => date('h:m A', strtotime($project->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($project->created_at)),
                    'keyword' => $project->importKeywords,
                    'custom_field_values' => $formattedCustomFieldValues,
                    'top_featured_id' => $project->top_featured_id,
                    'featured' => $project->top_featured_id !== null, // true if not null, else false
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
                        'property_status_id_name' => optional($project->developer->propertystatus)->name,
                        'property_type_id' => $project->developer->property_type_id,
                        'property_type_id_name' => optional($project->developer->propertyType)->name,
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
                        // 'user' => $project->developer->user_id ? [
                        //         'id' => $project->developer->user->id,
                        //         'name' => $project->developer->user->first_name,
                        //         'email' => $project->developer->user->email,
                        //         'role' => optional($project->developer->user->role)->name,
                        //     ] :null,
                        // 'created_by' => $project->developer->createdBy ? [
                        //     'id' => $project->developer->createdBy->id,
                        //     'name' => $project->developer->createdBy->first_name,
                        //     'email' => $project->developer->createdBy->email,
                        //     'role' => optional($project->developer->createdBy->role)->name,
                        // ] : null,
                        // 'updated_by' => $project->developer->updatedBy ? [
                        //     'id' => $project->developer->updatedBy->id,
                        //     'name' => $project->developer->updatedBy->first_name,
                        //     'email' => $project->developer->updatedBy->email,
                        //     'role' => optional($project->developer->updatedBy->role)->name,
                        // ] : null,

                        'user' => optional($project->developer->user, function ($user) {
                            return [
                                'id' => $user->id,
                                'name' => $user->first_name,
                                'email' => $user->email,
                                'role' => optional($user->role)->name,
                            ];
                        }),
                        'created_by' => optional($project->developer->createdBy, function ($createdBy) {
                            return [
                                'id' => $createdBy->id,
                                'name' => $createdBy->first_name,
                                'email' => $createdBy->email,
                                'role' => optional($createdBy->role)->name,
                            ];
                        }),
                        'updated_by' => optional($project->developer->updatedBy, function ($updatedBy) {
                            return [
                                'id' => $updatedBy->id,
                                'name' => $updatedBy->first_name,
                                'email' => $updatedBy->email,
                                'role' => optional($updatedBy->role)->name,
                            ];
                        }),
                        'keyword' => $project->developer->importKeywords ?? [],

                    ] : null,
                ];
            });

            return response()->json([
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
            ],200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
        }
    }

    // this is for update the record
    public function update(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized.'], 401);
            }

            $userId = $user->id;
            $userRole = $user->role->name;

            // Ensure non-admin users have live_status set to 'Modify Review'
            if ($userRole !== 'admin') {
                $request->merge(['live_status' => 'Modify Review']);
            }

            // Validate the request
            $validatedData = $request->validate([
                'id' => 'required|exists:project_listings,id',
                'purpose_id' => 'nullable',
                'property_id' => 'nullable',
                'property_type_id' => 'nullable|array',
                'property_type_id.*' => 'exists:property_types,id',

                'property_status_id' => 'nullable|array',
                'property_status_id.*' => 'exists:status,id',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'developer_id' => 'nullable|exists:developer_listings,id',
                'live_status' => 'required|in:Approve,Disapprove,Reject,Under Review,Modify Review',
                'temporary_status' => 'nullable|in:active,deactive',
                'country_id' => 'nullable|exists:countries,id',
                'state_id' => 'nullable|exists:states,id',
                'city_id' => 'nullable|exists:cities,id',
                'status_reason' => $request->live_status === 'Reject' ? 'required|string|max:500' : 'nullable',
                'area_locality' => 'nullable|string',
                'colony' => 'nullable|string',
                'street_address' => 'nullable|string',
                'pin_code' => 'required|numeric|digits:6',
                'user_id' => 'sometimes|exists:users,id'
            ]);

            // Set status_reason to null if live_status is not "Reject"
            $validatedData['status_reason'] = $request->live_status === 'Reject' ? $request->status_reason : null;

            // Find the project
            $project = ProjectList::findOrFail($request->id);

            // ✅ Check access permissions
            if ($user->role->name !== 'admin' && $project->created_by !== $userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only update your own project.',
                ], 403);
            }


            // Set `temporary_status`: Default to "active" unless provided
            $temporaryStatus = $request->has('temporary_status') ? $request->temporary_status : 'active';



            // Store old image path
            $oldImage = $project->featured_image;

            // Handle image upload
            if ($request->hasFile('featured_image')) {
                $file = $request->file('featured_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('uploads/projects'), $name);
                $featuredImage = '/uploads/projects/' . $name;

                // ✅ Delete old image if exists and not a URL
                if (!empty($oldImage) && File::exists(public_path($oldImage))) {
                    File::delete(public_path($oldImage));
                }
            } elseif (!empty($request->featured_image) && filter_var($request->featured_image, FILTER_VALIDATE_URL)) {
                $featuredImage = $request->featured_image;
            } else {
                $featuredImage = $oldImage; // Keep old if nothing new provided
            }

            // user_id handle
            if ($userRole === 'admin') {
                $validatedData['user_id'] = $request->user_id ;
                ;
            } else {
                $validatedData['user_id'] = $userId;
            }

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


            // Prepare update data
            $updateData = [
                'name' => $request->name ?? $project->name,
                'description' => $request->description ?? $project->description,
                'purpose_id' => $request->purpose_id ?? $project->purpose_id,
                'property_id' => $request->property_id ?? $project->property_id,
                'property_status_id' => $request->property_status_id ?? $project->property_status_id,
                'property_type_id' => $request->property_type_id ?? $project->property_type_id,
                'developer_id' => $request->developer_id ?? $project->developer_id,
                'live_status' => $request->live_status,
                'status_reason' => $validatedData['status_reason'], // Ensure proper status_reason logic
                'country_id' => $request->country_id ?? $project->country_id,
                'state_id' => $request->state_id ?? $project->state_id,
                'city_id' => $request->city_id ?? $project->city_id,
                'temporary_status' => $temporaryStatus,
                'updated_by' => $userId, // Store updated_by
                'address' => $request->address,
                'featured_image' => $featuredImage,
                'area_locality' => $request->area_locality,
                'colony' => $request->colony,
                'street_address' => $request->street_address,
                'pin_code' => $request->pin_code,
                'user_id' => $request->user_id ?? $project->user_id,
            ];

            // Update project
            $project->update($updateData);

            // Update Keywords
            if (!empty($request->keyword)) {
                Keyword::where('project_id', $project->id)->delete();
                foreach (explode(',', $request->keyword) as $keyword) {
                    Keyword::create(['project_id' => $project->id, 'keyword' => $keyword]);
                }
            }

            

            if ($request->has('repeater_fields')) {
                // ✅ Delete old values before insert
                Customfieldvalue::where('project_listing_id', $project->id)->delete();
                CustomFieldRepeaterValues::where('project_listing_id', $project->id)->delete(); // ✅ delete nested data

                foreach ($request->repeater_fields as $repeaterField) {
                    $customFieldData = [
                        'project_listing_id' => $project->id,
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
                            $option = DB::table('custom_field_options')
                                ->where('custom_field_id', $repeaterField['custom_field_id'])
                                ->where('value', $repeaterField['field_value'])
                                ->first();
                            if ($option) {
                                $customFieldData['custom_field_options_id'] = $option->id;
                            }
                            $customFieldData['field_meta_value'] = $repeaterField['field_value'];
                            break;

                        case 'checkbox':
                            $values = explode(',', $repeaterField['field_value']);
                            $customFieldData['field_meta_value'] = implode(',', $values);
                            $optionIds = DB::table('custom_field_options')
                                ->whereIn('value', $values)
                                ->where('custom_field_id', $repeaterField['custom_field_id'])
                                ->pluck('id')
                                ->implode(',');
                            $customFieldData['custom_field_options_id'] = $optionIds;
                            break;

                        

                        case 'media':
                            // Existing values load
                            $existingMedia = isset($repeaterField['existing_value']) ? json_decode($repeaterField['existing_value'], true) : [];
                            $mediaFiles = is_array($existingMedia) ? $existingMedia : [];

                            if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $value) {
                                    if (is_string($value)) {
                                        $mediaFiles[] = $value;
                                    } elseif ($value instanceof \Illuminate\Http\UploadedFile && $value->isValid()) {
                                        $fileName = time() . '_' . $value->getClientOriginalName();
                                        $value->move(public_path('uploads/media'), $fileName);
                                        $mediaFiles[] = $fileName;
                                    }
                                }
                            }

                            if (!empty($mediaFiles)) {
                                $customFieldData['field_meta_value'] = json_encode($mediaFiles);
                            }
                            break;

                        case 'file':
                            // Existing values load
                            $existingFiles = isset($repeaterField['existing_value']) ? json_decode($repeaterField['existing_value'], true) : [];
                            $filePaths = is_array($existingFiles) ? $existingFiles : [];

                            if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $file) {
                                    if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                        if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                            $fileName = time() . '_' . $file->getClientOriginalName();
                                            $relativePath = 'storage/uploads/customfield/projects/files/' . $fileName;
                                            $file->storeAs('public/uploads/customfield/projects/files', $fileName);
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
                                $customFieldData['field_meta_value'] = json_encode($filePaths);
                            }
                            break;


                        case 'repeater':
                            if (!empty($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $row) {
                                    $uniqueRowId = uniqid('repeater_');
                                    foreach ($row as $subField) {
                                        $nestedData = [
                                            'project_listing_id' => $project->id,
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
                                                $nestedData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'select':
                                            case 'radio':
                                                $option = DB::table('custom_field_repeater_options')
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->where('value', $subField['field_value'])
                                                    ->first();
                                                if ($option) {
                                                    $nestedData['custom_field_repeater_options_id'] = $option->id;
                                                }
                                                $nestedData['field_meta_value'] = $subField['field_value'];
                                                break;

                                            case 'checkbox':
                                                $values = explode(',', $subField['field_value']);
                                                $nestedData['field_meta_value'] = implode(',', $values);
                                                $optionIds = DB::table('custom_field_repeater_options')
                                                    ->where('custom_field_repeater_id', $subField['sub_field_id'])
                                                    ->whereIn('value', $values)
                                                    ->pluck('id')
                                                    ->implode(',');
                                                $nestedData['custom_field_repeater_options_id'] = $optionIds;
                                                break;

                                            

                                            case 'media':
                                                // Existing values merge
                                                $existingMedia = isset($subField['existing_value']) ? json_decode($subField['existing_value'], true) : [];
                                                $fileNames = is_array($existingMedia) ? $existingMedia : [];

                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            $fileName = time() . '_' . $file->getClientOriginalName();
                                                            $file->move(public_path('uploads/media'), $fileName);
                                                            $fileNames[] = $fileName;
                                                        } elseif (is_string($file)) {
                                                            $fileNames[] = $file; // already existing file
                                                        }
                                                    }
                                                }

                                                if (!empty($fileNames)) {
                                                    $nestedData['field_meta_value'] = json_encode($fileNames);
                                                }
                                                break;

                                            case 'file':
                                                // Existing values merge
                                                $existingFiles = isset($subField['existing_value']) ? json_decode($subField['existing_value'], true) : [];
                                                $filePaths = is_array($existingFiles) ? $existingFiles : [];

                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                                                $fileName = time() . '_' . $file->getClientOriginalName();
                                                                $relativePath = 'storage/uploads/customfield/projects/files/' . $fileName;

                                                                $file->storeAs('public/uploads/customfield/projects/files', $fileName);
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
                                                    $nestedData['field_meta_value'] = json_encode($filePaths);
                                                }
                                                break;

                                        }

                                        CustomFieldRepeaterValues::create($nestedData);
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
                'message' => 'Project updated successfully.',
                'data' => $project,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // this is for delete the record
    public function destroy(Request $request)
    {


        try {
            $user = Auth::user();
            $createdBy = $user->id;

            $id = $request->id;
            $project = ProjectList::find($id);
            if (!$project) {
                return response()->json(['message' => 'No project found'], 404);
            }
            if ($user->role !== 'admin' && $project->created_by !== $createdBy) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only delete your own projects.',
                ], 403);
            }



            // Delete specific related records
            $project->customFieldValues()->delete();

            // Delete the project
            $project->delete();

            $returnRes = [
                'status' => true,
                'message' => 'Data deleted successfully.'
            ];

            return response()->json($returnRes, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
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

            $projects = ProjectList::with([
                'user.userDetail',
                'createdBy.role',
                'updatedBy.role',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'customFieldValues.customField',
                'customFieldValues.customFieldOption',
                'importKeywords',
                'developer',
                'country',
                'state',
                'city',
            ])->where('id', $request->id)->first();

            if (!$projects) {
                return response()->json(['error' => 'Project not found'], 200);
            }

            // ✅ Check access permissions
            if ($user->role->name !== 'admin' && $projects->created_by !== $createdBy) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only view your own projects.',
                ], 403);
            }

            $createdByData = $projects->createdBy ? [
                'id' => $projects->createdBy->id,
                'name' => $projects->createdBy->first_name,
                'email' => $projects->createdBy->email,
                'role' => optional($projects->createdBy->role)->name,
            ] : null;

            $updatedByData = $projects->updatedBy ? [
                'id' => $projects->updatedBy->id,
                'name' => $projects->updatedBy->first_name,
                'email' => $projects->updatedBy->email,
                'role' => optional($projects->updatedBy->role)->name,
            ] : null;

            if (!empty($projects->featured_image)) {
                $projects->featured_image = filter_var($projects->featured_image, FILTER_VALIDATE_URL)
                    ? $projects->featured_image
                    : url($projects->featured_image);
            }

            $repeaterFields = $projects->customFieldValues->map(function ($customFieldValue) use ($baseURL, $projects) {
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
                        ->where('project_listing_id', $projects->id)
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
                } elseif ($fieldType === 'media') {
                    $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;

                    $fieldValue = is_array($decoded)
                        ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                        : [];

                    $existingValue = is_array($decoded) ? $decoded : [];
                } elseif ($fieldType === 'file') {
                    $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                    $fieldValue = is_array($decoded)
                        ? array_map(fn($file) => url($file), $decoded)
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

                // ✅ Only for top-level checkbox fields
                if ($fieldType === 'checkbox') {
                    $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
                }

                return $fieldArray;
            });

               //  Decode property_type_id safely

                $propertyTypeIds = is_array($projects->property_type_id)
                    ? array_map('intval', $projects->property_type_id)
                    : ((is_string($projects->property_type_id) && ($decoded = json_decode($projects->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($projects->property_type_id)) ? [(int)$projects->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($projects->property_status_id)
                    ? array_map('intval', $projects->property_status_id)
                    : ((is_string($projects->property_status_id) && ($decoded = json_decode($projects->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($projects->property_status_id)) ? [(int)$projects->property_status_id] : []));

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
                'id' => $projects->id,
                'project_unique_id' => $projects->project_unique_id,
                'name' => $projects->name,
                'description' => $projects->description,
                'address' => $projects->address,
                'country_id' => $projects->country_id,
                'country_name' => optional($projects->country)->name,
                'state_id' => $projects->state_id,
                'state_name' => optional($projects->state)->name,
                'city_id' => $projects->city_id,
                'city_name' => optional($projects->city)->name,
                'property_address' => $projects->property_address,
                'area_locality' => $projects->area_locality,
                'colony' => $projects->colony,
                'street_address' => $projects->street_address,
                'pin_code' => $projects->pin_code,
                'featured_image' => $projects->featured_image,
                'live_status' => $projects->live_status,
                'status_reason' => $projects->status_reason,
                'project_status' => $projects->project_status,
                'temporary_status' => $projects->temporary_status,
                'user_id' => $projects->user_id,
                'user' => $projects->user_id ? [
                        'id' => $projects->user->id,
                        'name' => $projects->user->first_name,
                        'email' => $projects->user->email,
                        'role' => optional($projects->user->role)->name,
                    ] :null,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'listed_by' => optional(optional($projects->user)->role)->name,
                'purpose_id' => $projects->purpose_id,
                'purpose_id_name' => optional($projects->purpose)->name,
                'property_id' => $projects->property_id,
                'property_id_name' => optional($projects->property)->name,
                
                'property_type' => $propertyTypes,
                'property_status' => $propertyStatuses,
                'total_view' => $projects->analytics()->count(),
                'date' => $projects->created_at ? $projects->created_at->format('d m Y') : null,
                'time' => $projects->created_at ? $projects->created_at->format('h:i A') : null,
                'timestamp' => $projects->created_at ? $projects->created_at->format('d m Y h:i A') : null,
                'keyword' => $projects->importKeywords,
                'repeater_fields' => $repeaterFields,
                'developer_id' => $projects->developer_id,
                  'developer' => $projects->developer ? [
                        'id' => $projects->developer->id,
                        'developer_unique_id' => $projects->developer->developer_unique_id,
                        'name' => $projects->developer->name,
                        'description' => $projects->developer->description,
                        'purpose_id' => $projects->developer->purpose_id,
                        'purpose_id_name' => optional($projects->developer->purpose)->name,
                        'property_id' => $projects->developer->property_id,
                        'property_id_name' => optional($projects->developer->property)->name,
                        'property_status_id' => $projects->developer->property_status_id,
                        'property_status_id_name' => optional($projects->developer->propertystatus)->name,
                        'property_type_id' => $projects->developer->property_type_id,
                        'property_type_id_name' => optional($projects->developer->propertyType)->name,
                        'country_id' => $projects->developer->country_id,
                        'country_name' => optional($projects->developer->country)->name,
                        'state_id' => $projects->developer->state_id,
                        'state_name' => optional($projects->developer->state)->name,
                        'city_id' => $projects->developer->city_id,
                        'city_name' => optional($projects->developer->city)->name,
                        'address' => $projects->developer->address,
                        'area_locality' => $projects->developer->area_locality,
                        'colony' => $projects->developer->colony,
                        'street_address' => $projects->developer->street_address,
                        'pin_code' => $projects->developer->pin_code,
                        'featured_image' => $projects->developer->featured_image
                            ? url($projects->developer->featured_image)
                            : null,
                        'live_status' => $projects->developer->live_status,
                        'temporary_status' => $projects->developer->temporary_status,
                        'status_reason' => $projects->developer->status_reason,
                        'user_id' => $projects->developer->user_id,
                        'user' => $projects->developer->user_id ? [
                            'id' => $projects->developer->user->id,
                            'name' => $projects->developer->user->first_name,
                            'email' => $projects->developer->user->email,
                            'role' => optional($projects->developer->user->role)->name,
                        ] :null,
                        'created_by' => $projects->developer->createdBy ? [
                            'id' => $projects->developer->createdBy->id,
                            'name' => $projects->developer->createdBy->first_name,
                            'email' => $projects->developer->createdBy->email,
                            'role' => optional($projects->developer->createdBy->role)->name,
                        ] : null,
                        'updated_by' => $projects->developer->updatedBy ? [
                            'id' => $projects->developer->updatedBy->id,
                            'name' => $projects->developer->updatedBy->first_name,
                            'email' => $projects->developer->updatedBy->email,
                            'role' => optional($projects->developer->updatedBy->role)->name,
                        ] : null,
                        'keyword' => $projects->developer->importKeywords ?? [],

                    ] : null,
            ]);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    ########### end get data by id ################

    // this is for update property status
    public function updateProjectStatus(Request $request)
    {

        try {
            // Validate the request data
            $validatedData = $request->validate([
                'project_id' => 'required|exists:project_listings,id',
                'project_status' => 'required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return validation error response
            return response()->json(['error' => $e->errors()], 422);
        }

        $user = Auth::user();
        $createdBy = $user->id;

        // Check if the setting record exists
        $projectData = ProjectList::where('id', $request->project_id)->first();

        // ✅ Check access permissions
        if ($user->role !== 'admin' && $projectData->created_by !== $createdBy) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: You can only update your own projects.',
            ], 403);
        }

        if (!$projectData) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Project Id',
            ], 401);
        }


        if ($request->project_status == 'active') {
            $project_status_val = '1';
        } else {
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


    // this is for update property status by admin
    public function updateProjectStatusByAdmin(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'project_id' => 'required|exists:project_listings,id',
                'live_status' => 'required|in:Approve,Disapprove,Reject,Under Review,Modify Review',
                'status_reason' => $request->live_status === 'Reject' ? 'required|string|max:500' : 'nullable',
            ]);

            // Find the project
            $projectData = ProjectList::find($request->project_id);

            if (!$projectData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid Project ID',
                ], 404);
            }

            // Set status and status_reason
            $projectData->live_status = $request->live_status;
            $projectData->status_reason = ($request->live_status === 'Reject') ? $request->status_reason : null;

            $projectData->update();

            // Return success response
            return response()->json([
                'status' => true,
                'message' => 'Live status updated successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for listing by userId
    // public function getProjectByUserId(Request $request)
    // {
    //     try {
    //         $baseURL = config('app.url');
    //         $basePath = public_path();
    //         $user = $request->user();
    //         $requestedUserId = $request->input('user_id');

    //         if (strtolower($user->role->name) !== 'admin') {
    //             // Non-admin can only access their own data
    //             $requestedUserId = $user->id;
    //         }

    //         $projects = ProjectList::with([
    //             'country',
    //             'state',
    //             'city',
    //             'user',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption'
    //         ])->where('user_id', $requestedUserId)->get();

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
    //                     'field_name' => $customField ? $customField->field_label : null,
    //                     // 'custom_field_options' => $customFieldOptions,
    //                 ];
    //             });

    //             // Prepare property data
    //             $projectData = [
    //                 'id' => $property->id,
    //                 'project_unique_id' => $property->project_unique_id,
    //                 'name' => $property->name,
    //                 'description' => $property->description,
    //                 'country_id' => $property->country_id,
    //                 'country_name' => optional($property->country)->name,
    //                 'state_id' => $property->state_id,
    //                 'state_name' => optional($property->state)->name,
    //                 'city_id' => $property->city_id,
    //                 'city_name' => optional($property->city)->name,
    //                 'live_status' => $property->live_status,
    //                 'status_reason' => $property->status_reason,
    //                 'project_status' => $property->project_status,
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

    //             return $projectData;
    //         });

    //         return response()->json($projectsData);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    ## new listing by user id ###

    public function getProjectByUserId(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();
            $user = $request->user();
            $requestedUserId = $request->input('user_id');

            if (strtolower($user->role->name) !== 'admin') {
                $requestedUserId = $user->id;
            }

            $projects = ProjectList::with([
                'country',
                'state',
                'city',
                'user.role',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'customFieldValues.customField.templateValue',
                'customFieldValues.customFieldOption'
            ])->where('user_id', $requestedUserId)->get();

            $projectsData = $projects->map(function ($property) use ($baseURL) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL, $property) {
                    $customField = $customFieldValue->customField;
                    $fieldType = $customField->field_type ?? null;
                    $fieldValue = $customFieldValue->field_meta_value ?? '';
                    $fieldValueArray = null;
                    $allAvailableOptions = [];

                    if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
                        $options = DB::table('custom_field_options')
                            ->where('custom_field_id', $customField->id)
                            ->get(['id', 'name', 'value']);

                        foreach ($options as $option) {
                            $allAvailableOptions[] = [
                                'name' => $option->name,
                                'value' => $option->value,
                            ];
                        }
                    }

                    if ($fieldType === 'checkbox') {
                        $optionIds = explode(',', $customFieldValue->custom_field_options_id);
                        $fieldValueArray = DB::table('custom_field_options')
                            ->whereIn('id', $optionIds)
                            ->pluck('name')
                            ->toArray();
                    } elseif ($fieldType === 'media') {
                        $decoded = json_decode($fieldValue, true);
                        $fieldValueArray = is_array($decoded)
                            ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                            : [];
                    } elseif ($fieldType === 'file') {
                        $fieldValueArray = $fieldValue ? url($fieldValue) : null;
                    } elseif ($fieldType === 'repeater') {
                        $nestedRows = DB::table('custom_field_repeater_values')
                            ->where('custom_field_repeater_id', $customFieldValue->custom_field_id)
                            ->where('project_listing_id', $property->id)
                            ->get()
                            ->groupBy('unique_id');

                        $fieldValueArray = [];

                        foreach ($nestedRows as $groupId => $rows) {
                            $groupData = [];

                            foreach ($rows as $row) {
                                $value = $row->field_meta_value;
                                $fieldTypeNested = $row->field_type;
                                $nestedOptions = [];

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

                                $groupData[] = [
                                    'sub_field_id' => $row->custom_field_id,
                                    'field_type' => $fieldTypeNested,
                                    'field_value' => $value,
                                    'options' => $nestedOptions,
                                ];
                            }

                            $fieldValueArray[] = $groupData;
                        }
                    } elseif (in_array($fieldType, ['select', 'radio'])) {
                        $option = DB::table('custom_field_options')
                            ->where('id', $customFieldValue->custom_field_options_id)
                            ->first();
                        $fieldValueArray = optional($option)->name;
                    } else {
                        $fieldValueArray = $fieldValue;
                    }

                    $fieldData = [
                        'custom_field_id' => $customField->id ?? null,
                        'field_label' => $customField->field_label ?? null,
                        'field_type' => $fieldType,
                        'placeholder' => $customField->field_placeholder ?? null,
                        'template_id' => $customField->template_id ?? null,
                        'template' => $customField->templateValue ?? null,
                        'field_value' => $fieldValueArray,
                        'options' => $allAvailableOptions,
                    ];

                    if ($fieldType === 'checkbox') {
                        $fieldData['checkbox_type'] = $customField->checkbox_type ?? null;
                    }

                    return $fieldData;
                });

                  //  Decode property_type_id safely

                $propertyTypeIds = is_array($property->property_type_id)
                    ? array_map('intval', $property->property_type_id)
                    : ((is_string($property->property_type_id) && ($decoded = json_decode($property->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($property->property_type_id)) ? [(int)$property->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($property->property_status_id)
                    ? array_map('intval', $property->property_status_id)
                    : ((is_string($property->property_status_id) && ($decoded = json_decode($property->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($property->property_status_id)) ? [(int)$property->property_status_id] : []));

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
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'country_id' => $property->country_id,
                    'country_name' => optional($property->country)->name,
                    'state_id' => $property->state_id,
                    'state_name' => optional($property->state)->name,
                    'city_id' => $property->city_id,
                    'city_name' => optional($property->city)->name,
                    'area_locality' => $property->area_locality,
                    'colony' => $property->colony,
                    'street_address' => $property->street_address,
                    'pin_code' => $property->pin_code,
                    'live_status' => $property->live_status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_type' => $propertyTypes,
                    'property_status' => $propertyStatuses,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // bulk
    // public function bulkDelete(Request $request)
    // {

    //     if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //         return response()->json(['error' => 'Please provide an API token.'], 422);
    //     }

    //     // Retrieve the Authorization header
    //     $authorizationHeader = $request->header('Authorization');

    //     // Check if the header starts with "Bearer "
    //     if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //         return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
    //     }

    //     // Extract the token by removing the "Bearer " prefix
    //     $requestToken = substr($authorizationHeader, 7);

    //     // Check if the token is empty after removing "Bearer "
    //     if (empty($requestToken)) {
    //         return response()->json(['error' => 'Token is missing.'], 422);
    //     }

    //     // Verify the token dynamically (e.g., check in the database)
    //     $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

    //     if (!$tokenExists) {
    //         return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
    //     }

    //     try {
    //         // Find the builder by ID
    //         $delete_ids = explode(',', $request->id);

    //         foreach ($delete_ids as $row) {
    //             $purpose = ProjectList::findOrFail($row);
    //             // Delete the builder record
    //             $purpose->customFieldValues()->delete();
    //             // Delete the property
    //             $purpose->delete();
    //         }

    //         // Return a success response
    //         return response()->json([
    //             'message' => 'Project bulk deleted successfully',

    //         ], 200);
    //     } catch (ModelNotFoundException $e) {
    //         // Handle model not found errors
    //         return response()->json(['error' => 'purpose not found'], 404);
    //     } catch (\Exception $e) {
    //         // Handle other unexpected errors
    //         return response()->json(['error' => 'Something went wrong'], 500);
    //     }
    // }

    public function bulkDelete(Request $request)
    {
        try {
            $user = Auth::user();
            $createdBy = $user->id;

            // Validate that 'id' is provided
            if (!$request->has('id')) {
                return response()->json([
                    'status' => false,
                    'message' => 'No project IDs provided.'
                ], 422);
            }

            // Convert comma-separated IDs to array
            $delete_ids = explode(',', $request->id);

            foreach ($delete_ids as $row) {
                $project = ProjectList::findOrFail($row);

                // Check access: Only admin or creator can delete
                if (
                    strtolower(optional($user->role)->name) !== 'admin' &&
                    $project->created_by !== $createdBy
                ) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized: You can only delete your own projects.',
                    ], 403);
                }

                // Delete related custom fields
                $project->customFieldValues()->delete();
                // Delete project
                $project->delete();
            }

            // Success response
            return response()->json([
                'status' => true,
                'message' => 'Project(s) deleted successfully.',
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'One or more projects not found.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An unexpected error occurred.',
                // 'error' => $e->getMessage() // Uncomment for debugging
            ], 500);
        }
    }



    public function updateTemporaryStatus(Request $request)
    {
        try {

            $user = Auth::user();

            // Validate the request
            $validatedData = $request->validate([
                'project_id' => 'required|exists:project_listings,id',
                'temporary_status' => 'required|string',  // Temporary status is required
            ]);

            // Fetch allowed enum values dynamically
            $enumValues = DB::select("SHOW COLUMNS FROM project_listings WHERE Field = 'temporary_status'");

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

            // Find the project and update the status
            $project = ProjectList::findOrFail($request->project_id);
            // permission check
            $isAdmin = isset($user->role->name) && $user->role->name === 'admin';
            $isOwner = $project->created_by == $user->id;

            if (!$isAdmin && !$isOwner) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only update your own projects.',
                ], 403);
            }



            $project->temporary_status = $request->temporary_status;
            $project->save();

            return response()->json([
                'status' => true,
                'message' => 'Temporary status updated successfully',
                'data' => $project
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile()
            ], 500);
        }
    }



    // project search by name , project_unique_id

    public function projectSearch(Request $request)
    {
        try {
            $user = Auth::user();
            $search = $request->input('search'); //  one search input
            $baseURL = config('app.url');
            $basePath = public_path();
            $perPage = $request->input('per_page', 10); // Default to 10 if not provided

            // Apply conditional search
            $projects = ProjectList::when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('project_unique_id', 'like', '%' . $search . '%');
                });
            })
                ->with([
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
                    'importKeywords',
                    'developer',
                    'createdBy.role',
                    'updatedBy.role'
                ])
                ->paginate($perPage);


            $isAdmin = isset($user->role->name) && $user->role->name === 'admin';
            if (!$isAdmin) {
                $projects = $projects->filter(function ($project) use ($user) {
                    return $project->created_by == $user->id;
                })->values(); // Reset keys
            }


            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $fieldValue = $customFieldValue->field_meta_value;

                    if ($customField && $customField->field_type === 'checkbox') {
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type === 'media') {
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(fn($file) => $baseURL . '/uploads/media/' . $file);
                    } elseif ($customField && $customField->field_type === 'file') {
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(fn($file) => $baseURL . '/' . $file);
                    } else {
                        $fieldValueArray = $fieldValue;
                    }

                    return [
                        'custom_field_id' => optional($customField)->id,
                        'field_type' => optional($customField)->field_type,
                        'field_value' => $fieldValueArray,
                        'field_name' => optional($customField)->field_label,
                    ];
                });

                  //  Decode property_type_id safely

                $propertyTypeIds = is_array($property->property_type_id)
                    ? array_map('intval', $property->property_type_id)
                    : ((is_string($property->property_type_id) && ($decoded = json_decode($property->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($property->property_type_id)) ? [(int)$property->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($property->property_status_id)
                    ? array_map('intval', $property->property_status_id)
                    : ((is_string($property->property_status_id) && ($decoded = json_decode($property->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($property->property_status_id)) ? [(int)$property->property_status_id] : []));

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


                $developer = $property->developer;
                $developerData = $developer ? [
                    'id' => $developer->id,
                    'fullname' => $developer->fullname,
                    'email' => $developer->email,
                    'phone' => $developer->phone,
                    'api_token' => $developer->api_token,
                    'unique_id' => $developer->unique_id,
                    'user_details' => optional($developer->userDetails)->toArray(),
                ] : null;

                return [
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'address' => $property->address,
                    'country' => $property->country,
                    'state' => $property->state,
                    'city' => $property->city,
                    'area_locality' => $property->area_locality,
                    'colony' => $property->colony,
                    'street_address' => $property->street_address,
                    'pin_code' => $property->pin_code,
                    'live_status' => $property->live_status,
                    'temporary_status' => $property->temporary_status,
                    'status_reason' => $property->status_reason,
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
                    'property_type' => $propertyTypes,
                    'property_status' => $propertyStatuses,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:i A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
                    'developer' => $developerData,
                    'keyword' => $property->importKeywords,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => $isAdmin
                    ? 'Admin: Showing all matched projects.'
                    : 'Showing only your own matched projects.',
                'data' => $projectsData,
                'meta' => [
                    'total' => $projects->total(),
                    'per_page' => $projects->perPage(),
                    'current_page' => $projects->currentPage(),
                    'last_page' => $projects->lastPage(),
                    'from' => $projects->firstItem(),
                    'to' => $projects->lastItem(),
                ],
                'links' => [
                    'first' => $projects->url(1),
                    'last' => $projects->url($projects->lastPage()),
                    'prev' => $projects->previousPageUrl(),
                    'next' => $projects->nextPageUrl(),
                ],

            ],200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
        }
    }


    ### no auth ###

    public function getdatabyIdNoAuth(Request $request)
    {
        try {
            if (!$request->id) {
                return response()->json(['error' => 'ID is required'], 400);
            }

            $baseURL = config('app.url');

            $projects = ProjectList::with([
                'user.userDetail',
                'createdBy.role',
                'updatedBy.role',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'customFieldValues.customField',
                'customFieldValues.customFieldOption',
                'importKeywords',
                'developer',
                'country',
                'state',
                'city',
            ])->where('live_Status', 'Approve')->where('id', $request->id)->first();

            if (!$projects) {
                return response()->json(['error' => 'Project not found'], 200);
            }

            $createdByData = $projects->createdBy ? [
                'id' => $projects->createdBy->id,
                'name' => $projects->createdBy->first_name,
                'email' => $projects->createdBy->email,
                'role' => optional($projects->createdBy->role)->name,
            ] : null;

            $updatedByData = $projects->updatedBy ? [
                'id' => $projects->updatedBy->id,
                'name' => $projects->updatedBy->first_name,
                'email' => $projects->updatedBy->email,
                'role' => optional($projects->updatedBy->role)->name,
            ] : null;

            if (!empty($projects->featured_image)) {
                $projects->featured_image = filter_var($projects->featured_image, FILTER_VALIDATE_URL)
                    ? $projects->featured_image
                    : url($projects->featured_image);
            }

            $repeaterFields = $projects->customFieldValues->map(function ($customFieldValue) use ($baseURL, $projects) {
                $customField = optional($customFieldValue->customField);
                $fieldType = $customField->field_type ?? 'unknown';
                $fieldValue = $customFieldValue->field_meta_value ?? '';
                $allAvailableOptions = [];

                // Get template info for top-level field
                $template = $customField && $customField->template_id
                    ? DB::table('custom_field_unique_codes')->where('id', $customField->template_id)->first()
                    : null;

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
                        ->where('project_listing_id', $projects->id)
                        ->get()
                        ->groupBy('unique_id');

                    $repeaterData = [];

                    foreach ($nestedRows as $groupId => $rows) {
                        $groupData = [];

                        foreach ($rows as $row) {
                            $value = $row->field_meta_value;
                            $fieldTypeNested = $row->field_type;
                            $nestedOptions = [];

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

                            // ✅ Add template for nested sub-field
                            $subField = DB::table('custom_fields')->where('id', $row->custom_field_id)->first();
                            $subTemplate = $subField && $subField->template_id
                                ? DB::table('custom_field_unique_codes')->where('id', $subField->template_id)->first()
                                : null;

                            $groupData[] = [
                                'sub_field_id' => $row->custom_field_id,
                                'field_label' => $subField->field_label ?? null,
                                'placeholder' => $subField->field_placeholder ?? null,
                                'field_type' => $fieldTypeNested,
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
                        'options' => [],
                        'template_id' => $customField->template_id ?? null,
                        'template' => $template,
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
                } elseif ($fieldType === 'media') {
                    $fieldValue = json_decode($fieldValue, true) ?? [];
                    if (!empty($fieldValue)) {
                        $fieldValue = array_map(fn($fileName) => $baseURL . '/uploads/media/' . $fileName, (array) $fieldValue);
                    }
                } elseif ($fieldType === 'file') {
                    $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                    $fieldValue = is_array($decoded)
                        ? array_map(fn($file) => url($file), $decoded)
                        : [];
                }

                $fieldArray = [
                    'custom_field_id' => $customFieldValue->custom_field_id,
                    'field_label' => $customField->field_label ?? 'Unknown Field',
                    'placeholder' => $customField->field_placeholder,
                    'field_type' => $fieldType,
                    'field_value' => $fieldValue,
                    'options' => $allAvailableOptions,
                    'template_id' => $customField->template_id ?? null,
                    'template' => $template,
                ];

                if ($fieldType === 'checkbox') {
                    $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
                }

                return $fieldArray;
            });

              //  Decode property_type_id safely

                $propertyTypeIds = is_array($projects->property_type_id)
                    ? array_map('intval', $projects->property_type_id)
                    : ((is_string($projects->property_type_id) && ($decoded = json_decode($projects->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($projects->property_type_id)) ? [(int)$projects->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($projects->property_status_id)
                    ? array_map('intval', $projects->property_status_id)
                    : ((is_string($projects->property_status_id) && ($decoded = json_decode($projects->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($projects->property_status_id)) ? [(int)$projects->property_status_id] : []));

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
                'id' => $projects->id,
                'project_unique_id' => $projects->project_unique_id,
                'name' => $projects->name,
                'description' => $projects->description,
                'address' => $projects->address,
                'country_id' => $projects->country_id,
                'country_name' => optional($projects->country)->name,
                'state_id' => $projects->state_id,
                'state_name' => optional($projects->state)->name,
                'city_id' => $projects->city_id,
                'city_name' => optional($projects->city)->name,
                'property_address' => $projects->property_address,
                'area_locality' => $projects->area_locality,
                'colony' => $projects->colony,
                'street_address' => $projects->street_address,
                'pin_code' => $projects->pin_code,
                'featured_image' => $projects->featured_image,
                'live_status' => $projects->live_status,
                'status_reason' => $projects->status_reason,
                'project_status' => $projects->project_status,
                'user_id' => $projects->user_id,
                    'user' => $projects->user_id ? [
                        'id' => $projects->user->id,
                        'name' => $projects->user->first_name,
                        'email' => $projects->user->email,
                        'role' => optional($projects->user->role)->name,
                    ] :null,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'listed_by' => optional(optional($projects->user)->role)->name,
                'purpose_id' => $projects->purpose_id,
                'purpose_id_name' => optional($projects->purpose)->name,
                'property_id' => $projects->property_id,
                'property_id_name' => optional($projects->property)->name,
                'property_type' => $propertyTypes,
                'property_status' => $propertyStatuses,
                'total_view' => $projects->analytics()->count(),
                'date' => $projects->created_at ? $projects->created_at->format('d m Y') : null,
                'time' => $projects->created_at ? $projects->created_at->format('h:i A') : null,
                'timestamp' => $projects->created_at ? $projects->created_at->format('d m Y h:i A') : null,

                'keyword' => $projects->importKeywords,
                'repeater_fields' => $repeaterFields,
                'developer_id' => $projects->developer_id,
                'developer' => $projects->developer ? [
                    'id' => $projects->developer->id,
                    'developer_unique_id' => $projects->developer->developer_unique_id,
                    'name' => $projects->developer->name,
                    'description' => $projects->developer->description,
                    'purpose_id' => $projects->developer->purpose_id,
                    'purpose_id_name' => optional($projects->developer->purpose)->name,
                    'property_id' => $projects->developer->property_id,
                    'property_id_name' => optional($projects->developer->property)->name,
                    'property_status_id' => $projects->developer->property_status_id,
                    'property_status_id_name' => optional($projects->developer->propertystatus)->name,
                    'property_type_id' => $projects->developer->property_type_id,
                    'property_type_id_name' => optional($projects->developer->propertyType)->name,
                    'country_id' => $projects->developer->country_id,
                    'country_name' => optional($projects->developer->country)->name,
                    'state_id' => $projects->developer->state_id,
                    'state_name' => optional($projects->developer->state)->name,
                    'city_id' => $projects->developer->city_id,
                    'city_name' => optional($projects->developer->city)->name,
                    'address' => $projects->developer->address,
                    'area_locality' => $projects->developer->area_locality,
                    'colony' => $projects->developer->colony,
                    'street_address' => $projects->developer->street_address,
                    'pin_code' => $projects->developer->pin_code,
                    'featured_image' => $projects->developer->featured_image
                        ? url($projects->developer->featured_image)
                        : null,
                    'live_status' => $projects->developer->live_status,
                    'temporary_status' => $projects->developer->temporary_status,
                    'status_reason' => $projects->developer->status_reason,
                    'user_id' => $projects->developer->user_id,
                    'user' => $projects->developer->user_id ? [
                        'id' => $projects->developer->user->id,
                        'name' => $projects->developer->user->first_name,
                        'email' => $projects->developer->user->email,
                        'role' => optional($projects->developer->user->role)->name,
                    ] :null,
                    'created_by' => $projects->developer->createdBy ? [
                        'id' => $projects->developer->createdBy->id,
                        'name' => $projects->developer->createdBy->first_name,
                        'email' => $projects->developer->createdBy->email,
                        'role' => optional($projects->developer->createdBy->role)->name,
                    ] : null,
                    'updated_by' => $projects->developer->updatedBy ? [
                        'id' => $projects->developer->updatedBy->id,
                        'name' => $projects->developer->updatedBy->first_name,
                        'email' => $projects->developer->updatedBy->email,
                        'role' => optional($projects->developer->updatedBy->role)->name,
                    ] : null,
                    'keyword' => $projects->developer->importKeywords ?? [],

                ] : null,

            ]);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // public function getProjectsByUserId(Request $request, $userId)
    // {
    //     try {
    //         // Property Owner check (exclude admin)
    //         $propertyOwner = User::where('id', $userId)
    //             ->whereHas('role', function ($q) {
    //                 $q->where('name', '!=', 'admin');
    //             })
    //             ->first();

    //         if (!$propertyOwner) {
    //             return response()->json(['error' => 'User not found or is admin.'], 200);
    //         }

    //         // Base query (without user relations)
    //         $query = ProjectList::with([
    //             'purpose',
    //             'propertyType',
    //             'propertystatus',
    //             'property',
    //             'developer',
    //             'country',
    //             'state',
    //             'city',
    //         ])
    //             ->where('live_status', 'Approve')
    //             ->where(function ($q) use ($userId) {
    //                 $q->where('created_by', $userId)
    //                     ->orWhere('updated_by', $userId);
    //             });

    //         // Purpose filter
    //         if ($request->filled('purpose_id')) {
    //             $query->where('purpose_id', $request->purpose_id);
    //         }

    //         // Pagination
    //         $perPage = $request->get('per_page', 10);
    //         $projects = $query->paginate($perPage);

    //         // Purpose-wise count
    //         $purposeCounts = DB::table('project_listings as p')
    //             ->join('purposes as pu', 'p.purpose_id', '=', 'pu.id')
    //             ->select('p.purpose_id', 'pu.name as purpose_name', DB::raw('COUNT(*) as total'))
    //             ->where(function ($q) use ($userId) {
    //                 $q->where('p.created_by', $userId)
    //                     ->orWhere('p.updated_by', $userId);
    //             })
    //             ->groupBy('p.purpose_id', 'pu.name')
    //             ->get();

    //         // Format properties data (without user info)
    //         $formattedProjects = $projects->getCollection()->map(function ($project) {
    //             $featuredImage = !empty($project->featured_image)
    //                 ? (filter_var($project->featured_image, FILTER_VALIDATE_URL)
    //                     ? $project->featured_image
    //                     : url(ltrim($project->featured_image, '/')))
    //                 : null;

    //             $propertyTypeNames = null;
    //             if (!empty($property->property_type_id)) {
    //                 $ids = explode(',', $project->property_type_id);
    //                 $propertyTypeNames = PropertyType::whereIn('id', $ids)
    //                     ->pluck('name')
    //                     ->toArray();
    //             }

    //             return [
    //                 'id' => $project->id,
    //                 'name' => $project->name ?? null,
    //                 'description' => $project->description ?? null,
    //                 'purpose_id' => $project->purpose_id ?? null,
    //                 'purpose_name' => $project->purpose->name ?? null,
    //                 'featured_image' => $featuredImage,
    //                 'country_id' => $project->country_id ?? null,
    //                 'state_id' => $project->state_id ?? null,
    //                 'city_id' => $project->city_id ?? null,
    //                 'country_name' => $project->country->name ?? null,
    //                 'state_name' => $project->state->name ?? null,
    //                 'city_name' => $project->city->name ?? null,
    //                 'area_locality' => $project->area_locality ?? null,
    //                 'colony' => $project->colony ?? null,
    //                 'street_address' => $project->street_address ?? null,
    //                 'pin_code' => $project->pin_code ?? null,
    //                 'property_type_id' => $project->property_type_id ?? null,
    //                 'property_type_name' => $propertyTypeNames ? implode(', ', $propertyTypeNames) : null,
    //                 'property_status_id' => $project->property_status_id ?? null,
    //                 'property_status_name' => $project->propertystatus->name ?? null,
    //                 'developer_id' => $project->project_id ?? null,
    //                 'developer_name' => $project->developer->name ?? null,
    //                 'property_id' => $project->property_id ?? null,
    //                 'property_name' => $project->property->name ?? null,
    //                 'created_by' => $project->created_by ?? null,
    //                 'updated_by' => $project->updated_by ?? null,
    //                 'created_at' => $project->created_at ?? null,
    //                 'updated_at' => $project->updated_at ?? null,
    //             ];
    //         });

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Projects retrieved successfully.',
    //             'data' => [
    //                 'properties' => $formattedProjects,
    //                 'pagination' => [
    //                     'total' => $projects->total(),
    //                     'per_page' => $projects->perPage(),
    //                     'current_page' => $projects->currentPage(),
    //                     'last_page' => $projects->lastPage(),
    //                 ],
    //                 'purpose_counts' => $purposeCounts
    //             ]
    //         ], 200);

    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    public function getProjectsByUserId(Request $request, $userId)
    {
        try {
            // Property Owner check (exclude admin)
            $propertyOwner = User::where('id', $userId)
                ->whereHas('role', function ($q) {
                    $q->where('name', '!=', 'admin');
                })
                ->first();

            if (!$propertyOwner) {
                return response()->json(['error' => 'User not found or is admin.'], 200);
            }

            $baseURL = config('app.url');

            // Base query with relations + custom fields
            $query = ProjectList::with([
                'purpose',
                'propertyType',
                'propertystatus',
                'property',
                'developer',
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
            $projects = $query->paginate($perPage);

            // Purpose-wise count
            $purposeCounts = DB::table('project_listings as p')
                ->join('purposes as pu', 'p.purpose_id', '=', 'pu.id')
                ->select('p.purpose_id', 'pu.name as purpose_name', DB::raw('COUNT(*) as total'))
                ->where(function ($q) use ($userId) {
                    $q->where('p.created_by', $userId)
                        ->orWhere('p.updated_by', $userId);
                })
                ->groupBy('p.purpose_id', 'pu.name')
                ->get();

            // Format projects data (same style as q1)
            $formattedProjects = $projects->getCollection()->map(function ($project) use ($baseURL) {
                $featuredImage = !empty($project->featured_image)
                    ? (filter_var($project->featured_image, FILTER_VALIDATE_URL)
                        ? $project->featured_image
                        : url(ltrim($project->featured_image, '/')))
                    : null;

                $propertyTypeNames = null;
                if (!empty($project->property_type_id)) {
                    $ids = explode(',', $project->property_type_id);
                    $propertyTypeNames = PropertyType::whereIn('id', $ids)
                        ->pluck('name')
                        ->toArray();
                }

                // ✅ Custom fields (copied from q1)
                $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL, $project) {
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
                            ->where('project_listing_id', $project->id) // ✅ for project
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

                $propertyTypeIds = is_array($project->property_type_id)
                    ? array_map('intval', $project->property_type_id)
                    : ((is_string($project->property_type_id) && ($decoded = json_decode($project->property_type_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_type_id)) ? [(int)$project->property_type_id] : []));

                //  Decode property_status_id safely
                $propertyStatusIds = is_array($project->property_status_id)
                    ? array_map('intval', $project->property_status_id)
                    : ((is_string($project->property_status_id) && ($decoded = json_decode($project->property_status_id, true)) && json_last_error() === JSON_ERROR_NONE)
                        ? array_map('intval', $decoded)
                        : ((is_numeric($project->property_status_id)) ? [(int)$project->property_status_id] : []));

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
                    'id' => $project->id,
                    'name' => $project->name ?? null,
                    'description' => $project->description ?? null,
                    'purpose_id' => $project->purpose_id ?? null,
                    'purpose_name' => $project->purpose->name ?? null,
                    'featured_image' => $featuredImage,
                    'country_id' => $project->country_id ?? null,
                    'state_id' => $project->state_id ?? null,
                    'city_id' => $project->city_id ?? null,
                    'country_name' => $project->country->name ?? null,
                    'state_name' => $project->state->name ?? null,
                    'city_name' => $project->city->name ?? null,
                    'area_locality' => $project->area_locality ?? null,
                    'colony' => $project->colony ?? null,
                    'street_address' => $project->street_address ?? null,
                    'pin_code' => $project->pin_code ?? null,
                    'property_type' => $propertyTypes,
                    'property_status' => $propertyStatuses,
                    'developer_id' => $project->developer_id ?? null,
                    'developer_name' => $project->developer->name ?? null,
                    'property_id' => $project->property_id ?? null,
                    'property_name' => $project->property->name ?? null,
                    'created_by' => $project->created_by ?? null,
                    'updated_by' => $project->updated_by ?? null,
                    'created_at' => $project->created_at ?? null,
                    'updated_at' => $project->updated_at ?? null,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Projects retrieved successfully.',
                'data' => [
                    'projects' => $formattedProjects,
                    'pagination' => [
                        'total' => $projects->total(),
                        'per_page' => $projects->perPage(),
                        'current_page' => $projects->currentPage(),
                        'last_page' => $projects->lastPage(),
                    ],
                    'purpose_counts' => $purposeCounts
                ]
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }





    // public function getRelatedProjectsByProjectId(Request $request, $projectId)
    // {
    //     try {
    //         // Reference project find
    //         $referenceProject = ProjectList::find($projectId);

    //         if (!$referenceProject) {
    //             return response()->json(['status' => false, 'message' => 'Reference project not found.'], 200);
    //         }

    //         // Viewer for masking logic
    //         $viewer = auth('sanctum')->user() ?? User::where('api_token', $request->bearerToken())->first();

    //         // Convert reference property's property_type_id to an array
    //         $referencePropertyTypes = explode(',', $referenceProject->property_type_id);

    //         // Query build
    //         $query = ProjectList::with([
    //             'purpose',
    //             'propertyType',
    //             'propertystatus',
    //             'property',
    //             'developer',
    //             'user.role:id,name',
    //             'user.country:id,name as country_name',
    //             'user.state:id,name as state_name',
    //             'user.city:id,name as city_name',
    //             'user.userDetails',
    //             'country',
    //             'state',
    //             'city',
    //         ])
    //             ->where('id', '!=', $referenceProject->id) // same property skip
    //             ->where('purpose_id', $referenceProject->purpose_id)
    //             ->where('property_id', $referenceProject->property_id)
    //             ->where(function ($q) use ($referencePropertyTypes) {
    //                 // Match any of the property types in the reference property
    //                 foreach ($referencePropertyTypes as $type) {
    //                     $q->orWhere('property_type_id', 'like', "%{$type}%");
    //                 }
    //             })
    //             ->where('area_locality', 'like', '%' . $referenceProject->area_locality . '%');

    //         $perPage = $request->get('per_page', 10);
    //         $projects = $query->paginate($perPage);

    //         $formattedProjects = $projects->getCollection()->map(function ($project) use ($viewer) {
    //             $featuredImage = !empty($project->featured_image)
    //                 ? (filter_var($project->featured_image, FILTER_VALIDATE_URL)
    //                     ? $project->featured_image
    //                     : url(ltrim($project->featured_image, '/')))
    //                 : null;

    //             // Convert "1,2" IDs to names
    //             $propertyTypeNames = null;
    //             if (!empty($project->property_type_id)) {
    //                 $ids = explode(',', $project->property_type_id);
    //                 $propertyTypeNames = PropertyType::whereIn('id', $ids)
    //                     ->pluck('name')
    //                     ->toArray();
    //             }

    //             $user = $project->user;

    //             return [
    //                 'id' => $project->id,
    //                 'name' => $project->name ?? null,
    //                 'purpose_id' => $project->purpose_id ?? null,
    //                 'purpose_name' => $project->purpose->name ?? null,
    //                 'featured_image' => $featuredImage,
    //                 'property_type_name' => $propertyTypeNames ? implode(', ', $propertyTypeNames) : null,

    //             ];
    //         });

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Related projects retrieved successfully.',
    //             'data' => [
    //                 'properties' => $formattedProjects,
    //                 'pagination' => [
    //                     'total' => $projects->total(),
    //                     'per_page' => $projects->perPage(),
    //                     'current_page' => $projects->currentPage(),
    //                     'last_page' => $projects->lastPage(),
    //                 ],
    //             ]
    //         ], 200);

    //     } catch (\Throwable $th) {
    //         return response()->json(['status' => false, 'error' => $th->getMessage()], 500);
    //     }
    // }


    public function getRelatedProjectsByProjectId(Request $request, $projectId)
    {
        try {
            // Reference project find
            $referenceProject = ProjectList::find($projectId);

            if (!$referenceProject) {
                return response()->json(['status' => false, 'message' => 'Reference project not found.'], 200);
            }

            // Viewer for masking logic
            $viewer = auth('sanctum')->user() ?? User::where('api_token', $request->bearerToken())->first();

            // Convert reference property's property_type_id to an array
            $referencePropertyTypes = explode(',', $referenceProject->property_type_id);

            $baseURL = config('app.url');

            // Query build
            $query = ProjectList::with([
                'purpose',
                'propertyType',
                'propertystatus',
                'property',
                'developer',
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
                ->where('id', '!=', $referenceProject->id) // same property skip
                ->where('purpose_id', $referenceProject->purpose_id)
                ->where('property_id', $referenceProject->property_id)
                ->where(function ($q) use ($referencePropertyTypes) {
                    foreach ($referencePropertyTypes as $type) {
                        $q->orWhere('property_type_id', 'like', "%{$type}%");
                    }
                })
                ->where('area_locality', 'like', '%' . $referenceProject->area_locality . '%');

            $perPage = $request->get('per_page', 10);
            $projects = $query->paginate($perPage);

            $formattedProjects = $projects->getCollection()->map(function ($project) use ($viewer, $baseURL) {
                $featuredImage = !empty($project->featured_image)
                    ? (filter_var($project->featured_image, FILTER_VALIDATE_URL)
                        ? $project->featured_image
                        : url(ltrim($project->featured_image, '/')))
                    : null;

                $propertyTypeNames = null;
                if (!empty($project->property_type_id)) {
                    $ids = explode(',', $project->property_type_id);
                    $propertyTypeNames = PropertyType::whereIn('id', $ids)
                        ->pluck('name')
                        ->toArray();
                }

                // ✅ Custom fields (same logic as q1/q2)
                $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL, $project) {
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
                            ->where('project_listing_id', $project->id) // ✅ for project
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
                    'id' => $project->id,
                    'name' => $project->name ?? null,
                    'purpose_id' => $project->purpose_id ?? null,
                    'purpose_name' => $project->purpose->name ?? null,
                    'featured_image' => $featuredImage,
                    'property_type_name' => $propertyTypeNames ? implode(', ', $propertyTypeNames) : null,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Related projects retrieved successfully.',
                'data' => [
                    'properties' => $formattedProjects,
                    'pagination' => [
                        'total' => $projects->total(),
                        'per_page' => $projects->perPage(),
                        'current_page' => $projects->currentPage(),
                        'last_page' => $projects->lastPage(),
                    ],
                ]
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'error' => $th->getMessage()], 500);
        }
    }











}
