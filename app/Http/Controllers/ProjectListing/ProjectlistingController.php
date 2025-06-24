<?php

namespace App\Http\Controllers\ProjectListing;

use App\Http\Controllers\Controller;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Log;

class ProjectlistingController extends Controller
{


    // this is for store the data
    public function store(Request $request)
    {
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
                'property_status_id' => 'nullable',
                'property_type_id' => 'nullable',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'developer_id' => 'nullable|exists:developer_listings,id',
                'live_status' => 'in:Approve,Disapprove,Reject,Under Review,Modify Review',
                'temporary_status' => 'nullable|in:active,deactive',
                'country_id' => 'nullable|exists:countries,id',
                'state_id' => 'nullable|exists:states,id',
                'city_id' => 'nullable|exists:cities,id',
                'status_reason' => $request->live_status === 'Reject' ? 'required|string|max:500' : 'nullable',
            ]);

            // Set status_reason to null if live_status is not "Reject"
            $validatedData['status_reason'] = $request->live_status === 'Reject' ? $request->status_reason : null;

            // Generate unique project ID
            $project_unique_id = 'Project' . rand(111111, 999999);

            // Set temporary_status: "active" by default if not provided
            $validatedData['temporary_status'] = $validatedData['temporary_status'] ?? 'active';

            // Prepare project data
            $projectData = array_merge($validatedData, [
                'project_unique_id' => $project_unique_id,
                'user_id' => $userId,
                'created_by' => $userId, // Store the authenticated user's ID
                'address' => $request->address,
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
                            // ✅ Fix: Handle single file uploads correctly
                            if ($request->hasFile("repeater_fields.$repeaterFieldIndex.field_value")) {
                                $file = $request->file("repeater_fields.$repeaterFieldIndex.field_value");

                                if ($file->isValid() && $file->getClientOriginalExtension() === 'pdf') {
                                    $uniqueFileName = time() . '_' . $file->getClientOriginalName();
                                    $filePath = $file->storeAs('uploads/gallery', $uniqueFileName);
                                    $customFieldData['field_meta_value'] = $filePath;
                                } else {
                                    return response()->json(['error' => 'Invalid file format. Only PDF files are allowed.'], 400);
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


    // this is for listing
    public function index(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Fetch only projects where live_status is "Approve"
            $projects = ProjectList::with([
                'user',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'customFieldValues.customField',
                'customFieldValues.customFieldOption',
                'importKeywords',
                'developer.userDetails',
                'country',
                'state',
                'city'
            ])->where('live_status', 'Approve')->get();

            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
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
                        'custom_field_id' => optional($customField)->id,
                        'field_type' => optional($customField)->field_type,
                        'field_value' => $fieldValueArray,
                        'field_name' => optional($customField)->field_name,
                    ];
                });

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
                    'live_status' => $property->live_status, // Updated from status
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'created_by' => $property->created_by,
                    'updated_by' => $property->updated_by,
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
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'developer' => $developerData,
                    'keyword' => $property->importKeywords,
                    'custom_field_values' => $formattedCustomFieldValues,
                    'country' => $property->country,
                    'state' => $property->state,
                    'city' => $property->city
                ];
            });

            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()], 500);
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
            $developer = ProjectList::where('created_by', $user->id)
                ->orWhere('updated_by', $user->id)
                ->get();

            // If no properties found, return empty array
            if ($developer->isEmpty()) {
                return response()->json(['message' => 'No Developer found.'], 200);
            }

            return response()->json([
                'status' => true,
                'message' => 'Developer retrieved successfully.',
                'data' => $developer
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
                'developer.userDetails',
                'createdBy.role',
                'updatedBy.role',
                // 'address' // Added role relationships
            ])
                ->get(); // No live_status filter

            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
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
                        'field_name' => optional($customField)->field_name,
                    ];
                });

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
                    'live_status' => $property->live_status,
                    'temporary_status' => $property->temporary_status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'created_by' => $property->created_by,
                    'created_by_role' => optional($property->createdBy)->role->name ?? 'N/A', // Role name or 'N/A'
                    'updated_by' => $property->updated_by,
                    'updated_by_role' => optional($property->updatedBy)->role->name ?? 'N/A', // Role name or 'N/A'
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
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'developer' => $developerData,
                    'keyword' => $property->importKeywords,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            return response()->json($projectsData);

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
                'property_status_id' => 'nullable',
                'property_type_id' => 'nullable',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'developer_id' => 'nullable|exists:developer_listings,id',
                'live_status' => 'required|in:Approve,Disapprove,Reject,Under Review,Modify Review',
                'temporary_status' => 'nullable|in:active,deactive',
                'country_id' => 'nullable|exists:countries,id',
                'state_id' => 'nullable|exists:states,id',
                'city_id' => 'nullable|exists:cities,id',
                'status_reason' => $request->live_status === 'Reject' ? 'required|string|max:500' : 'nullable',
            ]);

            // Set status_reason to null if live_status is not "Reject"
            $validatedData['status_reason'] = $request->live_status === 'Reject' ? $request->status_reason : null;

            // Find the project
            $project = ProjectList::findOrFail($request->id);

            // Set `temporary_status`: Default to "active" unless provided
            $temporaryStatus = $request->has('temporary_status') ? $request->temporary_status : 'active';

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

            // Handle repeater field values
            if ($request->has('repeater_fields')) {
                Customfieldvalue::where('project_listing_id', $project->id)->delete();
                foreach ($request->repeater_fields as $repeaterField) {
                    $customFieldData = [
                        'project_listing_id' => $project->id,
                        'custom_field_id' => $repeaterField['custom_field_id'],
                        // 'updated_by' => $userId, // Store updated_by
                    ];

                    // Handle field types
                    switch ($repeaterField['field_type']) {
                        case 'text':
                        case 'textarea':
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
                            $mediaFiles = [];
                            if (is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $value) {
                                    if (is_string($value)) {
                                        $mediaFiles[] = $value;
                                    } else if ($value->isValid()) {
                                        $fileName = time() . '_' . $value->getClientOriginalName();
                                        $value->move(public_path('uploads/media'), $fileName);
                                        $mediaFiles[] = $fileName;
                                    }
                                }
                            }
                            $customFieldData['field_meta_value'] = json_encode($mediaFiles);
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
            $id = $request->id;
            $project = ProjectList::find($id);

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

            return response()->json($returnRes, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for get data by id
    public function getdatabyId(Request $request)
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
                'developer.userDetails',
                'country',
                'state',
                'city',
                // 'address',
            ])->where('id', $request->id)->first(); // Fetch only one record

            if (!$projects) {
                return response()->json(['error' => 'Project not found'], 404);
            }

            // ✅ Handle Created By and Updated By
            $createdByData = $projects->createdBy ? [
                'id' => $projects->createdBy->id,
                'name' => $projects->createdBy->name,
                'email' => $projects->createdBy->email,
                'role' => optional($projects->createdBy->role)->name,
            ] : null;

            $updatedByData = $projects->updatedBy ? [
                'id' => $projects->updatedBy->id,
                'name' => $projects->updatedBy->name,
                'email' => $projects->updatedBy->email,
                'role' => optional($projects->updatedBy->role)->name,
            ] : null;

            // ✅ Fetch repeater fields dynamically
            $repeaterFields = $projects->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                $customField = optional($customFieldValue->customField);
                $fieldType = $customField->field_type ?? 'unknown';
                $fieldValue = $customFieldValue->field_meta_value ?? '';
                $allAvailableOptions = [];

                // ✅ Fetch all available options dynamically
                $availableOptions = DB::table('custom_field_options')
                    ->where('custom_field_id', $customFieldValue->custom_field_id)
                    ->where('status', 1) // Only fetch active options
                    ->get(['id', 'name', 'value']);
                // dd( $availableOptions);
                if ($availableOptions->isNotEmpty()) {
                    foreach ($availableOptions as $option) {
                        $allAvailableOptions[] = [
                            // 'id' => $option->id,
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
                'live_status' => $projects->live_status,
                'status_reason' => $projects->status_reason,
                'project_status' => $projects->project_status,
                'user_id' => $projects->user_id,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'listed_by' => optional(optional($projects->user)->role)->name,
                'purpose_id' => $projects->purpose_id,
                'purpose_id_name' => optional($projects->purpose)->name,
                'property_id' => $projects->property_id,
                'property_id_name' => optional($projects->property)->name,
                'property_status_id' => $projects->property_status_id,
                'property_status_id_name' => optional($projects->propertystatus)->name,
                'property_type_id' => $projects->property_type_id,
                'property_type_id_name' => optional($projects->propertyType)->name,
                'total_view' => $projects->analytics()->count(),
                'date' => $projects->created_at ? $projects->created_at->format('d m Y') : null,
                'time' => $projects->created_at ? $projects->created_at->format('h:i A') : null,
                'timestamp' => $projects->created_at ? $projects->created_at->format('d m Y h:i A') : null,
                'developer' => null,
                'keyword' => $projects->importKeywords->pluck('id')->toArray() ?? [],
                'repeater_fields' => $repeaterFields, // ✅ Added repeater fields dynamically
            ]);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for update property status
    public function updateProjectStatus(Request $request)
    {

        try {
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
        $projectData = ProjectList::where('id', $request->project_id)->first();


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
    public function getProjectByUserId(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            $projects = ProjectList::with(['country', 'state', 'city', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])->where('user_id', $request->user_id)->get();

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
                    'country_id' => $property->country_id,
                    'country_name' => optional($property->country)->name,
                    'state_id' => $property->state_id,
                    'state_name' => optional($property->state)->name,
                    'city_id' => $property->city_id,
                    'city_name' => optional($property->city)->name,
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
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $projectData;
            });

            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // bulk
    public function bulkDelete(Request $request)
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
            // Find the builder by ID
            $delete_ids = explode(',', $request->id);

            foreach ($delete_ids as $row) {
                $purpose = ProjectList::findOrFail($row);
                // Delete the builder record
                $purpose->customFieldValues()->delete();
                // Delete the property
                $purpose->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Project bulk deleted successfully',

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
