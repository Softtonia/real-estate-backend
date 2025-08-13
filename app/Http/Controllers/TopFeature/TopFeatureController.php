<?php

namespace App\Http\Controllers\TopFeature;

use App\Http\Controllers\Controller;
use App\Models\Developerlist;
use App\Models\ProjectList;
use App\Models\PropertyList;
use App\Models\TopFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopFeatureController extends Controller
{

    #### ## GET ALL TOP FEATURES ######
    public function index()
    {
        $topFeatures = TopFeature::all();
        return response()->json([
            'message' => 'Top features fetched successfully',
            'status' => 'success',
            'data' => $topFeatures
        ]);
    }










    #### Get Top Features by Project_id, Property_id, or Developer_id ####
    public function getTopFeaturesById(Request $request)
    {
        // Accept exactly one ID
        $projectId = $request->query('project_id');
        $propertyId = $request->query('property_id');
        $developerId = $request->query('developer_id');

        $ids = array_filter([
            'project_id' => $projectId,
            'property_id' => $propertyId,
            'developer_id' => $developerId,
        ]);

        if (count($ids) !== 1) {
            return response()->json([
                'message' => 'Exactly one of project_id, property_id, or developer_id must be provided.',
            ], 422);
        }

        $column = array_key_first($ids);
        $value = $ids[$column];

        // Fetch the model by checking which column was passed
        $model = null;

        switch ($column) {
            case 'project_id':
                $model = ProjectList::find($value);
                break;
            case 'property_id':
                $model = PropertyList::find($value);
                break;
            case 'developer_id':
                $model = Developerlist::find($value);
                break;
        }

        if (!$model) {
            return response()->json([
                'message' => 'Record not found for ' . $column,
            ], 200);
        }

        // Load related top feature via foreign key
        $topFeature = TopFeature::find($model->top_featured_id);

        if (!$topFeature) {
            return response()->json([
                'message' => 'Top feature not assigned to this record.',
            ], 200);
        }

        return response()->json([
            'message' => 'Top feature fetched successfully.',
            'data' => $topFeature
        ], 200);
    }


    #### Create and Update Top Feature ####
    public function createOrUpdateTopFeature(Request $request, $id = null)
    {
        DB::beginTransaction();

        try {
            $allowedFeaturedTypes = [
                'home_page',
                'single_user_details',
                'single_property_details',
                'single_project_details',
                'signle_developer_details',
                'search_project_result',
                'search_property_result',
                'search_developer_result',
                'search_user_detials',
            ];

            $validated = $request->validate([
                'featured_type' => 'nullable|array',
                'featured_type.*' => 'in:' . implode(',', $allowedFeaturedTypes),
                'status' => 'required|in:1,0',
                'project_id' => 'nullable|integer|exists:project_listings,id',
                'property_id' => 'nullable|integer|exists:properties_listing,id',
                'developer_id' => 'nullable|integer|exists:developer_listings,id',
            ]);

            // Check that only one ID is passed
            $assignments = array_filter([
                'project_id' => $request->project_id,
                'property_id' => $request->property_id,
                'developer_id' => $request->developer_id,
            ]);

            if (count($assignments) > 1) {
                return response()->json([
                    'message' => 'Only one of project_id, property_id, or developer_id is allowed.',
                    'errors' => [
                        'assignment' => ['Only one of project_id, property_id, or developer_id is allowed.']
                    ]
                ], 422);
            }

            // CREATE or UPDATE
            if ($id) {
                $topFeature = TopFeature::find($id);
                if (!$topFeature) {
                    return response()->json([
                        'message' => 'Top feature not found.'
                    ], 200);
                }

                $topFeature->update([
                    'featured_type' => $request->featured_type,
                    'status' => $request->status,
                ]);
            } else {
                $topFeature = TopFeature::create([
                    'featured_type' => $request->featured_type,
                    'status' => $request->status,
                ]);
            }

            // Re-assign top_featured_id in related table
            if (isset($assignments['project_id'])) {
                ProjectList::where('top_featured_id', $topFeature->id)->update(['top_featured_id' => null]);
                ProjectList::where('id', $assignments['project_id'])->update(['top_featured_id' => $topFeature->id]);
            } elseif (isset($assignments['property_id'])) {
                PropertyList::where('top_featured_id', $topFeature->id)->update(['top_featured_id' => null]);
                PropertyList::where('id', $assignments['property_id'])->update(['top_featured_id' => $topFeature->id]);
            } elseif (isset($assignments['developer_id'])) {
                Developerlist::where('top_featured_id', $topFeature->id)->update(['top_featured_id' => null]);
                Developerlist::where('id', $assignments['developer_id'])->update(['top_featured_id' => $topFeature->id]);
            }

            DB::commit();

            return response()->json([
                'message' => $id ? 'Top feature updated successfully.' : 'Top feature created successfully.',
                'data' => $topFeature
            ], $id ? 200 : 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to ' . ($id ? 'update' : 'create') . ' top feature',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function getDevelopersByFeaturedType(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Multiple featured types from request
            $featuredTypes = (array) $request->get('featured_type', []);

            if (empty($featuredTypes)) {
                return response()->json(['error' => 'featured_type parameter is required'], 422);
            }

            // Get all top_feature IDs that have any of the requested featured types
            $topFeatureIds = DB::table('top_features')
                ->where(function ($q) use ($featuredTypes) {
                    foreach ($featuredTypes as $type) {
                        $q->orWhereJsonContains('featured_type', $type);
                    }
                })
                ->pluck('id');

            if ($topFeatureIds->isEmpty()) {
                return response()->json(['error' => 'No matching featured_type found'], 200);
            }

            // Fetch developers with matching top_feature_id
            $developers = Developerlist::where('live_status', 'Approve')
                ->whereIn('top_featured_id', $topFeatureIds)
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
                ])
                ->paginate($request->get('per_page', 10));


            // Same mapping logic as before
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


    public function getPropertiesByFeaturedType(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Multiple featured types from request
            $featuredTypes = (array) $request->get('featured_type', []);

            if (empty($featuredTypes)) {
                return response()->json(['error' => 'featured_type parameter is required'], 422);
            }

            // Get all top_feature IDs that have any of the requested featured types
            $topFeatureIds = DB::table('top_features')
                ->where(function ($q) use ($featuredTypes) {
                    foreach ($featuredTypes as $type) {
                        $q->orWhereJsonContains('featured_type', $type);
                    }
                })
                ->pluck('id');

            if ($topFeatureIds->isEmpty()) {
                return response()->json(['error' => 'No matching featured_type found'], 200);
            }

            // Fetch only properties where live_status is "Approve"
            $properties = PropertyList::where('live_status', 'Approve')
                ->whereIn('top_featured_id', $topFeatureIds)->with([
                        'country',
                        'state',
                        'city',
                        'user.role',
                        'propertyType',
                        'purpose',
                        'property',
                        'propertystatus',
                        'project',
                        'customFieldValues.customField.templateValue',
                        'customFieldValues.customFieldOption',
                        'importKeywords'
                    ])
                ->paginate($request->get('per_page', 10));

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL, $property) {
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
                            ->where('properties_listing_id', $property->id)
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
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'country' => $property->country,
                    'state' => $property->state,
                    'city' => $property->city,
                    'area_locality' => $property->area_locality,
                    'colony' => $property->colony,
                    'street_address' => $property->street_address,
                    'pin_code' => $property->pin_code,
                    'property_address' => $property->property_address,
                    'live_status' => $property->live_status,
                    'temporary_status' => $property->temporary_status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'created_by' => $property->created_by,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    // 'featured_image' => $property->featured_image
                    //     ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image')
                    //     : null,
                    'featured_image' => !empty($property->featured_image)
                        ? (filter_var($property->featured_image, FILTER_VALIDATE_URL)
                            ? $property->featured_image // ✅ If it's already a full URL, use as is
                            : $baseURL . $property->featured_image) // ✅ Convert relative path to full URL
                        : null,
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
                    'time' => date('h:i A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
                    'keyword' => $property->importKeywords,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            // return response()->json($propertiesData);
            return response()->json([
                'data' => $propertiesData,
                'meta' => [
                    'current_page' => $properties->currentPage(),
                    'from' => $properties->firstItem(),
                    'last_page' => $properties->lastPage(),
                    'path' => $request->url(),
                    'per_page' => $properties->perPage(),
                    'to' => $properties->lastItem(),
                    'total' => $properties->total(),
                ],
                'links' => [
                    'first' => $properties->url(1),
                    'last' => $properties->url($properties->lastPage()),
                    'prev' => $properties->previousPageUrl(),
                    'next' => $properties->nextPageUrl(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function getProjectsByFeaturedType(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Multiple featured types from request
            $featuredTypes = (array) $request->get('featured_type', []);

            if (empty($featuredTypes)) {
                return response()->json(['error' => 'featured_type parameter is required'], 422);
            }

            // Get all top_feature IDs that have any of the requested featured types
            $topFeatureIds = DB::table('top_features')
                ->where(function ($q) use ($featuredTypes) {
                    foreach ($featuredTypes as $type) {
                        $q->orWhereJsonContains('featured_type', $type);
                    }
                })
                ->pluck('id');

            if ($topFeatureIds->isEmpty()) {
                return response()->json(['error' => 'No matching featured_type found'], 200);
            }

            $projects = ProjectList::where('live_status', 'Approve')
                ->whereIn('top_featured_id', $topFeatureIds)->with([
                        'user',
                        'propertyType',
                        'purpose',
                        'property',
                        'propertystatus',
                        'customFieldValues.customField.templateValue',
                        'customFieldValues.customFieldOption',
                        'importKeywords',
                        'developer.userDetails',
                        'country',
                        'state',
                        'city'
                    ])->paginate($request->get('per_page', 10));

            $projectsData = $projects->map(function ($property) use ($baseURL) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL, $property) {
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

                $developer = $property->developer;
                $developerData = $developer ? [
                    'id' => $developer->id,
                    'fullname' => $developer->first_name,
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
                    'live_status' => $property->live_status,
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
                    'time' => date('h:i A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
                    'developer' => $developerData,
                    'keyword' => $property->importKeywords,
                    'address' => $property->address,
                    'country' => $property->country,
                    'state' => $property->state,
                    'city' => $property->city,
                    'area_locality' => $property->area_locality,
                    'colony' => $property->colony,
                    'street_address' => $property->street_address,
                    'pin_code' => $property->pin_code,
                    'custom_field_values' => $formattedCustomFieldValues,
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



}
