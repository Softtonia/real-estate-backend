<?php

namespace App\Http\Controllers\PropertyListing;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldRepeaterValues;
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
// use Illuminate\Http\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;



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
                'country_id' => 'required|exists:countries,id',
                'state_id' => 'required|exists:states,id',
                'city_id' => 'required|exists:cities,id',
                'area' => 'nullable|string',
                'locality' => 'nullable|string',
                'colony' => 'nullable|string',
                'street_address' => 'nullable|string',
                'pin_code' => 'required|numeric|digits:6',
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
                'area' => $request->area,
                'locality' => $request->locality,
                'colony' => $request->colony,
                'street_Address' => $request->street_address,
                'pin_code' => $request->pin_code,
            ];



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

                        // new
                        // case 'checkbox':
                        // $checkboxType = DB::table('custom_fields')
                        //     ->where('id', $repeaterField['custom_field_id'])
                        //     ->value('checkbox_type');

                        // if ($checkboxType === 'import_from_amanities') {
                        //     $raw = $repeaterField['field_value']; // e.g. [bathroom{garden-view,courtyard-view},scenic-views{hair-dryer}]
                        //     preg_match_all('/([a-zA-Z0-9\-_ ]+)\{([a-zA-Z0-9\-_, ]+)\}/', $raw, $matches, PREG_SET_ORDER);

                        //     $formatted = [];
                        //     $optionLabels = []; // like [bathroom, scenic-views]
                        //     $slugMapping = [];  // like ['bathroom' => ['garden-view', 'courtyard-view']]

                        //     foreach ($matches as $match) {
                        //         $optionLabel = trim($match[1]);
                        //         $slugs = array_map('trim', explode(',', $match[2]));
                        //         $optionLabels[] = $optionLabel;
                        //         $slugMapping[$optionLabel] = $slugs;
                        //     }

                        //     // 1. Get [id => value] from custom_field_options
                        //     $optionMap = DB::table('custom_field_options')
                        //         ->whereIn('value', $optionLabels)
                        //         ->where('custom_field_id', $repeaterField['custom_field_id'])
                        //         ->pluck('value', 'id') // [id => "bathroom"]
                        //         ->toArray();

                        //     \Log::info('OptionMap: ' . json_encode(array_values($optionMap)));
                        //     \Log::info('SlugMapping: ' . json_encode($slugMapping));

                        //     // 2. Get [slug => id] from amenities_categories
                        //     $categoryMap = DB::table('amenities_categories')
                        //         ->whereIn('slug', array_values($optionMap))
                        //         ->pluck('id', 'slug') // [bathroom => 2]
                        //         ->toArray();

                        //     \Log::info('CategoryMap: ' . json_encode($categoryMap));

                        //     // 3. Map option_id to category_id
                        //     $optionToCategoryMap = [];
                        //     foreach ($optionMap as $optionId => $slug) {
                        //         if (isset($categoryMap[$slug])) {
                        //             $optionToCategoryMap[$optionId] = [
                        //                 'slug' => $slug,
                        //                 'category_id' => $categoryMap[$slug]
                        //             ];
                        //         }
                        //     }

                        //     // 4. Get all amenity slugs from user input
                        //     $allAmenitySlugs = array_merge(...array_values($slugMapping));

                        //     // 5. Fetch amenities by slug and category_id
                        //     $amenities = DB::table('amenities')
                        //         ->whereIn(DB::raw('LOWER(slug)'), array_map('strtolower', $allAmenitySlugs))
                        //         ->whereIn('amenities_categories_id', array_column($optionToCategoryMap, 'category_id'))
                        //         ->get();

                        //     \Log::info('Amenities fetched: ' . json_encode($amenities));

                        //     // 6. Group amenities by category and slug
                        //     $groupedAmenities = [];
                        //     foreach ($amenities as $a) {
                        //         $groupedAmenities[$a->amenities_categories_id][strtolower($a->slug)] = [
                        //             'id' => $a->id,
                        //             'slug' => $a->slug,
                        //             'name' => $a->name,
                        //         ];
                        //     }

                        //     // 7. Build the formatted response
                        //     foreach ($optionToCategoryMap as $optionId => $info) {
                        //         $catSlug = $info['slug'];
                        //         $categoryId = $info['category_id'];

                        //         $formatted[$optionId] = [
                        //             'amenities_cat' => $catSlug,
                        //             'amenities' => []
                        //         ];

                        //         foreach ($slugMapping[$catSlug] ?? [] as $slug) {
                        //             $slugLower = strtolower($slug);
                        //             if (isset($groupedAmenities[$categoryId][$slugLower])) {
                        //                 $a = $groupedAmenities[$categoryId][$slugLower];
                        //                 $formatted[$optionId]['amenities'][$a['id']] = [
                        //                     'id' => $a['id'],
                        //                     'slug' => $a['slug'],
                        //                 ];
                        //             }
                        //         }
                        //     }

                        //     // ✅ Store final data
                        //     $customFieldData['custom_field_options_id'] = implode(',', array_keys($formatted));
                        //     $customFieldData['field_meta_value'] = json_encode($formatted);
                        // } else {
                        //     // ✅ Normal checkbox
                        //     $values = explode(',', $repeaterField['field_value']);
                        //     $customFieldData['field_meta_value'] = implode(',', $values);

                        //     $customFieldOptionsIds = DB::table('custom_field_options')
                        //         ->whereIn('value', $values)
                        //         ->where('custom_field_id', $repeaterField['custom_field_id'])
                        //         ->pluck('id')
                        //         ->implode(',');

                        //     $customFieldData['custom_field_options_id'] = $customFieldOptionsIds;
                        // }
                        // break;










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
                                            $relativePath = 'storage/uploads/customfield/properties/files/' . $fileName;

                                            // Store the file in the desired folder
                                            $file->storeAs('public/uploads/customfield/properties/files', $fileName); // goes to storage/app/public/uploads/customFiles

                                            $filePaths[] = $relativePath;
                                        } else {
                                            return response()->json(['error' => 'Invalid file format. Only PDF, DOC, DOCX allowed.'], 400);
                                        }
                                    }
                                }
                                $customFieldData['field_meta_value'] = json_encode($filePaths); // store array of relative paths
                            }
                            break;



                        ######################### new code  28/06/2025 ############################

                        case 'repeater':
                            if (!empty($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                                foreach ($repeaterField['field_value'] as $row) {
                                    $uniqueRowId = uniqid('repeater_'); // ✅ To group all sub-fields under one repeater row
                                    foreach ($row as $subField) {
                                        $repeaterFieldData = [
                                            'custom_field_id' => $subField['sub_field_id'],
                                            'custom_field_repeater_id' => $repeaterField['custom_field_id'],
                                            'field_type' => $subField['field_type'],
                                            'unique_id' => $uniqueRowId,
                                            'properties_listing_id' => $property->id,
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
                                                                $relativePath = 'storage/uploads/customfield/properties/files/' . $fileName;

                                                                $file->storeAs('public/uploads/customfield/properties/files', $fileName);
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



                            ######################### end new code 28/06/2025 #########################

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

            $user = Auth::user();
            $baseURL = url('/'); // ✅ Get full base URL dynamically

            // Fetch all projects in descending order by created_at
            $properties = PropertyList::with([
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
                'country',
                'state',
                'city'
            ])
                ->orderBy('created_at', 'desc') // 🔹 Sorting by latest first
                ->get();

            $isAdmin = isset($user->role->name) && $user->role->name === 'admin';
            if (!$isAdmin) {
                $properties = $properties->filter(function ($developer) use ($user) {
                    return $developer->created_by == $user->id;
                })->values(); // Reset keys
            }

            $projectsData = $properties->map(function ($property) use ($baseURL) {
                return [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
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
                    'property_address' => $property->property_address,
                    'country' => $property->country,
                    'state' => $property->state,
                    'city' => $property->city,
                    'area' => $property->area,
                    'locality' => $property->locality,
                    'colony' => $property->colony,
                    'street_address' => $property->street_address,
                    'pin_code' => $property->pin_code,
                    'top_featured_id' => $property->top_featured_id,
                    'featured' => $property->top_featured_id !== null, // true if not null, else false
                ];
            });

            return response()->json(
                [
                    'status' => true,
                    'message' => $isAdmin
                        ? 'Admin: Showing all properties.'
                        : 'Showing only your own properties.',
                    'data' => $projectsData
                ],
                200
            );

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()
            ], 500);
        }
    }





    // public function index(Request $request)
    // {
    //     try {
    //         $baseURL = config('app.url');
    //         $basePath = public_path();

    //         // Fetch only properties where live_status is "Approve"
    //         $properties = PropertyList::with([
    //             'country',
    //             'state',
    //             'city',
    //             'user',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'project',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption',
    //             'importKeywords'
    //         ])
    //             ->where('live_status', 'Approve') // Filter properties
    //             ->get();

    //         $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
    //             $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
    //                 $customField = $customFieldValue->customField;
    //                 $customFieldOption = $customFieldValue->customFieldOption ?? null;

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

    //             return [
    //                 'id' => $property->id,
    //                 'property_unique_id' => $property->property_unique_id,
    //                 'property_name' => $property->name,
    //                 'description' => $property->description,
    //                 'country' => $property->country,
    //                 'state' => $property->state,
    //                 'city' => $property->city,
    //                 'property_address' => $property->property_address,
    //                 'live_status' => $property->live_status,
    //                 'temporary_status' => $property->temporary_status,
    //                 'status_reason' => $property->status_reason,
    //                 'user_id' => $property->user_id,
    //                 'created_by' => $property->created_by,
    //                 'listed_by' => optional(optional($property->user)->role)->name,
    //                 'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
    //                 'purpose_id' => $property->purpose_id,
    //                 'purpose_id_name' => optional($property->purpose)->name,
    //                 'property_id' => $property->property_id,
    //                 'property_id_name' => optional($property->property)->name,
    //                 'property_status_id' => $property->property_status_id,
    //                 'property_status_id_name' => optional($property->propertystatus)->name,
    //                 'property_type_id' => $property->property_type_id,
    //                 'property_type_id_name' => optional($property->propertyType)->name,
    //                 'project_id' => $property->project_id,
    //                 'project_id_name' => optional($property->project)->name,
    //                 'total_view' => $property->analytics()->count(),
    //                 'date' => date('d m Y', strtotime($property->created_at)),
    //                 'time' => date('h:m A', strtotime($property->created_at)),
    //                 'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
    //                 'keyword' => $property->importKeywords,
    //                 'custom_field_values' => $formattedCustomFieldValues,
    //             ];
    //         });

    //         return response()->json($propertiesData);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }


    ##### new index code ############

    // public function index(Request $request)
    // {
    //     try {
    //         $baseURL = config('app.url');
    //         $basePath = public_path();

    //         // Fetch only properties where live_status is "Approve"
    //         $properties = PropertyList::with([
    //             'country',
    //             'state',
    //             'city',
    //             'user.role',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'project',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption',
    //             'importKeywords'
    //         ])
    //             ->where('live_status', 'Approve')
    //             ->get();

    //         $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
    //             $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL, $property) {
    //                 $customField = optional($customFieldValue->customField);
    //                 $fieldType = $customField->field_type ?? 'unknown';
    //                 $fieldValue = $customFieldValue->field_meta_value ?? '';
    //                 $allAvailableOptions = [];

    //                 if (in_array($fieldType, ['select', 'radio', 'checkbox'])) {
    //                     $availableOptions = DB::table('custom_field_options')
    //                         ->where('custom_field_id', $customFieldValue->custom_field_id)
    //                         ->get(['id', 'name', 'value']);

    //                     foreach ($availableOptions as $option) {
    //                         $allAvailableOptions[] = [
    //                             'name' => $option->name,
    //                             'value' => $option->value,
    //                         ];
    //                     }
    //                 }

    //                 if ($fieldType === 'repeater') {
    //                     $nestedRows = DB::table('custom_field_repeater_values')
    //                         ->where('custom_field_repeater_id', $customFieldValue->custom_field_id)
    //                         ->where('properties_listing_id', $property->id)
    //                         ->get()
    //                         ->groupBy('unique_id');

    //                     $repeaterData = [];

    //                     foreach ($nestedRows as $groupId => $rows) {
    //                         $groupData = [];

    //                         foreach ($rows as $row) {
    //                             $value = $row->field_meta_value;
    //                             $fieldTypeNested = $row->field_type;
    //                             $nestedOptions = [];

    //                             if (in_array($fieldTypeNested, ['select', 'radio'])) {
    //                                 $option = DB::table('custom_field_repeater_options')
    //                                     ->where('id', $row->custom_field_repeater_options_id)
    //                                     ->first();
    //                                 $value = optional($option)->name ?? $value;

    //                                 $nestedOptions = DB::table('custom_field_repeater_options')
    //                                     ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                     ->get(['name', 'value'])
    //                                     ->map(fn($opt) => [
    //                                         'name' => $opt->name,
    //                                         'value' => $opt->value,
    //                                     ])->toArray();
    //                             } elseif ($fieldTypeNested === 'checkbox') {
    //                                 $ids = explode(',', $row->custom_field_repeater_options_id);
    //                                 $value = DB::table('custom_field_repeater_options')
    //                                     ->whereIn('id', $ids)
    //                                     ->pluck('name')
    //                                     ->toArray();

    //                                 $nestedOptions = DB::table('custom_field_repeater_options')
    //                                     ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                     ->get(['name', 'value'])
    //                                     ->map(fn($opt) => [
    //                                         'name' => $opt->name,
    //                                         'value' => $opt->value,
    //                                     ])->toArray();
    //                             } elseif ($fieldTypeNested === 'file') {
    //                                 $value = $value ? url($value) : null;
    //                             } elseif ($fieldTypeNested === 'media') {
    //                                 $decoded = json_decode($value, true);
    //                                 $value = is_array($decoded)
    //                                     ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
    //                                     : [];
    //                             }

    //                             $groupData[] = [
    //                                 'sub_field_id' => $row->custom_field_id,
    //                                 'field_type' => $fieldTypeNested,
    //                                 'field_value' => $value,
    //                                 'options' => $nestedOptions,
    //                             ];
    //                         }

    //                         $repeaterData[] = $groupData;
    //                     }

    //                     return [
    //                         'custom_field_id' => $customFieldValue->custom_field_id,
    //                         'field_label' => $customField->field_label ?? 'Unknown Field',
    //                         'placeholder' => $customField->field_placeholder,
    //                         'field_type' => $fieldType,
    //                         'field_value' => $repeaterData,
    //                         'options' => [],
    //                     ];
    //                 }

    //                 if (in_array($fieldType, ['select', 'radio'])) {
    //                     $customFieldOption = DB::table('custom_field_options')
    //                         ->where('id', $customFieldValue->custom_field_options_id)
    //                         ->first();
    //                     $fieldValue = optional($customFieldOption)->name;
    //                 } elseif ($fieldType === 'checkbox') {
    //                     $optionIds = explode(',', $customFieldValue->custom_field_options_id);
    //                     $fieldValue = DB::table('custom_field_options')
    //                         ->whereIn('id', $optionIds)
    //                         ->pluck('name')
    //                         ->toArray();
    //                 } elseif (in_array($fieldType, ['media', 'file'])) {
    //                     $fieldValue = json_decode($fieldValue, true) ?? [];
    //                     if (!empty($fieldValue)) {
    //                         $fieldValue = array_map(fn($fileName) => $baseURL . '/uploads/media/' . $fileName, (array) $fieldValue);
    //                     }
    //                 }

    //                 $fieldArray = [
    //                     'custom_field_id' => $customFieldValue->custom_field_id,
    //                     'field_label' => $customField->field_label ?? 'Unknown Field',
    //                     'placeholder' => $customField->field_placeholder,
    //                     'field_type' => $fieldType,
    //                     'field_value' => $fieldValue,
    //                     'options' => $allAvailableOptions,
    //                 ];

    //                 if ($fieldType === 'checkbox') {
    //                     $fieldArray['checkbox_type'] = $customField->checkbox_type ?? null;
    //                 }

    //                 return $fieldArray;
    //             });

    //             return [
    //                 'id' => $property->id,
    //                 'property_unique_id' => $property->property_unique_id,
    //                 'property_name' => $property->name,
    //                 'description' => $property->description,
    //                 'country' => $property->country,
    //                 'state' => $property->state,
    //                 'city' => $property->city,
    //                 'property_address' => $property->property_address,
    //                 'live_status' => $property->live_status,
    //                 'temporary_status' => $property->temporary_status,
    //                 'status_reason' => $property->status_reason,
    //                 'user_id' => $property->user_id,
    //                 'created_by' => $property->created_by,
    //                 'listed_by' => optional(optional($property->user)->role)->name,
    //                 'featured_image' => $property->featured_image
    //                     ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image')
    //                     : null,
    //                 'purpose_id' => $property->purpose_id,
    //                 'purpose_id_name' => optional($property->purpose)->name,
    //                 'property_id' => $property->property_id,
    //                 'property_id_name' => optional($property->property)->name,
    //                 'property_status_id' => $property->property_status_id,
    //                 'property_status_id_name' => optional($property->propertystatus)->name,
    //                 'property_type_id' => $property->property_type_id,
    //                 'property_type_id_name' => optional($property->propertyType)->name,
    //                 'project_id' => $property->project_id,
    //                 'project_id_name' => optional($property->project)->name,
    //                 'total_view' => $property->analytics()->count(),
    //                 'date' => date('d m Y', strtotime($property->created_at)),
    //                 'time' => date('h:i A', strtotime($property->created_at)),
    //                 'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
    //                 'keyword' => $property->importKeywords,
    //                 'custom_field_values' => $formattedCustomFieldValues,
    //             ];
    //         });

    //         return response()->json($propertiesData);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    public function index(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Fetch only properties where live_status is "Approve"
            $properties = PropertyList::with([
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
                ->where('live_status', 'Approve')
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
                    'area' => $property->area,
                    'locality' => $property->locality,
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
                    'featured_image' => $property->featured_image
                        ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image')
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





    ###### end new index code ############




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
                'project_id' => 'nullable|exists:project_listings,id',
                'area' => 'nullable|string',
                'locality' => 'nullable|string',
                'colony' => 'nullable|string',
                'street_address' => 'nullable|string',
                'pin_code' => 'required|numeric|digits:6',

            ]);

            // Find the property by ID
            $property = PropertyList::findOrFail($request->id);

            // ✅ Check access permissions
            if ($user->role->name !== 'admin' && $property->created_by !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only update your own properties.',
                ], 403);
            }

            // ✅ Handle `featured_image` properly
            if ($request->hasFile('featured_image')) {
                $file = $request->file('featured_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
                $file->move(public_path('uploads/properties'), $name);
                $validatedData['featured_image'] = '/uploads/properties/' . $name; // Store only relative path
            } elseif (!empty($request->featured_image) && filter_var($request->featured_image, FILTER_VALIDATE_URL)) {
                $validatedData['featured_image'] = $request->featured_image; // Store full URL if provided
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

                // Collect all repeater custom_field_id (for nested deletion)
                $repeaterFieldIds = collect($request->repeater_fields)
                    ->where('field_type', 'repeater')
                    ->pluck('custom_field_id')
                    ->toArray();

                // Delete old nested repeater sub-fields
                CustomFieldRepeaterValues::where('properties_listing_id', $property->id)->delete();


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

                        ########## new code media & file handle 28/06/2025 ###########
                        // case 'media':
                        //     if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                        //         $fileNames = [];
                        //         foreach ($repeaterField['field_value'] as $file) {
                        //             if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                        //                 $fileName = time() . '_' . $file->getClientOriginalName();
                        //                 $file->move(public_path('uploads/media'), $fileName);
                        //                 $fileNames[] = $fileName;
                        //             }
                        //         }
                        //         $customFieldData['field_meta_value'] = json_encode($fileNames);
                        //     }
                        //     break;

                        // //  FILE after
                        // case 'file':

                        //     if (isset($repeaterField['field_value']) && is_array($repeaterField['field_value'])) {
                        //         $filePaths = [];
                        //         foreach ($repeaterField['field_value'] as $file) {
                        //             if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                        //                 if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                        //                     $fileName = time() . '_' . $file->getClientOriginalName();
                        //                     $relativePath = 'storage/uploads/customfield/properties/files/' . $fileName;

                        //                     // Store the file in the desired folder
                        //                     $file->storeAs('public/uploads/customfield/properties/files', $fileName); // goes to storage/app/public/uploads/customFiles

                        //                     $filePaths[] = $relativePath;
                        //                 } else {
                        //                     return response()->json(['error' => 'Invalid file format. Only PDF, DOC, DOCX allowed.'], 400);
                        //                 }
                        //             }
                        //         }
                        //         $customFieldData['field_meta_value'] = json_encode($filePaths); // store array of relative paths
                        //     }
                        //     break;

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
                                foreach ($repeaterField['field_value'] as $file) {
                                    if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                        if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                            $fileName = time() . '_' . $file->getClientOriginalName();
                                            $relativePath = 'storage/uploads/customfield/properties/files/' . $fileName;
                                            $file->storeAs('public/uploads/customfield/properties/files', $fileName);
                                            $filePaths[] = $relativePath;
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
                                        $repeaterFieldData = [
                                            'custom_field_id' => $subField['sub_field_id'],
                                            'custom_field_repeater_id' => $repeaterField['custom_field_id'],
                                            'field_type' => $subField['field_type'],
                                            'unique_id' => $uniqueRowId,
                                            'properties_listing_id' => $property->id,
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

                                            // case 'media':
                                            //     if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                            //         $fileNames = [];
                                            //         foreach ($subField['field_value'] as $file) {
                                            //             if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                            //                 $fileName = time() . '_' . $file->getClientOriginalName();
                                            //                 $file->move(public_path('uploads/media'), $fileName);
                                            //                 $fileNames[] = $fileName;
                                            //             }
                                            //         }
                                            //         $repeaterFieldData['field_meta_value'] = json_encode($fileNames);
                                            //     }
                                            //     break;

                                            // case 'file':
                                            //     if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                            //         $filePaths = [];
                                            //         foreach ($subField['field_value'] as $file) {
                                            //             if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                            //                 if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                            //                     $fileName = time() . '_' . $file->getClientOriginalName();
                                            //                     $relativePath = 'storage/uploads/customfield/properties/files/' . $fileName;

                                            //                     $file->storeAs('public/uploads/customfield/properties/files', $fileName);
                                            //                     $filePaths[] = $relativePath;
                                            //                 } else {
                                            //                     return response()->json(['error' => 'Invalid file format in repeater. Only PDF, DOC, DOCX files allowed.'], 400);
                                            //                 }
                                            //             }
                                            //         }
                                            //         $repeaterFieldData['field_meta_value'] = json_encode($filePaths); // store array of relative paths
                                            //     }

                                            case 'media':
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
                                                    $repeaterFieldData['field_meta_value'] = json_encode($fileNames);
                                                }
                                                break;

                                            case 'file':
                                                $existingFiles = isset($subField['existing_value']) ? json_decode($subField['existing_value'], true) : [];
                                                $filePaths = is_array($existingFiles) ? $existingFiles : [];

                                                if (isset($subField['field_value']) && is_array($subField['field_value'])) {
                                                    foreach ($subField['field_value'] as $file) {
                                                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                                            if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                                                                $fileName = time() . '_' . $file->getClientOriginalName();
                                                                $relativePath = 'storage/uploads/customfield/properties/files/' . $fileName;
                                                                $file->storeAs('public/uploads/customfield/properties/files', $fileName);
                                                                $filePaths[] = $relativePath;
                                                            } else {
                                                                return response()->json(['error' => 'Invalid file format in repeater. Only PDF, DOC, DOCX allowed.'], 400);
                                                            }
                                                        } elseif (is_string($file)) {
                                                            $filePaths[] = $file; // already existing file path
                                                        }
                                                    }
                                                }

                                                if (!empty($filePaths)) {
                                                    $repeaterFieldData['field_meta_value'] = json_encode($filePaths);
                                                }
                                                break;
                                        }

                                        CustomFieldRepeaterValues::create($repeaterFieldData);
                                    }
                                }
                            }
                            break;


                        ########## end new code media & file handle 28/06/2025 ###########
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

        try {
            $user = Auth::user();
            $id = $request->id;
            $property = PropertyList::find($id);

            if (!$property) {
                return response()->json(['message' => 'Data not found'], 404);
            }

            // ✅ Check access permissions
            if ($user->role->name !== 'admin' && $property->created_by !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only delete your own properties.',
                ], 403);
            }

            $filePath = public_path($property->featured_image);

            // Delete the file if it exists
            if (File::exists($filePath)) {
                File::delete($filePath);
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
    // public function getdatabyId(Request $request)
    // {
    //     try {
    //         if (empty($request->id)) {
    //             return response()->json(['error' => 'ID is required'], 400);
    //         }

    //         $baseURL = config('app.url'); // Base URL for constructing full paths

    //         $property = PropertyList::with([
    //             'country',
    //             'state',
    //             'city',
    //             'user',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'project',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption',
    //             'importKeywords',
    //             'createdBy',
    //             'updatedBy'
    //         ])
    //             ->where('id', $request->id)
    //             ->first();

    //         if (!$property) {
    //             return response()->json(['error' => 'Property not found'], 200);
    //         }

    //         // ✅ Handle Created By and Updated By
    //         $createdByData = optional($property->createdBy) ? [
    //             'id' => optional($property->createdBy)->id,
    //             'name' => optional($property->createdBy)->first_name,
    //             'email' => optional($property->createdBy)->email,
    //             'role' => optional(optional($property->createdBy)->role)->name,
    //         ] : null;

    //         $updatedByData = optional($property->updatedBy) ? [
    //             'id' => optional($property->updatedBy)->id,
    //             'name' => optional($property->updatedBy)->first_name,
    //             'email' => optional($property->updatedBy)->email,
    //             'role' => optional(optional($property->updatedBy)->role)->name,
    //         ] : null;

    //         // ✅ Ensure `featured_image` shows full URL
    //         if (!empty($property->featured_image)) {
    //             $property->featured_image = filter_var($property->featured_image, FILTER_VALIDATE_URL)
    //                 ? $property->featured_image
    //                 : url($property->featured_image);
    //         }

    //         // ✅ Fetch repeater fields dynamically
    //         $repeaterFields = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
    //             $customField = optional($customFieldValue->customField);
    //             $fieldType = $customField->field_type ?? 'unknown';
    //             $fieldValue = $customFieldValue->field_meta_value ?? '';
    //             $allAvailableOptions = [];

    //             // ✅ Fetch all available options dynamically
    //             $availableOptions = DB::table('custom_field_options')
    //                 ->where('custom_field_id', $customFieldValue->custom_field_id)
    //                 ->where('status', 1) // Only fetch active options
    //                 ->get(['id', 'name', 'value']);

    //             if ($availableOptions->isNotEmpty()) {
    //                 foreach ($availableOptions as $option) {
    //                     $allAvailableOptions[] = [
    //                         'name' => $option->name,
    //                         'value' => $option->value,
    //                     ];
    //                 }
    //             }

    //             // ✅ Handle multiple field types correctly
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
    //             'id' => $property->id,
    //             'property_unique_id' => $property->property_unique_id,
    //             'property_name' => $property->name,
    //             'description' => $property->description,
    //             'country_id' => $property->country_id,
    //             'state_id' => $property->state_id,
    //             'city_id' => $property->city_id,
    //             'country' => $property->country,
    //             'state' => $property->state,
    //             'city' => $property->city,
    //             'property_address' => $property->property_address,
    //             'live_status' => $property->live_status,
    //             'temporary_status' => $property->temporary_status,
    //             'status_reason' => $property->status_reason,
    //             'user_id' => $property->user_id,
    //             'listed_by' => optional(optional($property->user)->role)->name,
    //             'featured_image' => $property->featured_image, // ✅ Full URL
    //             'purpose_id' => $property->purpose_id,
    //             'purpose_id_name' => optional($property->purpose)->name,
    //             'property_id' => $property->property_id,
    //             'property_id_name' => optional($property->property)->name,
    //             'property_status_id' => $property->property_status_id,
    //             'property_status_id_name' => optional($property->propertystatus)->name,
    //             'property_type_id' => $property->property_type_id,
    //             'property_type_id_name' => optional($property->propertyType)->name,
    //             'posted_on' => date('d M, Y', strtotime($property->created_at)),
    //             'project_id' => $property->project_id,
    //             'project_id_name' => optional($property->project)->name,
    //             'total_view' => $property->analytics()->count(),
    //             'keyword' => $property->importKeywords->pluck('id')->toArray() ?? null,
    //             'created_by' => $createdByData,
    //             'updated_by' => $updatedByData,
    //             'repeater_fields' => $repeaterFields, // ✅ Include repeater fields in response with options
    //         ]);

    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    ################## new get data by id 02/07/2025 ###########

    // public function getdatabyId(Request $request)
    // {
    //     try {
    //         if (empty($request->id)) {
    //             return response()->json(['error' => 'ID is required'], 400);
    //         }

    //         $baseURL = config('app.url');

    //         $property = PropertyList::with([
    //             'country',
    //             'state',
    //             'city',
    //             'user',
    //             'propertyType',
    //             'purpose',
    //             'property',
    //             'propertystatus',
    //             'project',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption',
    //             'importKeywords',
    //             'createdBy',
    //             'updatedBy'
    //         ])
    //             ->where('id', $request->id)
    //             ->first();

    //         if (!$property) {
    //             return response()->json(['error' => 'Property not found'], 200);
    //         }

    //         $createdByData = optional($property->createdBy) ? [
    //             'id' => optional($property->createdBy)->id,
    //             'name' => optional($property->createdBy)->first_name,
    //             'email' => optional($property->createdBy)->email,
    //             'role' => optional(optional($property->createdBy)->role)->name,
    //         ] : null;

    //         $updatedByData = optional($property->updatedBy) ? [
    //             'id' => optional($property->updatedBy)->id,
    //             'name' => optional($property->updatedBy)->first_name,
    //             'email' => optional($property->updatedBy)->email,
    //             'role' => optional(optional($property->updatedBy)->role)->name,
    //         ] : null;

    //         if (!empty($property->featured_image)) {
    //             $property->featured_image = filter_var($property->featured_image, FILTER_VALIDATE_URL)
    //                 ? $property->featured_image
    //                 : url($property->featured_image);
    //         }

    //         $repeaterFields = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL, $property) {
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
    //                     ->where('properties_listing_id', $property->id)
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

    //                             // Get all options
    //                             $nestedOptions = DB::table('custom_field_repeater_options')
    //                                 ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                 ->get(['name', 'value'])
    //                                 ->map(function ($opt) {
    //                                     return [
    //                                         'name' => $opt->name,
    //                                         'value' => $opt->value,
    //                                     ];
    //                                 })->toArray();
    //                         } elseif ($fieldTypeNested === 'checkbox') {
    //                             $ids = explode(',', $row->custom_field_repeater_options_id);
    //                             $value = DB::table('custom_field_repeater_options')
    //                                 ->whereIn('id', $ids)
    //                                 ->pluck('name')
    //                                 ->toArray();

    //                             $nestedOptions = DB::table('custom_field_repeater_options')
    //                                 ->where('custom_field_repeater_id', $row->custom_field_id)
    //                                 ->get(['name', 'value'])
    //                                 ->map(function ($opt) {
    //                                     return [
    //                                         'name' => $opt->name,
    //                                         'value' => $opt->value,
    //                                     ];
    //                                 })->toArray();
    //                         } elseif ($fieldTypeNested === 'file') {
    //                             $value = $value ? url($value) : null;
    //                         } elseif ($fieldTypeNested === 'media') {
    //                             $decoded = json_decode($value, true);
    //                             $value = is_array($decoded) ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded) : [];
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
    //             'id' => $property->id,
    //             'property_unique_id' => $property->property_unique_id,
    //             'property_name' => $property->name,
    //             'description' => $property->description,
    //             'country_id' => $property->country_id,
    //             'state_id' => $property->state_id,
    //             'city_id' => $property->city_id,
    //             'country' => $property->country,
    //             'state' => $property->state,
    //             'city' => $property->city,
    //             'property_address' => $property->property_address,
    //             'live_status' => $property->live_status,
    //             'temporary_status' => $property->temporary_status,
    //             'status_reason' => $property->status_reason,
    //             'user_id' => $property->user_id,
    //             'listed_by' => optional(optional($property->user)->role)->name,
    //             'featured_image' => $property->featured_image,
    //             'purpose_id' => $property->purpose_id,
    //             'purpose_id_name' => optional($property->purpose)->name,
    //             'property_id' => $property->property_id,
    //             'property_id_name' => optional($property->property)->name,
    //             'property_status_id' => $property->property_status_id,
    //             'property_status_id_name' => optional($property->propertystatus)->name,
    //             'property_type_id' => $property->property_type_id,
    //             'property_type_id_name' => optional($property->propertyType)->name,
    //             'posted_on' => date('d M, Y', strtotime($property->created_at)),
    //             'project_id' => $property->project_id,
    //             'project_id_name' => optional($property->project)->name,
    //             'total_view' => $property->analytics()->count(),
    //             'keyword' => $property->importKeywords->pluck('id')->toArray() ?? null,
    //             'created_by' => $createdByData,
    //             'updated_by' => $updatedByData,
    //             'repeater_fields' => $repeaterFields,
    //         ]);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    public function getdatabyId(Request $request)
    {
        try {
            if (empty($request->id)) {
                return response()->json(['error' => 'ID is required'], 400);
            }

            $user = Auth::user();

            $baseURL = config('app.url');

            $property = PropertyList::with([
                'country',
                'state',
                'city',
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
                return response()->json(['error' => 'Property not found'], 200);
            }

            // ✅ Check access permissions
            if ($user->role->name !== 'admin' && $property->created_by !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only view your own properties.',
                ], 403);
            }

            $createdByData = optional($property->createdBy) ? [
                'id' => optional($property->createdBy)->id,
                'name' => optional($property->createdBy)->first_name,
                'email' => optional($property->createdBy)->email,
                'role' => optional(optional($property->createdBy)->role)->name,
            ] : null;

            $updatedByData = optional($property->updatedBy) ? [
                'id' => optional($property->updatedBy)->id,
                'name' => optional($property->updatedBy)->first_name,
                'email' => optional($property->updatedBy)->email,
                'role' => optional(optional($property->updatedBy)->role)->name,
            ] : null;

            if (!empty($property->featured_image)) {
                $property->featured_image = filter_var($property->featured_image, FILTER_VALIDATE_URL)
                    ? $property->featured_image
                    : url($property->featured_image);
            }

            $repeaterFields = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL, $property) {
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
                                // $decoded = is_string($value) ? json_decode($value, true) : $value;
                                // $value = is_array($decoded)
                                //     ? array_map(fn($file) => url($file), $decoded)
                                //     : [];

                                $decoded = is_string($value) ? json_decode($value, true) : $value;

                                $existingValue = is_array($decoded) ? $decoded : [];

                                $value = is_array($decoded)
                                    ? array_map(fn($file) => url($file), $decoded)
                                    : [];



                            } elseif ($fieldTypeNested === 'media') {
                                // $decoded = json_decode($value, true);
                                // $value = is_array($decoded)
                                //     ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                                //     : [];

                                $decoded = is_string($value) ? json_decode($value, true) : $value;

                                $existingValue = is_array($decoded) ? $decoded : [];

                                $value = is_array($decoded)
                                    ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                                    : [];

                            }

                            // $groupData[] = [
                            //     'sub_field_id' => $row->custom_field_id,
                            //     'field_type' => $fieldTypeNested,
                            //     'field_label' => $fieldLabel,
                            //     'placeholder' => $fieldPlaceholder,
                            //     'field_value' => $value,
                            //     'options' => $nestedOptions,
                            // ];

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

                    // $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                    // $fieldValue = is_array($decoded)
                    //     ? array_map(fn($file) => url($file), $decoded)
                    //     : [];

                    $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;

                    $fieldValue = is_array($decoded)
                        ? array_map(fn($file) => url($file), $decoded)
                        : [];

                    $existingValue = is_array($decoded) ? $decoded : [];



                } elseif ($fieldType === 'media') {
                    // $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : $fieldValue;
                    // $fieldValue = is_array($decoded)
                    //     ? array_map(fn($file) => $baseURL . '/uploads/media/' . $file, $decoded)
                    //     : [];

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

            return response()->json([
                'id' => $property->id,
                'property_unique_id' => $property->property_unique_id,
                'property_name' => $property->name,
                'description' => $property->description,
                'country_id' => $property->country_id,
                'state_id' => $property->state_id,
                'city_id' => $property->city_id,
                'country' => $property->country,
                'state' => $property->state,
                'city' => $property->city,

                'property_address' => $property->property_address,
                'area' => $property->area,
                'locality' => $property->locality,
                'colony' => $property->colony,
                'street_address' => $property->street_address,
                'pin_code' => $property->pin_code,
                'live_status' => $property->live_status,
                'temporary_status' => $property->temporary_status,
                'status_reason' => $property->status_reason,
                'user_id' => $property->user_id,
                'listed_by' => optional(optional($property->user)->role)->name,
                'featured_image' => $property->featured_image,
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
                'repeater_fields' => $repeaterFields,
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    ################## end new get data by id 02/07/2025 ###########

    public function getUserProperties(Request $request)
    {
        try {
            // Authenticate the user
            $user = $request->user();
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

            // Generate full featured_image URL
            $properties = $properties->map(function ($property) {
                if (!empty($property->featured_image)) {
                    $property->featured_image = filter_var($property->featured_image, FILTER_VALIDATE_URL)
                        ? $property->featured_image
                        : url(ltrim($property->featured_image, '/'));
                }
                return $property;
            });

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

            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized.'], 401);
            }
            // Validate the request
            $validatedData = $request->validate([
                'property_id' => 'required|exists:properties_listing,id',  // Ensure property exists
                'temporary_status' => 'required|string',  // Temporary status is required
            ]);


            $property = PropertyList::findOrFail($validatedData['property_id']);

            if (!$property) {
                return response()->json(['error' => 'Property not found'], 200);
            }

            // ✅ Optional (extra safety): Check if the user is admin or creator
            if ($user->id !== $property->created_by && strtolower(optional($user->role)->name) !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized : You can only update your own properties.'
                ], 403);
            }

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

    public function getAllProjectByLocationId(Request $request)
    {
        try {
            $user = Auth::user();
            $baseURL = config('app.url');
            $basePath = public_path();
            $projectData = [];



            $validator = Validator::make($request->all(), [
                'country_id' => 'required|integer|exists:countries,id',
                'state_id' => 'required|integer|exists:states,id',
                'city_id' => 'required|integer|exists:cities,id',
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'errors' => $validator->errors(),      // field-wise detail
                    'message' => 'country_id, state_id, and city_id are all required.',
                ], 422);
            }

            // Build query dynamically based on provided parameters
            $query = ProjectList::query();

            if (!empty($request->country_id) && !empty($request->state_id) && !empty($request->city_id)) {
                $query->where('country_id', $request->country_id)
                    ->where('state_id', $request->state_id)
                    ->where('city_id', $request->city_id);
            }




            // Fetch matching projects
            $projects = $query->get();

            // Return error if no data is found
            if ($projects->isEmpty()) {
                return response()->json(['error' => 'No projects found for the given location.'], 200);
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


            $authUser = Auth::user();
            $userData = $authUser;

            $userId = $userData->id;

            $baseURL = config('app.url');
            $basePath = public_path();
            $projectData = [];

            $validator = Validator::make($request->all(), [
                'country_id' => 'required|integer|exists:countries,id',
                'state_id' => 'required|integer|exists:states,id',
                'city_id' => 'required|integer|exists:cities,id',
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'errors' => $validator->errors(),      // field-wise detail
                    'message' => 'country_id, state_id, and city_id are all required.',
                ], 422);
            }
            $country_id = $request->country_id;
            $state_id = $request->state_id;
            $city_id = $request->city_id;

            $user = User::where('id', $userId)->first();

            $projects = ProjectList::where('country_id', $country_id)
                ->where('state_id', $state_id)
                ->where('city_id', $city_id)
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

        // if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
        //     return response()->json(['error' => 'Please provide an API token.'], 422);
        // }

        // // Retrieve the Authorization header
        // $authorizationHeader = $request->header('Authorization');

        // // Check if the header starts with "Bearer "
        // if (strpos($authorizationHeader, 'Bearer ') !== 0) {
        //     return response()->json(['error' => 'Invalid token format.'], 422);
        // }

        // // Extract the token by removing "Bearer " prefix
        // $requestToken = substr($authorizationHeader, 7);

        // // Verify the token dynamically (e.g., check in the database)
        // $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        // if (!$tokenExists) {
        //     return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        // }

        try {
            // Find the builder by ID
            $delete_ids = explode(',', $request->id);

            foreach ($delete_ids as $row) {
                $property = PropertyList::findOrFail($row);

                $filePath = public_path($property->featured_image);

                // Delete the file if it exists
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }

                // Delete the builder record
                $property->customFieldValues()->delete();
                // Delete the property
                $property->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Lisitng bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'property not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }






    // properties search by name , property_unique_id

    public function propertiesSearch(Request $request)
    {
        try {
            $user = Auth::user();
            $search = $request->input('search'); // 🔍 Single input for both name & unique ID
            $baseURL = url('/');

            $projects = PropertyList::when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('property_unique_id', 'like', "%{$search}%");
                });
            })
                ->with([
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
                    'country',
                    'state',
                    'city'
                ])
                ->orderBy('created_at', 'desc')
                ->get();



            $projectsData = $projects->map(function ($property) use ($baseURL) {
                return [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
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
                            ? $property->featured_image
                            : $baseURL . $property->featured_image)
                        : null,
                    'property_address' => $property->property_address,
                    'country' => $property->country,
                    'state' => $property->state,
                    'city' => $property->city,
                ];
            });

            return response()->json(
                [
                    'data' => $projectsData
                ],
                200
            );

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage() . ' ' . $th->getLine() . ' ' . $th->getFile()
            ], 500);
        }
    }


    ############ No Auth ##########



    public function getdatabyIdNoAuth(Request $request, $id)
    {
        try {
            $propertyId = $request->id ?? $id;
            if (empty($propertyId)) {
                return response()->json(['error' => 'ID is required'], 400);
            }

            $baseURL = config('app.url');

            $property = PropertyList::with([
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
                'importKeywords',
                'createdBy.role',
                'updatedBy.role'
            ])->where('live_status', 'Approve')
                ->where('id', $propertyId)
                ->first();

            if (!$property) {
                return response()->json(['error' => 'Property not found'], 200);
            }

            // Safely access createdBy data
            $createdByData = $property->createdBy ? [
                'id' => $property->createdBy->id,
                'name' => $property->createdBy->first_name,
                'email' => $property->createdBy->email,
                'role' => $property->createdBy->role->name ?? null,
            ] : null;

            // Safely access updatedBy data
            $updatedByData = $property->updatedBy ? [
                'id' => $property->updatedBy->id,
                'name' => $property->updatedBy->first_name,
                'email' => $property->updatedBy->email,
                'role' => $property->updatedBy->role->name ?? null,
            ] : null;

            if (!empty($property->featured_image)) {
                $property->featured_image = filter_var($property->featured_image, FILTER_VALIDATE_URL)
                    ? $property->featured_image
                    : url($property->featured_image);
            }

            $repeaterFields = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL, $property) {
                $customField = $customFieldValue->customField;
                if (!$customField) {
                    return null;
                }

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

                            // Add null check for nestedCustomField
                            if (!$nestedCustomField) {
                                continue;
                            }

                            $templateDetails = DB::table('custom_field_unique_codes')
                                ->where('id', $nestedCustomField->template_id ?? null)
                                ->first();

                            if (in_array($fieldTypeNested, ['select', 'radio'])) {
                                $option = DB::table('custom_field_repeater_options')
                                    ->where('id', $row->custom_field_repeater_options_id)
                                    ->first();
                                $value = $option->name ?? $value;

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

                // For other field types
                if (in_array($fieldType, ['select', 'radio'])) {
                    $customFieldOption = DB::table('custom_field_options')
                        ->where('id', $customFieldValue->custom_field_options_id)
                        ->first();
                    $fieldValue = $customFieldOption->name ?? $fieldValue;
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
            })->filter(); // Filter out any null values from the map

            return response()->json([
                'id' => $property->id,
                'property_unique_id' => $property->property_unique_id,
                'property_name' => $property->name,
                'description' => $property->description,
                'country_id' => $property->country_id,
                'state_id' => $property->state_id,
                'city_id' => $property->city_id,
                'country' => $property->country,
                'state' => $property->state,
                'city' => $property->city,
                'area' => $property->area,
                    'locality' => $property->locality,
                    'colony' => $property->colony,
                    'street_address' => $property->street_address,
                    'pin_code' => $property->pin_code,
                'property_address' => $property->property_address,
                'live_status' => $property->live_status,
                'temporary_status' => $property->temporary_status,
                'status_reason' => $property->status_reason,
                'user_id' => $property->user_id,
                'listed_by' => $property->user->role->name ?? null,
                'featured_image' => $property->featured_image,
                'purpose_id' => $property->purpose_id,
                'purpose_id_name' => $property->purpose->name ?? null,
                'property_id' => $property->property_id,
                'property_id_name' => $property->property->name ?? null,
                'property_status_id' => $property->property_status_id,
                'property_status_id_name' => $property->propertystatus->name ?? null,
                'property_type_id' => $property->property_type_id,
                'property_type_id_name' => $property->propertyType->name ?? null,
                'posted_on' => date('d M, Y', strtotime($property->created_at)),
                'project_id' => $property->project_id,
                'project_id_name' => $property->project->name ?? null,
                'total_view' => $property->analytics()->count(),
                'keyword' => $property->importKeywords->pluck('id')->toArray() ?? null,
                'created_by' => $createdByData,
                'updated_by' => $updatedByData,
                'repeater_fields' => $repeaterFields,
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


}
