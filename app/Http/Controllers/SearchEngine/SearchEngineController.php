<?php

namespace App\Http\Controllers\SearchEngine;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Customfieldvalue;
use App\Models\Keyword;
use App\Models\PropertyList;
use App\Models\ProjectList;
use App\Models\Status;
use App\Models\User;
use App\Models\Purpose;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Developer;


use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


use App\Models\PropertyType;

class SearchEngineController extends Controller
{

    


    public function globleSearchEngine(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            $AuthUser = auth('sanctum')->user();

            // Properties query
            $propertiesQuery = PropertyList::with([
                'country','state','city','user',
                'propertyType', 'purpose', 'property',
                'propertystatus', 'project', 'customFieldValues.customField',
                'customFieldValues.customFieldOption'
            ])->where('live_status','Approve')
            ->when($request->country_id, function ($q, $country_id) {
                $q->where('country_id', $country_id);
            })
            ->when($request->state_id, function ($q, $state_id) {
                $q->where('state_id', $state_id);
            })
            ->when($request->city_id, function ($q, $city_id) {
                $q->where('city_id', $city_id);
            })
            ->when($request->area_locality, function ($q, $area_locality) {
                $q->where('area_locality', $area_locality);
            });

            // Projects query (for project purpose) - WITH ALL RELATIONSHIPS
            $projectsQuery = ProjectList::with([
                'country', 'state', 'city', 'user',
                'propertyType', 'purpose', 'property',
                'propertystatus', 'developer', 'customFieldValues.customField',
                'customFieldValues.customFieldOption'
            ])
            ->where('live_status','Approve')
            ->when($request->country_id, function ($q, $country_id) {
                $q->where('country_id', $country_id);
            })
            ->when($request->state_id, function ($q, $state_id) {
                $q->where('state_id', $state_id);
            })
            ->when($request->city_id, function ($q, $city_id) {
                $q->where('city_id', $city_id);
            })
            ->when($request->area_locality, function ($q, $area_locality) {
                $q->where('area_locality', $area_locality);
            });

            // Agents query (top agents based on filters)
            $agentsQuery = User::with(['role','userDetails.country', 'userDetails.state', 'userDetails.city'])
                ->where('role_id', 3) // Agent role
                ->where('isapproved', 1) 
                ->when($request->country_id, function ($q, $country_id) {
                    $q->whereHas('properties', function ($query) use ($country_id) {
                        $query->where('country_id', $country_id);
                    });
                })
                ->when($request->state_id, function ($q, $state_id) {
                    $q->whereHas('properties', function ($query) use ($state_id) {
                        $query->where('state_id', $state_id);
                    });
                })
                ->when($request->city_id, function ($q, $city_id) {
                    $q->whereHas('properties', function ($query) use ($city_id) {
                        $query->where('city_id', $city_id);
                    });
                })
                ->when($request->area_locality, function ($q, $area_locality) {
                    $q->whereHas('properties', function ($query) use ($area_locality) {
                        $query->where('area_locality', $area_locality);
                    });
                });
            

            // Apply purpose-specific filters to properties
            if ($request->purpose == 'buy') {
                    if (!empty($request->purpose)) {
                        $purpose = Purpose::where('slug', $request->purpose)->first();
                        if ($purpose) {
                            $propertiesQuery->where('purpose_id', $purpose->id);
                        }
                    }

                    if (!empty($request->property_id)) {
                        $propertiesQuery->where('property_id', $request->property_id);
                    }

                    if (!empty($request->property_type_id)) {
                        $explod_property_type_id = explode(',', $request->property_type_id);
                        $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
                            foreach ($explod_property_type_id as $id) {
                                $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
                            }
                        });
                    }

                    if (!empty($request->property_status_id)) {
                        $explod_property_status_id = explode(',', $request->property_status_id);
                        $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
                    }

                    if (!empty($request->property_price_low) && !empty($request->property_price_high)) {
                        $checkCustomField = CustomField::where('field_label', 'property_rent_amount')->first();
                        if ($checkCustomField) {
                            $custom_field_id = $checkCustomField->id;
                            $properties_listing_id_arr = Customfieldvalue::where('custom_field_id', $custom_field_id)
                                ->whereBetween('field_meta_value', [$request->property_price_low, $request->property_price_high])
                                ->pluck('properties_listing_id')
                                ->toArray();
                            $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                        }
                    }
                }

                if ($request->purpose == 'rent') {
                    if (!empty($request->purpose)) {
                        $purpose = Purpose::where('slug', $request->purpose)->first();
                        if ($purpose) {
                            $propertiesQuery->where('purpose_id', $purpose->id);
                        }
                    }

                    if (!empty($request->property_id)) {
                        $propertiesQuery->where('property_id', $request->property_id);
                    }

                    if (!empty($request->property_type_id)) {
                        $explod_property_type_id = explode(',', $request->property_type_id);
                        $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
                            foreach ($explod_property_type_id as $id) {
                                $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
                            }
                        });
                    }

                    if (!empty($request->property_status_id)) {
                        $explod_property_status_id = explode(',', $request->property_status_id);
                        $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
                    }

                    if (!empty($request->rent_price_low) && !empty($request->rent_price_high)) {
                        $checkCustomField = CustomField::where('field_label', 'property_rent_amount')->first();
                        if ($checkCustomField) {
                            $custom_field_id = $checkCustomField->id;
                            $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                                ->whereBetween('field_meta_value', [$request->rent_price_low, $request->rent_price_high])
                                ->pluck('properties_listing_id')
                                ->toArray();
                            $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                        }
                    }

                    if (!empty($request->posted_by)) {
                        $user_id_arr = [];
                        if ($request->posted_by == 'agent') {
                            $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
                        }
                        if ($request->posted_by == 'owner') {
                            $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
                        }
                        $propertiesQuery->whereIn('user_id', $user_id_arr);
                    }
                }

                if ($request->purpose == 'pgco-living') {
                    if (!empty($request->property_type_id)) {
                        $explod_property_type_id = explode(',', $request->property_type_id);
                        $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
                            foreach ($explod_property_type_id as $id) {
                                $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
                            }
                        });
                    }

                    if (!empty($request->property_status_id)) {
                        $explod_property_status_id = explode(',', $request->property_status_id);
                        $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
                    }

                    if (!empty($request->pg_rent_price_low) && !empty($request->pg_rent_price_high)) {
                        $checkCustomField = CustomField::where('field_label', 'property_rent_amount')->first();
                        if ($checkCustomField) {
                            $custom_field_id = $checkCustomField->id;
                            $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                                ->whereBetween('field_meta_value', [$request->pg_rent_price_low, $request->pg_rent_price_high])
                                ->pluck('properties_listing_id')
                                ->toArray();
                            $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                        }
                    }

                    if (!empty($request->posted_by)) {
                        $user_id_arr = [];
                        if ($request->posted_by == 'agent') {
                            $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
                        }
                        if ($request->posted_by == 'owner') {
                            $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
                        }
                        $propertiesQuery->whereIn('user_id', $user_id_arr);
                    }

                    if (!empty($request->availabel_for)) {
                        $checkCustomField = CustomField::where('field_label', 'listing_available_for')->first();
                        if ($checkCustomField) {
                            $custom_field_id = $checkCustomField->id;
                            $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                                ->whereRaw("FIND_IN_SET(?, field_meta_value)", [$request->availabel_for])
                                ->pluck('properties_listing_id')
                                ->toArray();
                            $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                        }
                    }
                }

                if ($request->purpose == 'plot_land') {
                    if (!empty($request->property_id)) {
                        $propertiesQuery->where('property_id', $request->property_id);
                    }

                    if (!empty($request->property_type_id)) {
                        $explod_property_type_id = explode(',', $request->property_type_id);
                        $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
                            foreach ($explod_property_type_id as $id) {
                                $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
                            }
                        });
                    }

                    if (!empty($request->plot_price_low) && !empty($request->plot_price_high)) {
                        $checkCustomField = CustomField::where('field_label', 'property_rent_amount')->first();
                        if ($checkCustomField) {
                            $custom_field_id = $checkCustomField->id;
                            $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                                ->whereBetween('field_meta_value', [$request->plot_price_low, $request->plot_price_high])
                                ->pluck('properties_listing_id')
                                ->toArray();
                            $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                        }
                    }

                    if (!empty($request->posted_by)) {
                        $user_id_arr = [];
                        if ($request->posted_by == 'agent') {
                            $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
                        }
                        if ($request->posted_by == 'owner') {
                            $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
                        }
                        $propertiesQuery->whereIn('user_id', $user_id_arr);
                    }

                    if (!empty($request->plot_area)) {
                        // Add plot area filtering logic here if needed
                    }
                }

                if ($request->purpose == 'project') {
                    if (!empty($request->property_id)) {
                        $propertiesQuery->where('property_id', $request->property_id);
                    }

                    if (!empty($request->property_type_id)) {
                        $explod_property_type_id = explode(',', $request->property_type_id);
                        $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
                            foreach ($explod_property_type_id as $id) {
                                $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
                            }
                        });
                    }

                    if (!empty($request->project_price_low) && !empty($request->project_price_high)) {
                        $checkCustomField = CustomField::where('field_label', 'property_rent_amount')->first();
                        if ($checkCustomField) {
                            $custom_field_id = $checkCustomField->id;
                            $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                                ->whereBetween('field_meta_value', [$request->project_price_low, $request->project_price_high])
                                ->pluck('properties_listing_id')
                                ->toArray();
                            $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                        }
                    }
                }

            // Apply keyword filter to properties
            if (isset($request->keyword)) {
                $property_id_arr = Keyword::where('keyword', $request->keyword)->where('property_id', '!=', null)->pluck('property_id')->toArray();
                $propertiesQuery->whereIn('id', $property_id_arr);
            }

            // Get the results
            $properties = $propertiesQuery->get();
            $totalCount = $propertiesQuery->count();

            // Format properties data
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
                        'field_label' => $customField ? $customField->field_label : null,
                    ];
                });

                return [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'country_id' => $property->country_id,
                    'country' => $property->country_id ? [
                        'id' =>$property->country->id,
                        'name' => $property->country->name,
                    ] : null,
                    'state_id' => $property->state_id,
                    'state' => $property->state_id ? [
                        'id' => $property->state->id,
                        'name'=> $property->state->name,
                    ] : null,
                    'city_id' => $property->city_id,
                    'city' => $property->city_id ? [
                        'id' => $property->city->id,
                        'name'=> $property->city->name,
                    ] : null,
                    'property_address' => $property->property_address,
                    'area_locality' => $property->area_locality,
                    'live_status' => $property->live_status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? url($property->featured_image) : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => $property->property_type_id
                                            ? DB::table('property_types')
                                                ->whereIn('id', explode(',', $property->property_type_id))
                                                ->pluck('name')
                                                ->implode(', ')
                                            : null,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];
            });

            // Get projects if purpose is 'project'
            $projectsData = [];
            if ($request->purpose == 'project') {
                $projects = $projectsQuery->get();
                
                $projectsData = $projects->map(function ($project) use ($baseURL) {
                    // Format custom field values for projects
                    $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
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
                            'field_label' => $customField ? $customField->field_label : null,
                        ];
                    });

                    return [
                        'id' => $project->id,
                        'project_name' => $project->name,
                        'description' => $project->description,
                        'country_id' => $project->country_id,
                        'country' => $project->country ? [
                            'id' => $project->country->id,
                            'name' => $project->country->name
                        ] : null,
                        'state_id' => $project->state_id,
                        'state' => $project->state ? [
                            'id' => $project->state->id,
                            'name' => $project->state->name
                        ] : null,
                        'city_id' => $project->city_id,
                        'city' => $project->city ? [
                            'id' => $project->city->id,
                            'name' => $project->city->name
                        ] : null,
                        'address' => $project->address,
                        'area_locality' => $project->area_locality,
                        'featured_image' => $project->featured_image ? url($project->featured_image) : null,
                        'user_id' => $project->user_id,
                        'developer' => $project->developer ? $project->developer->name : null,
                        'developer_id' => $project->developer_id,
                        'purpose_id' => $project->purpose_id,
                        'purpose_id_name' => optional($project->purpose)->name,
                        'property_type_id' => $project->property_type_id,
                        'property_type_id_name' => optional($project->propertyType)->name,
                        'property_status_id' => $project->property_status_id,
                        'property_status_id_name' => optional($project->propertystatus)->name,
                        'total_units' => $project->total_units,
                        'date' => date('d m Y', strtotime($project->created_at)),
                        'time' => date('h:m A', strtotime($project->created_at)),
                        'timestamp' => date('d m Y h:m A', strtotime($project->created_at)),
                        'custom_field_values' => $formattedCustomFieldValues,
                        // Add other project fields as needed
                    ];
                });
            }

            // Get top agents based on filters
            $agents = $agentsQuery->withCount(['properties' => function($query) use ($request) {
                // Apply the same location filters to agent's properties count
                $query->when($request->country_id, function ($q, $country_id) {
                    $q->where('country_id', $country_id);
                })
                ->when($request->state_id, function ($q, $state_id) {
                    $q->where('state_id', $state_id);
                })
                ->when($request->city_id, function ($q, $city_id) {
                    $q->where('city_id', $city_id);
                })
                ->when($request->area_locality, function ($q, $area_locality) {
                    $q->where('area_locality', $area_locality);
                });
            }])
            ->orderBy('properties_count', 'desc')
            ->limit(100) // Limit to top 10 agents
            ->get();

        
            $agentsData = $agents->map(function ($agent) use ($AuthUser, $baseURL) {
                // Apply masking if user is not authenticated
                $email = $agent->email ?? '';
                $phone = $agent->phone ?? '';

                if (!$AuthUser) {
                    if (!empty($email)) {
                        $email = preg_replace('/(?<=.{2}).(?=.*@)/', '*', $email);
                    }
                    if (!empty($phone)) {
                        $phone = substr($phone, 0, 3) . '****' . substr($phone, -3);
                    }
                }

                return [
                    'id' => $agent->id,
                    'first_name' => $agent->first_name,
                    'last_name' => $agent->last_name,
                    'email' => $email,
                    'phone' => $phone,
                    'role_id' => $agent->role_id,
                    'role_name' => optional($agent->role)->name ?? 'Agent',
                    'unique_id' => $agent->unique_id ?? '',
                    'isapproved' => $agent->isapproved,
                    'country' => $agent->userDetails && $agent->userDetails->country 
                        ? ['id' => $agent->userDetails->country->id, 'name' => $agent->userDetails->country->name] 
                        : 'N/A',
                    'state' => $agent->userDetails && $agent->userDetails->state 
                        ? ['id' => $agent->userDetails->state->id, 'name' => $agent->userDetails->state->name] 
                        : 'N/A',
                    'city' => $agent->userDetails && $agent->userDetails->city 
                        ? ['id' => $agent->userDetails->city->id, 'name' => $agent->userDetails->city->name] 
                        : 'N/A',
                    'area_locality' => $agent->userDetails->area_locality ?? 'N/A',
                    'colony' => $agent->userDetails->colony ?? 'N/A',
                    'street_address' => $agent->userDetails->street_address ?? 'N/A',
                    'pin_code' => $agent->userDetails->pin_code ?? 'N/A',
                    'about' => $agent->userDetails->about ?? '',
                    'bussiness_name' => $agent->userDetails->bussiness_name ?? '',
                    'profile_photo' => $agent->userDetails->profile_photo ? url($agent->userDetails->profile_photo) : null,
                    'about_us' => $agent->userDetails->about_us ?? '',
                    'properties_count' => $agent->properties_count,
                    'date' => date('d m Y', strtotime($agent->created_at)),
                    'time' => date('h:m A', strtotime($agent->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($agent->created_at)),
                ];
            });

            return response()->json([
                'total_count' => $totalCount,
                'properties' => $propertiesData,
                'projects' => $projectsData,
                'agents' => $agentsData
            ], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' on line ' . $th->getLine()], 500);
        }
    }



##########################



 /**
     * Build the base property query used by both filter aggregation and property listing.
     */
    protected function buildBasePropertyQuery(Request $request)
    {
        $q = PropertyList::query()->where('live_status', 'Approve');

        if ($request->filled('country_id')) {
            $q->where('country_id', $request->country_id);
        }
        if ($request->filled('state_id')) {
            $q->where('state_id', $request->state_id);
        }
        if ($request->filled('city_id')) {
            $q->where('city_id', $request->city_id);
        }
        if ($request->filled('area_locality')) {
            $q->where('area_locality', $request->area_locality);
        }

        // purpose filter (buy/rent/pgco-living/plot_land/project)
        if ($request->filled('purpose')) {
            $purpose = DB::table('purposes')->where('slug', $request->purpose)->first();
            if ($purpose) {
                $q->where('purpose_id', $purpose->id);
            }
        }

        // property_id
        if ($request->filled('property_id')) {
            $q->where('property_id', $request->property_id);
        }

        // property_type_id may be CSV
        if ($request->filled('property_type_id')) {
            $ids = explode(',', $request->property_type_id);
            $q->where(function ($sub) use ($ids) {
                foreach ($ids as $id) {
                    $id = trim($id);
                    if ($id === '') continue;
                    $sub->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
                }
            });
        }

        // property_status_id may be CSV
        if ($request->filled('property_status_id')) {
            $ids = explode(',', $request->property_status_id);
            $ids = array_filter(array_map('trim', $ids));
            if (!empty($ids)) $q->whereIn('property_status_id', $ids);
        }

        // posted_by -> agent/owner
        if ($request->filled('posted_by')) {
            if ($request->posted_by === 'agent') {
                $q->whereIn('user_id', User::where('role_id', 3)->pluck('id')->toArray());
            } elseif ($request->posted_by === 'owner') {
                $q->whereIn('user_id', User::where('role_id', 2)->pluck('id')->toArray());
            }
        }

        // bhk filter
        if ($request->filled('bhk')) {
            $bhkRequested = $request->get('bhk');
            $matchedIds = $this->propertyIdsByBhk($bhkRequested);
            if (empty($matchedIds)) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('id', $matchedIds);
            }
        }

        return $q;
    }

    /**
     * GET /api/search/get-filterdata-by-search-result
     */
    public function getFilterDataBySearchResult(Request $request)
    {
        try {
            $baseQuery = $this->buildBasePropertyQuery($request);

            $propertyIds = (clone $baseQuery)->pluck('id')->toArray();

            if (empty($propertyIds)) {
                return response()->json([
                    'topLocalities' => [],
                    'budget' => null,
                    'property_type' => [],
                    'bhk' => [],
                    'postedBy' => []
                ], 200);
            }

            // topLocalities
            $topLocalities = (clone $baseQuery)
                ->select('area_locality', DB::raw('COUNT(*) as cnt'))
                ->whereNotNull('area_locality')
                ->groupBy('area_locality')
                ->orderByDesc('cnt')
                ->limit(10)
                ->pluck('area_locality')
                ->filter()
                ->values()
                ->toArray();

            // budget
            $budget = $this->deriveBudgetRange($propertyIds);

            // property types
            $propertyTypeNames = $this->derivePropertyTypes((clone $baseQuery));

            // bhk
            $bhk = $this->deriveBhk($propertyIds);

            // posted by
            $propertiesTable = (new PropertyList)->getTable();
            $postedByRows = DB::table($propertiesTable)
                ->join('users', "{$propertiesTable}.user_id", '=', 'users.id')
                ->whereIn("{$propertiesTable}.id", $propertyIds)
                ->select('users.role_id', DB::raw("COUNT({$propertiesTable}.id) as cnt"))
                ->groupBy('users.role_id')
                ->get();

            $postedBy = $postedByRows->map(function ($row) {
                if ($row->role_id == 3) return 'agent';
                if ($row->role_id == 2) return 'owner';
                return 'admin';
            })->unique()->values()->toArray();

            return response()->json([
                'topLocalities' => $topLocalities,
                'budget' => $budget,
                'property_type' => $propertyTypeNames,
                'bhk' => $bhk,
                'postedBy' => $postedBy,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to generate filter data',
                'error' => $th->getMessage() . ' on line ' . $th->getLine()
            ], 500);
        }
    }

    /**
     * POST /api/search/apply-filters
     */
    public function applyFilters(Request $request)
    {
        try {
            $q = $this->buildBasePropertyQuery($request)
                ->with([
                    'country','state','city','user',
                    'propertyType', 'purpose', 'property',
                    'propertystatus', 'project', 'customFieldValues.customField',
                    'customFieldValues.customFieldOption'
                ])
                ->orderByDesc('created_at');

            if ($request->filled('price_min') && $request->filled('price_max')) {
                $propertyIdsByPrice = $this->propertyIdsByPriceRange($request->price_min, $request->price_max);
                if (!empty($propertyIdsByPrice)) {
                    $q->whereIn('id', $propertyIdsByPrice);
                } else {
                    return response()->json([
                        'total_count' => 0,
                        'properties' => []
                    ], 200);
                }
            }

            $perPage = (int) $request->get('per_page', 20);
            $page = (int) $request->get('page', 1);

            $paginator = $q->paginate($perPage, ['*'], 'page', $page);
            $properties = $paginator->items();

            return response()->json([
                'total_count' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'properties' => $properties,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to apply filters',
                'error' => $th->getMessage() . ' on line ' . $th->getLine()
            ], 500);
        }
    }

    /* ----------------------- Helper methods ----------------------- */

    protected function deriveBudgetRange(array $propertyIds)
    {
        if (empty($propertyIds)) return null;

        $candidates = [
            ['field_label_slug' => 'property-rent-amount'],
            ['field_label_slug' => 'price'],
            ['field_label' => 'Price'],
        ];

        foreach ($candidates as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_label_slug'])) $cfQuery->where('field_label_slug', $cond['field_label_slug']);
            if (isset($cond['field_label'])) $cfQuery->where('field_label', $cond['field_label']);
            $cf = $cfQuery->first();
            if (!$cf) continue;

            $values = CustomFieldValue::where('custom_field_id', $cf->id)
                ->whereIn('properties_listing_id', $propertyIds)
                ->whereRaw("field_meta_value REGEXP '^[0-9]+(\\.[0-9]+)?$'")
                ->pluck('field_meta_value')
                ->map(function ($v) { return (float) $v; });

            if ($values->isEmpty()) continue;

            return [
                'min' => (float) $values->min(),
                'max' => (float) $values->max()
            ];
        }

        return null;
    }

    protected function propertyIdsByPriceRange($min, $max)
    {
        $min = (float)$min;
        $max = (float)$max;

        $candidates = [
            ['field_label_slug' => 'property-rent-amount'],
            ['field_label_slug' => 'price'],
            ['field_label' => 'Price']
        ];

        foreach ($candidates as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_label_slug'])) $cfQuery->where('field_label_slug', $cond['field_label_slug']);
            if (isset($cond['field_label'])) $cfQuery->where('field_label', $cond['field_label']);
            $cf = $cfQuery->first();
            if (!$cf) continue;

            $ids = CustomFieldValue::where('custom_field_id', $cf->id)
                ->whereRaw('field_meta_value REGEXP "^[0-9]+(\\.[0-9]+)?$"')
                ->whereBetween(DB::raw('CAST(field_meta_value AS DECIMAL(20,2))'), [$min, $max])
                ->pluck('properties_listing_id')
                ->unique()
                ->values()
                ->toArray();

            if (!empty($ids)) {
                return $ids;
            }
        }

        return [];
    }

    protected function derivePropertyTypes($baseQuery)
    {
        $raw = $baseQuery->pluck('property_type_id')->filter()->unique()->toArray();
        $ids = [];
        foreach ($raw as $csv) {
            foreach (explode(',', $csv) as $id) {
                $trim = trim($id);
                if ($trim !== '') $ids[(int)$trim] = true;
            }
        }
        $ids = array_keys($ids);
        if (empty($ids)) return [];

        $names = PropertyType::whereIn('id', $ids)->pluck('name')->unique()->values()->toArray();
        return $names;
    }

    protected function deriveBhk(array $propertyIds)
    {
        if (empty($propertyIds)) return [];

        $possible = [
            ['field_label' => 'Bedrooms'],
            ['field_label_slug' => 'bedrooms'],
            ['field_label' => 'BHK'],
            ['field_label_slug' => 'bhk'],
        ];

        $valuesCollected = collect();

        foreach ($possible as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_label_slug'])) $cfQuery->where('field_label_slug', $cond['field_label_slug']);
            if (isset($cond['field_label'])) $cfQuery->where('field_label', $cond['field_label']);
            $cf = $cfQuery->first();
            if (!$cf) continue;

            $rawValues = CustomFieldValue::where('custom_field_id', $cf->id)
                ->whereIn('properties_listing_id', $propertyIds)
                ->pluck('field_meta_value')
                ->filter()
                ->map(function ($v) {
                    if (is_array($v)) return $v;
                    $vStr = (string) $v;

                    if (Str::startsWith($vStr, '[') && Str::endsWith($vStr, ']')) {
                        $decoded = json_decode($vStr, true);
                        if (is_array($decoded)) return $decoded;
                    }

                    if (strpos($vStr, ',') !== false) {
                        return array_map('trim', explode(',', $vStr));
                    }

                    return trim($vStr);
                })
                ->flatten();

            if ($rawValues->isEmpty()) continue;

            foreach ($rawValues->unique() as $val) {
                $val = trim((string)$val);
                if ($val === '') continue;

                if (preg_match('/\d+/', $val, $m)) {
                    $num = (int)$m[0];
                    if ($num > 0) {
                        $valuesCollected->push((string)$num);
                        continue;
                    }
                }

                $valuesCollected->push($val);
            }

            if ($valuesCollected->isNotEmpty()) break;
        }

        $numeric = $valuesCollected->filter(fn($v) => is_numeric($v))
            ->map('intval')->unique()->sort()->values()->map(fn($v)=> (string)$v);

        $nonNumeric = $valuesCollected->filter(fn($v) => !is_numeric($v))
            ->unique()->sort()->values();

        return $numeric->merge($nonNumeric)->values()->toArray();
    }

    protected function propertyIdsByBhk($bhkRequested)
    {
        if (empty($bhkRequested) && $bhkRequested !== '0') return [];

        if (!is_array($bhkRequested)) {
            $bhkRequested = array_filter(array_map('trim', explode(',', (string)$bhkRequested)));
        }

        if (empty($bhkRequested)) return [];

        $possible = [
            ['field_label' => 'Bedrooms'],
            ['field_label_slug' => 'bedrooms'],
            ['field_label' => 'BHK'],
            ['field_label_slug' => 'bhk'],
        ];

        $matchedPropertyIds = collect();

        foreach ($possible as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_label_slug'])) $cfQuery->where('field_label_slug', $cond['field_label_slug']);
            if (isset($cond['field_label'])) $cfQuery->where('field_label', $cond['field_label']);
            $cf = $cfQuery->first();
            if (!$cf) continue;

            $q = CustomFieldValue::where('custom_field_id', $cf->id);

            $q->where(function ($sub) use ($bhkRequested) {
                foreach ($bhkRequested as $req) {
                    $req = trim((string)$req);
                    if ($req === '') continue;

                    if (is_numeric($req)) {
                        $sub->orWhereRaw('field_meta_value REGEXP ?', [$this->regexNumberToken($req)]);
                        $sub->orWhere('field_meta_value', 'like', "%{$req}%");
                    } else {
                        $sub->orWhere('field_meta_value', 'like', "%{$req}%");
                    }
                }
            });

            $ids = $q->pluck('properties_listing_id')->filter()->unique()->values();
            if ($ids->isNotEmpty()) {
                $matchedPropertyIds = $matchedPropertyIds->merge($ids);
            }
        }

        return $matchedPropertyIds->unique()->values()->toArray();
    }

    protected function regexNumberToken($num)
    {
        $escaped = preg_quote((string)$num, '/');
        return '(^|[^0-9])' . $escaped . '([^0-9]|$)';
    }




    ################################################


    //   // =======================
    // // 1. Global Search API
   

    //    public function globalSearch(Request $request)
    // {
    //     try {
    //         $baseURL = config('app.url');
    //         $AuthUser = auth('sanctum')->user();

    //         // --- Query Properties ---
    //         $propertiesQuery = PropertyList::with([
    //             'country','state','city','user',
    //             'propertyType','purpose','property',
    //             'propertystatus','project',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption'
    //         ])->where('live_status','Approve');

    //         // Apply location filters
    //         foreach(['country_id','state_id','city_id','area_locality'] as $field){
    //             if($request->filled($field)) $propertiesQuery->where($field,$request->$field);
    //         }

    //         // Keyword filter
    //         if($request->filled('keyword')){
    //             $propertyIds = Keyword::where('keyword', $request->keyword)
    //                 ->whereNotNull('property_id')
    //                 ->pluck('property_id');
    //             $propertiesQuery->whereIn('id', $propertyIds);
    //         }

    //         // Purpose filter
    //         if($request->filled('purpose')){
    //             $purpose = Purpose::where('slug', $request->purpose)->first();
    //             if($purpose) $propertiesQuery->where('purpose_id', $purpose->id);
    //         }

    //         // Apply purpose-specific filters dynamically
    //         $this->applyPurposeFilters($propertiesQuery, $request);

    //         $properties = $propertiesQuery->get();

    //         // --- Query Projects ---
    //         $projectsQuery = ProjectList::with([
    //             'country','state','city','user',
    //             'propertyType','purpose','property',
    //             'propertystatus','developer',
    //             'customFieldValues.customField',
    //             'customFieldValues.customFieldOption'
    //         ])->where('live_status','Approve');

    //         foreach(['country_id','state_id','city_id','area_locality'] as $field){
    //             if($request->filled($field)) $projectsQuery->where($field,$request->$field);
    //         }

    //         $projects = $projectsQuery->get();

    //         // --- Query Agents ---
    //         $agentsQuery = User::with(['role','userDetails.country','userDetails.state','userDetails.city'])
    //             ->where('role_id',3)
    //             ->where('isapproved',1);

    //         foreach(['country_id','state_id','city_id','area_locality'] as $field){
    //             if($request->filled($field)){
    //                 $agentsQuery->whereHas('properties', function($q) use($field,$request){
    //                     $q->where($field,$request->$field);
    //                 });
    //             }
    //         }

    //         $agents = $agentsQuery->withCount(['properties'=>function($q) use($request){
    //             foreach(['country_id','state_id','city_id','area_locality'] as $field){
    //                 if(request()->filled($field)){
    //                     $q->where($field,request()->$field);
    //                 }
    //             }
    //         }])->orderBy('properties_count','desc')->limit(100)->get();

    //         // Format all data
    //         return response()->json([
    //             'total_properties' => $properties->count(),
    //             'properties' => $this->formatProperties($properties, $baseURL),
    //             'total_projects' => $projects->count(),
    //             'projects' => $this->formatProjects($projects, $baseURL),
    //             'total_agents' => $agents->count(),
    //             'agents' => $this->formatAgents($agents, $AuthUser, $baseURL)
    //         ],200);

    //     } catch(\Throwable $th){
    //         return response()->json(['error'=>$th->getMessage().' on line '.$th->getLine()],500);
    //     }
    // }

    // // =======================
    // // 2. Global Filters API
    // // =======================
    // public function globalFilters(Request $request)
    // {
    //     try {
    //         $propertiesQuery = PropertyList::where('live_status','Approve');
    //         foreach(['country_id','state_id','city_id','area_locality','purpose','keyword'] as $field){
    //             if($request->filled($field)){
    //                 if($field=='keyword'){
    //                     $ids = Keyword::where('keyword',$request->keyword)->pluck('property_id');
    //                     $propertiesQuery->whereIn('id',$ids);
    //                 } elseif($field == 'purpose'){
    //                     // purpose slug 
    //                     $purpose = Purpose::where('slug', $request->purpose)->first();
    //                     if($purpose) {
    //                         $propertiesQuery->where('purpose_id', $purpose->id);
    //                     }
    //                 } else {
    //                     $propertiesQuery->where($field, $request->$field);
    //                 }
    //             }
    //         }

    //         $propertyIds = $propertiesQuery->pluck('id');

    //         $filters = [];

    //         // Property types with id + name
    //         $typeIds = PropertyList::whereIn('id',$propertyIds)
    //             ->pluck('property_type_id')
    //             ->map(fn($v)=>explode(',',$v))->flatten()->unique()->values();

    //         $filters['property_types'] = PropertyType::whereIn('id',$typeIds)
    //             ->get(['id','name']);

    //         // Property statuses with id + name
    //         $statusIds = PropertyList::whereIn('id',$propertyIds)
    //             ->pluck('property_status_id')->unique()->values();

    //         $filters['property_statuses'] = Status::whereIn('id',$statusIds)
    //             ->get(['id','name']);

    //         // Price range from custom fields
    //         $priceFieldIds = CustomField::whereIn('field_label', [
    //             'property_rent_amount','plot_price','project_price'
    //         ])->pluck('id');

    //         $priceValues = CustomFieldValue::whereIn('custom_field_id', $priceFieldIds)
    //             ->whereIn('properties_listing_id', $propertyIds)
    //             ->pluck('field_meta_value')
    //             ->map(fn($v) => (float)$v);

    //         $filters['price_min'] = $priceValues->min() ?? 0;
    //         $filters['price_max'] = $priceValues->max() ?? 0;

    //         // Bedrooms filter
    //         $bedroomField = CustomField::where('field_label', 'bedrooms')->first();
    //         if ($bedroomField) {
    //             $bedroomValues = CustomFieldValue::where('custom_field_id', $bedroomField->id)
    //                 ->whereIn('properties_listing_id', $propertyIds)
    //                 ->pluck('field_meta_value')
    //                 ->unique()
    //                 ->values();
    //             $filters['bedrooms'] = $bedroomValues;
    //         }

    //         // Custom fields with id + label + values
    //         $customFieldValues = CustomFieldValue::whereIn('properties_listing_id',$propertyIds)
    //             ->with('customField:id,field_label')
    //             ->get();

    //         $filters['custom_fields'] = $customFieldValues->groupBy('custom_field_id')
    //             ->map(function($group){
    //                 return [
    //                     'id' => $group->first()->customField->id,
    //                     'label' => $group->first()->customField->field_label,
    //                     'values' => $group->pluck('field_meta_value')->unique()->values()
    //                 ];
    //             })->values();

    //         return response()->json(['status'=>true,'filters'=>$filters],200);

    //     } catch(\Throwable $th){
    //         return response()->json([
    //             'status'=>false,
    //             'error'=>$th->getMessage().' on line '.$th->getLine()
    //         ],500);
    //     }
    // }

    // // =======================
    // // 3. Apply Filter API
    // // =======================
    // public function applyFilter(Request $request)
    // {
    //     try {
    //         $request->merge($request->filters ?? []); // merge selected filters dynamically
    //         return $this->globalSearch($request); // reuse globalSearch function
    //     } catch(\Throwable $th){
    //         return response()->json(['error'=>$th->getMessage().' on line '.$th->getLine()],500);
    //     }
    // }

    // // =======================
    // // Helper Functions
    // // =======================
    // private function applyPurposeFilters(&$query, $request)
    // {
    //     if(!$request->filled('purpose')) return;

    //     $purpose = $request->purpose;

    //     // Property Type filter
    //     if($request->filled('property_type_id')){
    //         $ids = explode(',',$request->property_type_id);
    //         $query->where(function($q) use($ids){
    //             foreach($ids as $id) $q->orWhereRaw("FIND_IN_SET(?,property_type_id)",[$id]);
    //         });
    //     }

    //     // Property Status filter
    //     if($request->filled('property_status_id')){
    //         $ids = explode(',',$request->property_status_id);
    //         $query->whereIn('property_status_id',$ids);
    //     }

    //     // Posted By filter
    //     if($request->filled('posted_by')){
    //         $user_ids = [];
    //         if($request->posted_by=='agent') $user_ids = User::where('role_id',3)->pluck('id')->toArray();
    //         if($request->posted_by=='owner') $user_ids = User::where('role_id',2)->pluck('id')->toArray();
    //         $query->whereIn('user_id',$user_ids);
    //     }

    //     // Bedrooms filter
    //     if ($request->filled('bedrooms')) {
    //         $cf = CustomField::where('field_label', 'bedrooms')->first();
    //         if ($cf) {
    //             $ids = CustomFieldValue::where('custom_field_id', $cf->id)
    //                 ->whereIn('field_meta_value', explode(',', $request->bedrooms))
    //                 ->pluck('properties_listing_id')
    //                 ->toArray();
    //             $query->whereIn('id', $ids);
    //         }
    //     }

    //     // Price filters based on purpose
    //     if($purpose=='buy' && $request->filled('property_price_low') && $request->filled('property_price_high')){
    //         $cf = CustomField::where('field_label','property_rent_amount')->first();
    //         if($cf){
    //             $ids = CustomFieldValue::where('custom_field_id',$cf->id)
    //                 ->whereBetween('field_meta_value',[$request->property_price_low,$request->property_price_high])
    //                 ->pluck('properties_listing_id')->toArray();
    //             $query->whereIn('id',$ids);
    //         }
    //     }

    //     if($purpose=='rent' && $request->filled('rent_price_low') && $request->filled('rent_price_high')){
    //         $cf = CustomField::where('field_label','property_rent_amount')->first();
    //         if($cf){
    //             $ids = CustomFieldValue::where('custom_field_id',$cf->id)
    //                 ->whereBetween('field_meta_value',[$request->rent_price_low,$request->rent_price_high])
    //                 ->pluck('properties_listing_id')->toArray();
    //             $query->whereIn('id',$ids);
    //         }
    //     }

    //     // Similar logic for pgco-living, plot_land, project
    //     if($purpose=='pgco-living'){
    //         if($request->filled('pg_rent_price_low') && $request->filled('pg_rent_price_high')){
    //             $cf = CustomField::where('field_label','property_rent_amount')->first();
    //             if($cf){
    //                 $ids = CustomFieldValue::where('custom_field_id',$cf->id)
    //                     ->whereBetween('field_meta_value',[$request->pg_rent_price_low,$request->pg_rent_price_high])
    //                     ->pluck('properties_listing_id')->toArray();
    //                 $query->whereIn('id',$ids);
    //             }
    //         }
    //         if($request->filled('availabel_for')){
    //             $cf = CustomField::where('field_label','listing_available_for')->first();
    //             if($cf){
    //                 $ids = CustomFieldValue::where('custom_field_id',$cf->id)
    //                     ->whereRaw("FIND_IN_SET(?,field_meta_value)",[$request->availabel_for])
    //                     ->pluck('properties_listing_id')->toArray();
    //                 $query->whereIn('id',$ids);
    //             }
    //         }
    //     }

    //     if($purpose=='plot_land'){
    //         if($request->filled('plot_price_low') && $request->filled('plot_price_high')){
    //             $cf = CustomField::where('field_label','property_rent_amount')->first();
    //             if($cf){
    //                 $ids = CustomFieldValue::where('custom_field_id',$cf->id)
    //                     ->whereBetween('field_meta_value',[$request->plot_price_low,$request->plot_price_high])
    //                     ->pluck('properties_listing_id')->toArray();
    //                 $query->whereIn('id',$ids);
    //             }
    //         }
    //     }

    //     if($purpose=='project'){
    //         if($request->filled('project_price_low') && $request->filled('project_price_high')){
    //             $cf = CustomField::where('field_label','property_rent_amount')->first();
    //             if($cf){
    //                 $ids = CustomFieldValue::where('custom_field_id',$cf->id)
    //                     ->whereBetween('field_meta_value',[$request->project_price_low,$request->project_price_high])
    //                     ->pluck('properties_listing_id')->toArray();
    //                 $query->whereIn('id',$ids);
    //             }
    //         }
    //     }
    // }

   
    // private function formatProperties($properties, $baseURL)
    // {
    //     return $properties->map(function($p) use($baseURL){

    //         // ---- Custom Fields ----
    //         $customFields = $p->customFieldValues->map(function($cfv) use($baseURL){
    //             $value = $cfv->field_meta_value;
    //             if($cfv->customField->field_type=='checkbox'){
    //                 $value = explode(',', $value);
    //             } elseif($cfv->customField->field_type=='media'){
    //                 $value = collect(json_decode($value))->map(fn($v)=>$baseURL.'/uploads/media/'.$v);
    //             }
    //             return [
    //                 'id'    => $cfv->customField->id,
    //                 'type'  => $cfv->customField->field_type,
    //                 'value' => $value,
    //                 'label' => $cfv->customField->field_label
    //             ];
    //         });

    //         // ---- Property Types (multiple ids -> names) ----
    //         $propertyTypeIds = explode(',', $p->property_type_id ?? '');
    //         $propertyTypeNames = \App\Models\PropertyType::whereIn('id', $propertyTypeIds)
    //             ->pluck('name')->toArray();

    //         return [
    //             'id'                    => $p->id,
    //             'property_unique_id'    => $p->property_unique_id,
    //             'property_name'         => $p->name,
    //             'description'           => $p->description,
    //             'country_id'            => $p->country_id,
    //             'country'               => $p->country ? ['id'=>$p->country->id, 'name'=>$p->country->name] : null,
    //             'state_id'              => $p->state_id,
    //             'state'                 => $p->state ? ['id'=>$p->state->id, 'name'=>$p->state->name] : null,
    //             'city_id'               => $p->city_id,
    //             'city'                  => $p->city ? ['id'=>$p->city->id, 'name'=>$p->city->name] : null,
    //             'property_address'      => $p->property_address,
    //             'area_locality'         => $p->area_locality,
    //             'live_status'           => $p->live_status,
    //             'status_reason'         => $p->status_reason,
    //             'user_id'               => $p->user_id,
    //             'listed_by'             => $p->listed_by,
    //             'featured_image'        => $p->featured_image ? $baseURL.$p->featured_image : null,

    //             // Purpose
    //             'purpose_id'            => $p->purpose_id,
    //             'purpose_id_name'       => $p->purpose->name ?? null,

    //             // Property
    //             'property_id'           => $p->property_id,
    //             'property_id_name'      => $p->property->name ?? null,

    //             // Property Status
    //             'property_status_id'    => $p->property_status_id,
    //             'property_status_id_name'=> $p->propertystatus->name ?? null,

    //             // Property Type (multiple)
    //             'property_type_id'      => $p->property_type_id,
    //             'property_type_id_name' => implode(', ', $propertyTypeNames),

    //             // Project
    //             'project_id'            => $p->project_id,
    //             'project_id_name'       => $p->project->name ?? null,

    //             'total_view'            => $p->total_view ?? 0,
    //             'date'                  => $p->created_at ? $p->created_at->format('d m Y') : null,
    //             'time'                  => $p->created_at ? $p->created_at->format('h:i A') : null,
    //             'timestamp'             => $p->created_at ? $p->created_at->format('d m Y h:i A') : null,

    //             // Custom Fields
    //             'custom_field_values'   => $customFields
    //         ];
    //     });
    // }


    


    // private function formatProjects($projects, $baseURL)
    // {
    //     return $projects->map(function($p) use($baseURL){

    //         // Custom Fields
    //         $customFields = $p->customFieldValues->map(function($cfv) use($baseURL){
    //             $value = $cfv->field_meta_value;
    //             if($cfv->customField->field_type=='checkbox'){
    //                 $value = explode(',', $value);
    //             } elseif($cfv->customField->field_type=='media'){
    //                 $value = collect(json_decode($value))->map(fn($v)=>$baseURL.'/uploads/media/'.$v);
    //             }
    //             return [
    //                 'id' => $cfv->customField->id,
    //                 'type' => $cfv->customField->field_type,
    //                 'value' => $value,
    //                 'label' => $cfv->customField->field_label
    //             ];
    //         });

    //         // Property Types (multiple)
    //         $propertyTypeIds = explode(',', $p->property_type_id ?? '');
    //         $propertyTypeNames = \App\Models\PropertyType::whereIn('id', $propertyTypeIds)
    //             ->pluck('name')->toArray();

    //         return [
    //             'id'                    => $p->id,
    //             'project_name'          => $p->name,
    //             'description'           => $p->description,
    //             'country_id'            => $p->country_id,
    //             'country'               => $p->country ? ['id'=>$p->country->id, 'name'=>$p->country->name] : null,
    //             'state_id'              => $p->state_id,
    //             'state'                 => $p->state ? ['id'=>$p->state->id, 'name'=>$p->state->name] : null,
    //             'city_id'               => $p->city_id,
    //             'city'                  => $p->city ? ['id'=>$p->city->id, 'name'=>$p->city->name] : null,
    //             'area_locality'         => $p->area_locality,
    //             'live_status'           => $p->live_status,
    //             'status_reason'         => $p->status_reason,
    //             'user_id'               => $p->user_id,
    //             'listed_by'             => $p->listed_by,
    //             'featured_image'        => $p->featured_image ? $baseURL.$p->featured_image : null,

    //             // Purpose
    //             'purpose_id'            => $p->purpose_id,
    //             'purpose_id_name'       => $p->purpose->name ?? null,

    //             // Property
    //             'property_id'           => $p->property_id,
    //             'property_id_name'      => $p->property->name ?? null,

    //             // Property Status
    //             'property_status_id'    => $p->property_status_id,
    //             'property_status_id_name'=> $p->propertystatus->name ?? null,

    //             // Property Type
    //             'property_type_id'      => $p->property_type_id,
    //             'property_type_id_name' => implode(', ', $propertyTypeNames),

    //             // Developer
    //             'developer_id'          => $p->developer_id,
    //             'developer_name'        => $p->developer->name ?? null,

    //             'total_view'            => $p->total_view ?? 0,
    //             'date'                  => $p->created_at ? $p->created_at->format('d m Y') : null,
    //             'time'                  => $p->created_at ? $p->created_at->format('h:i A') : null,
    //             'timestamp'             => $p->created_at ? $p->created_at->format('d m Y h:i A') : null,

    //             'custom_field_values'   => $customFields
    //         ];
    //     });
    // }


    

    // private function formatAgents($agents, $AuthUser, $baseURL)
    // {
    //     return $agents->map(function($a) use($AuthUser){

    //         $email = $a->email; 
    //         $phone = $a->phone;

    //         // Mask email & phone for unauthenticated users
    //         if(!$AuthUser){
    //             if($email) $email = preg_replace('/(?<=.{2}).(?=.*@)/','*',$email);
    //             if($phone) $phone = substr($phone,0,3).'****'.substr($phone,-3);
    //         }

    //         return [
    //             'id'            => $a->id,
    //             'name'          => $a->first_name.' '.$a->last_name,
    //             'email'         => $email,
    //             'phone'         => $phone,
    //             'role_id'       => $a->role_id,
    //             'role_name'     => $a->role->name ?? null,
    //             'country_id'    => $a->userDetails->country_id ?? null,
    //             'country_name'  => $a->userDetails->country->name ?? null,
    //             'state_id'      => $a->userDetails->state_id ?? null,
    //             'state_name'    => $a->userDetails->state->name ?? null,
    //             'city_id'       => $a->userDetails->city_id ?? null,
    //             'city_name'     => $a->userDetails->city->name ?? null,
    //             'profile_photo' => $a->userDetails->profile_photo ? url($a->userDetails->profile_photo) : null,
    //             'properties_count' => $a->properties_count,
    //             // 'properties'    => $a->properties->map(function($p) use($baseURL){
    //             //     return [
    //             //         'id' => $p->id,
    //             //         'name' => $p->name,
    //             //         'live_status' => $p->live_status,
    //             //         'property_type_id' => $p->property_type_id,
    //             //         'property_type_id_name' => implode(', ', \App\Models\PropertyType::whereIn('id', explode(',', $p->property_type_id))->pluck('name')->toArray()),
    //             //     ];
    //             // })
    //         ];
    //     });
    // }


    public function globalSearch(Request $request)
{
    try {
        $baseURL = config('app.url');
        $AuthUser = auth('sanctum')->user();

        // --- Query Properties ---
        $propertiesQuery = PropertyList::with([
            'country','state','city','user',
            'propertyType','purpose','property',
            'propertystatus','project',
            'customFieldValues.customField',
            'customFieldValues.customFieldOption'
        ])->where('live_status','Approve');

        // Apply location filters
        foreach(['country_id','state_id','city_id','area_locality'] as $field){
            if($request->filled($field)) $propertiesQuery->where($field,$request->$field);
        }

        // Keyword filter
        if($request->filled('keyword')){
            $propertyIds = Keyword::where('keyword', $request->keyword)
                ->whereNotNull('property_id')
                ->pluck('property_id');
            $propertiesQuery->whereIn('id', $propertyIds);
        }

        // Purpose filter
        if($request->filled('purpose')){
            $purpose = Purpose::where('slug', $request->purpose)->first();
            if($purpose) $propertiesQuery->where('purpose_id', $purpose->id);
        }


        // --- BEDROOMS FILTER DIRECTLY IN GLOBAL SEARCH ---
        if ($request->filled('bedrooms')) {
            $bedroomValues = explode(',', $request->bedrooms);
            $cf = CustomField::where('field_label', 'bedrooms')->first();
            if ($cf) {
                $propertiesQuery->whereHas('customFieldValues', function($q) use($cf, $bedroomValues) {
                    $q->where('custom_field_id', $cf->id)
                      ->where(function($subQuery) use($bedroomValues) {
                          foreach($bedroomValues as $value) {
                              $subQuery->orWhereRaw("FIND_IN_SET(?, field_meta_value)", [trim($value)]);
                          }
                      });
                });
            }
        }


        // Apply purpose-specific filters dynamically
        $this->applyPurposeFilters($propertiesQuery, $request);

        $properties = $propertiesQuery->get();

        // --- Query Projects ---
        $projectsQuery = ProjectList::with([
            'country','state','city','user',
            'propertyType','purpose','property',
            'propertystatus','developer',
            'customFieldValues.customField',
            'customFieldValues.customFieldOption'
        ])->where('live_status','Approve');

        foreach(['country_id','state_id','city_id','area_locality'] as $field){
            if($request->filled($field)) $projectsQuery->where($field,$request->$field);
        }

        $projects = $projectsQuery->get();

        // --- Query Agents ---
        $agentsQuery = User::with(['role','userDetails.country','userDetails.state','userDetails.city'])
            ->where('role_id',3)
            ->where('isapproved',1);

        foreach(['country_id','state_id','city_id','area_locality'] as $field){
            if($request->filled($field)){
                $agentsQuery->whereHas('properties', function($q) use($field,$request){
                    $q->where($field,$request->$field);
                });
            }
        }

        $agents = $agentsQuery->withCount(['properties'=>function($q) use($request){
            foreach(['country_id','state_id','city_id','area_locality'] as $field){
                if(request()->filled($field)){
                    $q->where($field,request()->$field);
                }
            }
        }])->orderBy('properties_count','desc')->limit(100)->get();

        // Format all data
        return response()->json([
            'total_properties' => $properties->count(),
            'properties' => $this->formatProperties($properties, $baseURL),
            'total_projects' => $projects->count(),
            'projects' => $this->formatProjects($projects, $baseURL),
            'total_agents' => $agents->count(),
            'agents' => $this->formatAgents($agents, $AuthUser, $baseURL)
        ],200);

    } catch(\Throwable $th){
        return response()->json(['error'=>$th->getMessage().' on line '.$th->getLine()],500);
    }
}

// =======================
// 2. Global Filters API
// =======================
public function globalFilters(Request $request)
{
    try {
        $propertiesQuery = PropertyList::where('live_status','Approve');
        foreach(['country_id','state_id','city_id','area_locality','purpose','keyword'] as $field){
            if($request->filled($field)){
                if($field=='keyword'){
                    $ids = Keyword::where('keyword',$request->keyword)->pluck('property_id');
                    $propertiesQuery->whereIn('id',$ids);
                } elseif($field == 'purpose'){
                    // purpose slug 
                    $purpose = Purpose::where('slug', $request->purpose)->first();
                    if($purpose) {
                        $propertiesQuery->where('purpose_id', $purpose->id);
                    }
                } else {
                    $propertiesQuery->where($field, $request->$field);
                }
            }
        }

        $propertyIds = $propertiesQuery->pluck('id');

        $filters = [];

        // Property types with id + name
        $typeIds = PropertyList::whereIn('id',$propertyIds)
            ->pluck('property_type_id')
            ->map(fn($v)=>explode(',',$v))->flatten()->unique()->values();

        $filters['property_types'] = PropertyType::whereIn('id',$typeIds)
            ->get(['id','name']);

        // Property statuses with id + name
        $statusIds = PropertyList::whereIn('id',$propertyIds)
            ->pluck('property_status_id')->unique()->values();

        $filters['property_statuses'] = Status::whereIn('id',$statusIds)
            ->get(['id','name']);

        // Price range from custom fields
        $priceFieldIds = CustomField::whereIn('field_label', [
            'property_rent_amount','plot_price','project_price'
        ])->pluck('id');

        $priceValues = CustomFieldValue::whereIn('custom_field_id', $priceFieldIds)
            ->whereIn('properties_listing_id', $propertyIds)
            ->pluck('field_meta_value')
            ->map(fn($v) => (float)$v);

        $filters['price_min'] = $priceValues->min() ?? 0;
        $filters['price_max'] = $priceValues->max() ?? 0;

        // Bedrooms filter - FIXED: For comma-separated values
        $bedroomField = CustomField::where('field_label', 'bedrooms')->first();
        if ($bedroomField) {
            $bedroomValues = CustomFieldValue::where('custom_field_id', $bedroomField->id)
                ->whereIn('properties_listing_id', $propertyIds)
                ->pluck('field_meta_value')
                ->flatMap(function($value) {
                    // Split comma-separated values and trim
                    return array_map('trim', explode(',', $value));
                })
                ->filter(fn($v) => !empty($v)) // Remove empty values
                ->unique()
                ->sort()
                ->values();
            
            $filters['bedrooms'] = $bedroomValues;
        }

        // Custom fields with id + label + values
        $customFieldValues = CustomFieldValue::whereIn('properties_listing_id',$propertyIds)
            ->with('customField:id,field_label')
            ->get();

        $filters['custom_fields'] = $customFieldValues->groupBy('custom_field_id')
            ->map(function($group){
                return [
                    'id' => $group->first()->customField->id,
                    'label' => $group->first()->customField->field_label,
                    'values' => $group->pluck('field_meta_value')->unique()->values()
                ];
            })->values();

        return response()->json(['status'=>true,'filters'=>$filters],200);

    } catch(\Throwable $th){
        return response()->json([
            'status'=>false,
            'error'=>$th->getMessage().' on line '.$th->getLine()
        ],500);
    }
}

// =======================
// 3. Apply Filter API
// =======================
public function applyFilter(Request $request)
{
    try {
        $request->merge($request->filters ?? []); // merge selected filters dynamically
        return $this->globalSearch($request); // reuse globalSearch function
    } catch(\Throwable $th){
        return response()->json(['error'=>$th->getMessage().' on line '.$th->getLine()],500);
    }
}

// =======================
// Helper Functions - FIXED BEDROOMS FILTER
// =======================
private function applyPurposeFilters(&$query, $request)
{
    // Property Type filter - works for all purposes
    if($request->filled('property_type_id')){
        $ids = explode(',',$request->property_type_id);
        $query->where(function($q) use($ids){
            foreach($ids as $id) $q->orWhereRaw("FIND_IN_SET(?,property_type_id)",[$id]);
        });
    }

    // Property Status filter - works for all purposes
    if($request->filled('property_status_id')){
        $ids = explode(',',$request->property_status_id);
        $query->whereIn('property_status_id',$ids);
    }

    // Posted By filter - works for all purposes
    if($request->filled('posted_by')){
        $user_ids = [];
        if($request->posted_by=='agent') $user_ids = User::where('role_id',3)->pluck('id')->toArray();
        if($request->posted_by=='owner') $user_ids = User::where('role_id',2)->pluck('id')->toArray();
        $query->whereIn('user_id',$user_ids);
    }

    // Bedrooms filter - FIXED: For comma-separated values like "1 BHK, 2BHK"
    if ($request->filled('bedrooms')) {
        $bedroomValues = explode(',', $request->bedrooms);
        $cf = CustomField::where('field_label', 'bedrooms')->first();
        if ($cf) {
            $query->whereHas('customFieldValues', function($q) use($cf, $bedroomValues) {
                $q->where('custom_field_id', $cf->id)
                  ->where(function($subQuery) use($bedroomValues) {
                      foreach($bedroomValues as $value) {
                          $subQuery->orWhereRaw("FIND_IN_SET(?, field_meta_value)", [trim($value)]);
                      }
                  });
            });
        }
    }

    if(!$request->filled('purpose')) return;

    $purpose = $request->purpose;

    // Price filters based on purpose
    if($purpose=='buy' && $request->filled('property_price_low') && $request->filled('property_price_high')){
        $cf = CustomField::where('field_label','property_rent_amount')->first();
        if($cf){
            $query->whereHas('customFieldValues', function($q) use($cf, $request) {
                $q->where('custom_field_id', $cf->id)
                  ->whereBetween('field_meta_value',[$request->property_price_low,$request->property_price_high]);
            });
        }
    }

    if($purpose=='rent' && $request->filled('rent_price_low') && $request->filled('rent_price_high')){
        $cf = CustomField::where('field_label','property_rent_amount')->first();
        if($cf){
            $query->whereHas('customFieldValues', function($q) use($cf, $request) {
                $q->where('custom_field_id', $cf->id)
                  ->whereBetween('field_meta_value',[$request->rent_price_low,$request->rent_price_high]);
            });
        }
    }

    // Similar logic for other purposes...
    if($purpose=='pgco-living'){
        if($request->filled('pg_rent_price_low') && $request->filled('pg_rent_price_high')){
            $cf = CustomField::where('field_label','property_rent_amount')->first();
            if($cf){
                $query->whereHas('customFieldValues', function($q) use($cf, $request) {
                    $q->where('custom_field_id', $cf->id)
                      ->whereBetween('field_meta_value',[$request->pg_rent_price_low,$request->pg_rent_price_high]);
                });
            }
        }
        if($request->filled('availabel_for')){
            $cf = CustomField::where('field_label','listing_available_for')->first();
            if($cf){
                $query->whereHas('customFieldValues', function($q) use($cf, $request) {
                    $q->where('custom_field_id', $cf->id)
                      ->whereRaw("FIND_IN_SET(?,field_meta_value)",[$request->availabel_for]);
                });
            }
        }
    }

    if($purpose=='plot_land'){
        if($request->filled('plot_price_low') && $request->filled('plot_price_high')){
            $cf = CustomField::where('field_label','property_rent_amount')->first();
            if($cf){
                $query->whereHas('customFieldValues', function($q) use($cf, $request) {
                    $q->where('custom_field_id', $cf->id)
                      ->whereBetween('field_meta_value',[$request->plot_price_low,$request->plot_price_high]);
                });
            }
        }
    }

    if($purpose=='project'){
        if($request->filled('project_price_low') && $request->filled('project_price_high')){
            $cf = CustomField::where('field_label','property_rent_amount')->first();
            if($cf){
                $query->whereHas('customFieldValues', function($q) use($cf, $request) {
                    $q->where('custom_field_id', $cf->id)
                      ->whereBetween('field_meta_value',[$request->project_price_low,$request->project_price_high]);
                });
            }
        }
    }
}

private function formatProperties($properties, $baseURL)
{
    return $properties->map(function($p) use($baseURL){

        // ---- Custom Fields ----
        $customFields = $p->customFieldValues->map(function($cfv) use($baseURL){
            $customField = $cfv->customField;
            $value = $cfv->field_meta_value;
            if($cfv->customField->field_type=='checkbox'){
                $value = explode(',', $value);
            } elseif($cfv->customField->field_type=='media'){
                $value = collect(json_decode($value))->map(fn($v)=>$baseURL.'/uploads/media/'.$v);
            }

             // ✅ Template Handling (added)
            $templateData = optional(optional($customField)->templateValue)?->toArray();
            $templateId = optional(optional($customField)->templateValue)?->id;

            return [
                'id'    => $cfv->customField->id,
                'type'  => $cfv->customField->field_type,
                'value' => $value,
                'label' => $cfv->customField->field_label,
                'placeholder' => $customField->field_placeholder ?? null,
                'template_id' => $templateId,
                'template'    => $templateData,
            ];
        });

        // ---- Property Types (multiple ids -> names) ----
        $propertyTypeIds = explode(',', $p->property_type_id ?? '');
        $propertyTypeNames = \App\Models\PropertyType::whereIn('id', $propertyTypeIds)
            ->pluck('name')->toArray();

        return [
            'id'                    => $p->id,
            'property_unique_id'    => $p->property_unique_id,
            'property_name'         => $p->name,
            'description'           => $p->description,
            'country_id'            => $p->country_id,
            'country'               => $p->country ? ['id'=>$p->country->id, 'name'=>$p->country->name] : null,
            'state_id'              => $p->state_id,
            'state'                 => $p->state ? ['id'=>$p->state->id, 'name'=>$p->state->name] : null,
            'city_id'               => $p->city_id,
            'city'                  => $p->city ? ['id'=>$p->city->id, 'name'=>$p->city->name] : null,
            'property_address'      => $p->property_address,
            'area_locality'         => $p->area_locality,
            'live_status'           => $p->live_status,
            'status_reason'         => $p->status_reason,
            'user_id'               => $p->user_id,
            'listed_by'             => $p->listed_by,
            'featured_image'        => $p->featured_image ? $baseURL.$p->featured_image : null,

            // Purpose
            'purpose_id'            => $p->purpose_id,
            'purpose_id_name'       => $p->purpose->name ?? null,

            // Property
            'property_id'           => $p->property_id,
            'property_id_name'      => $p->property->name ?? null,

            // Property Status
            'property_status_id'    => $p->property_status_id,
            'property_status_id_name'=> $p->propertystatus->name ?? null,

            // Property Type (multiple)
            'property_type_id'      => $p->property_type_id,
            'property_type_id_name' => implode(', ', $propertyTypeNames),

            // Project
            'project_id'            => $p->project_id,
            'project_id_name'       => $p->project->name ?? null,

            'total_view'            => $p->total_view ?? 0,
            'date'                  => $p->created_at ? $p->created_at->format('d m Y') : null,
            'time'                  => $p->created_at ? $p->created_at->format('h:i A') : null,
            'timestamp'             => $p->created_at ? $p->created_at->format('d m Y h:i A') : null,

            // Custom Fields
            'custom_field_values'   => $customFields
        ];
    });
}

private function formatProjects($projects, $baseURL)
{
    return $projects->map(function($p) use($baseURL){

        // Custom Fields
        $customFields = $p->customFieldValues->map(function($cfv) use($baseURL){
            $customField = $cfv->customField;

            $value = $cfv->field_meta_value;
            if($cfv->customField->field_type=='checkbox'){
                $value = explode(',', $value);
            } elseif($cfv->customField->field_type=='media'){
                $value = collect(json_decode($value))->map(fn($v)=>$baseURL.'/uploads/media/'.$v);
            }

            //  Template Handling (added)
            $templateData = optional(optional($customField)->templateValue)?->toArray();
            $templateId = optional(optional($customField)->templateValue)?->id; 


            return [
                'id' => $cfv->customField->id,
                'type' => $cfv->customField->field_type,
                'value' => $value,
                'label' => $cfv->customField->field_label,
                'placeholder' => $customField->field_placeholder ?? null,
                'template_id' => $templateId,
                'template'    => $templateData,
            ];
        });

        // Property Types (multiple)
        $propertyTypeIds = explode(',', $p->property_type_id ?? '');
        $propertyTypeNames = \App\Models\PropertyType::whereIn('id', $propertyTypeIds)
            ->pluck('name')->toArray();

        return [
            'id'                    => $p->id,
            'project_name'          => $p->name,
            'description'           => $p->description,
            'country_id'            => $p->country_id,
            'country'               => $p->country ? ['id'=>$p->country->id, 'name'=>$p->country->name] : null,
            'state_id'              => $p->state_id,
            'state'                 => $p->state ? ['id'=>$p->state->id, 'name'=>$p->state->name] : null,
            'city_id'               => $p->city_id,
            'city'                  => $p->city ? ['id'=>$p->city->id, 'name'=>$p->city->name] : null,
            'area_locality'         => $p->area_locality,
            'live_status'           => $p->live_status,
            'status_reason'         => $p->status_reason,
            'user_id'               => $p->user_id,
            'listed_by'             => $p->listed_by,
            'featured_image'        => $p->featured_image ? $baseURL.$p->featured_image : null,

            // Purpose
            'purpose_id'            => $p->purpose_id,
            'purpose_id_name'       => $p->purpose->name ?? null,

            // Property
            'property_id'           => $p->property_id,
            'property_id_name'      => $p->property->name ?? null,

            // Property Status
            'property_status_id'    => $p->property_status_id,
            'property_status_id_name'=> $p->propertystatus->name ?? null,

            // Property Type
            'property_type_id'      => $p->property_type_id,
            'property_type_id_name' => implode(', ', $propertyTypeNames),

            // Developer
            'developer_id'          => $p->developer_id,
            'developer_name'        => $p->developer->name ?? null,

            'total_view'            => $p->total_view ?? 0,
            'date'                  => $p->created_at ? $p->created_at->format('d m Y') : null,
            'time'                  => $p->created_at ? $p->created_at->format('h:i A') : null,
            'timestamp'             => $p->created_at ? $p->created_at->format('d m Y h:i A') : null,

            'custom_field_values'   => $customFields
        ];
    });
}

private function formatAgents($agents, $AuthUser, $baseURL)
{
    return $agents->map(function($a) use($AuthUser){

        $email = $a->email; 
        $phone = $a->phone;

        // Mask email & phone for unauthenticated users
        if(!$AuthUser){
            if($email) $email = preg_replace('/(?<=.{2}).(?=.*@)/','*',$email);
            if($phone) $phone = substr($phone,0,3).'****'.substr($phone,-3);
        }

        return [
            'id'            => $a->id,
            'name'          => $a->first_name.' '.$a->last_name,
            'email'         => $email,
            'phone'         => $phone,
            'role_id'       => $a->role_id,
            'role_name'     => $a->role->name ?? null,
            'country_id'    => $a->userDetails->country_id ?? null,
            'country_name'  => $a->userDetails->country->name ?? null,
            'state_id'      => $a->userDetails->state_id ?? null,
            'state_name'    => $a->userDetails->state->name ?? null,
            'city_id'       => $a->userDetails->city_id ?? null,
            'city_name'     => $a->userDetails->city->name ?? null,
            'profile_photo' => $a->userDetails->profile_photo ? url($a->userDetails->profile_photo) : null,
            'properties_count' => $a->properties_count,
        ];
    });
}






}
