<?php

namespace App\Http\Controllers\SearchEngine;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Customfieldvalue;
use App\Models\Keyword;
use App\Models\PropertyList;
use App\Models\ProjectList;
use App\Models\User;
use App\Models\Purpose;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Developer;

class SearchEngineController extends Controller
{

     // this is for search property
    // public function globleSearchEngine(Request $request)
    // {
    //     try {
    //         $baseURL = config('app.url');
    //         $basePath = public_path();


    //         $propertiesQuery = PropertyList::with([
    //             'country','state','city','user',
    //             'propertyType', 'purpose', 'property',
    //              'propertystatus', 'project', 'customFieldValues.customField',
    //               'customFieldValues.customFieldOption'
    //             ])->when($request->country_id, function ($q, $country_id) {
    //                 $q->where('country_id', $country_id);
    //             })
    //             ->when($request->state_id, function ($q, $state_id) {
    //                 $q->where('state_id', $state_id);
    //             })
    //             ->when($request->city_id, function ($q, $city_id) {
    //                 $q->where('city_id', $city_id);
    //             });




    //         if ($request->purpose == 'buy') {


    //             if (!empty($request->purpose)) {
    //                 $purpose = Purpose::where('slug', $request->purpose)->first();

    //                 if ($purpose) {
    //                     $propertiesQuery->where('purpose_id', $purpose->id);
    //                 }
    //             }

    //             if (!empty($request->property_id)) {
    //                 $propertiesQuery->where('property_id', $request->property_id);
    //             }

    //             // if (!empty($request->property_type_id)) {
    //             //     $explod_property_type_id = explode(',', $request->property_type_id);
    //             //     $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
    //             // }

    //             if (!empty($request->property_type_id)) {
    //                 $explod_property_type_id = explode(',', $request->property_type_id);

    //                 $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
    //                     foreach ($explod_property_type_id as $id) {
    //                         $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
    //                     }
    //                 });
    //             }

    //             if (!empty($request->property_status_id)) {
    //                 $explod_property_status_id = explode(',', $request->property_status_id);
    //                 $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
    //             }

    //             if (!empty($request->property_price_low) && !empty($request->property_price_high)) {
    //                 $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

    //                 if ($checkCustomField) {
    //                     $custom_field_id = $checkCustomField->id;

    //                     $properties_listing_id_arr = Customfieldvalue::where('custom_field_id', $custom_field_id)
    //                         ->whereBetween('field_meta_value', [$request->property_price_low, $request->property_price_high])
    //                         ->pluck('properties_listing_id')
    //                         ->toArray();

    //                     $propertiesQuery->whereIn('id', $properties_listing_id_arr);
    //                 }
    //             }
    //         }

    //         if ($request->purpose == 'rent') {

    //             if (!empty($request->purpose)) {
    //                 $purpose = Purpose::where('slug', $request->purpose)->first();

    //                 \Log::info($purpose);

    //                 if ($purpose) {
    //                     $propertiesQuery->where('purpose_id', $purpose->id);
    //                 }
    //             }

    //             if (!empty($request->property_id)) {
    //                 $propertiesQuery->where('property_id', $request->property_id);
    //             }

    //             // if (!empty($request->property_type_id)) {
    //             //     $explod_property_type_id = explode(',', $request->property_type_id);
    //             //     $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
    //             // }

    //             if (!empty($request->property_type_id)) {
    //                 $explod_property_type_id = explode(',', $request->property_type_id);

    //                 $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
    //                     foreach ($explod_property_type_id as $id) {
    //                         $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
    //                     }
    //                 });
    //             }

    //             if (!empty($request->property_status_id)) {
    //                 $explod_property_status_id = explode(',', $request->property_status_id);
    //                 $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
    //             }

    //             if (!empty($request->rent_price_low) && !empty($request->rent_price_high)) {
    //                 $checkCustomField = CustomField::where('field_label', 'property_rent_amount')->first();

    //                 if ($checkCustomField) {
    //                     $custom_field_id = $checkCustomField->id;

    //                     $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
    //                         ->whereBetween('field_meta_value', [$request->rent_price_low, $request->rent_price_high])
    //                         ->pluck('properties_listing_id')
    //                         ->toArray();

    //                     $propertiesQuery->whereIn('id', $properties_listing_id_arr);
    //                 }
    //             }


    //             if (!empty($request->posted_by)) {

    //                 $user_id_arr = [];

    //                 if ($request->posted_by == 'agent') {
    //                     $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
    //                 }

    //                 if ($request->posted_by == 'owner') {
    //                     $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
    //                 }

    //                 $propertiesQuery->whereIn('user_id', $user_id_arr);
    //             }
    //         }

    //         if ($request->purpose == 'pgco-living') {

    //             if (!empty($request->property_type_id)) {
    //                 $explod_property_type_id = explode(',', $request->property_type_id);

    //                 $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
    //                     foreach ($explod_property_type_id as $id) {
    //                         $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
    //                     }
    //                 });
    //             }

    //             if (!empty($request->property_status_id)) {
    //                 $explod_property_status_id = explode(',', $request->property_status_id);
    //                 $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
    //             }

    //             if (!empty($request->pg_rent_price_low) && !empty($request->pg_rent_price_high)) {
    //                 $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

    //                 if ($checkCustomField) {
    //                     $custom_field_id = $checkCustomField->id;

    //                     $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
    //                         ->whereBetween('field_meta_value', [$request->pg_rent_price_low, $request->pg_rent_price_high])
    //                         ->pluck('properties_listing_id')
    //                         ->toArray();

    //                     $propertiesQuery->whereIn('id', $properties_listing_id_arr);
    //                 }
    //             }


    //             if (!empty($request->posted_by)) {

    //                 $user_id_arr = [];

    //                 if ($request->posted_by == 'agent') {
    //                     $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
    //                 }

    //                 if ($request->posted_by == 'owner') {
    //                     $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
    //                 }

    //                 $propertiesQuery->whereIn('user_id', $user_id_arr);
    //             }


    //             if (!empty($request->availabel_for)) {
    //                 $checkCustomField = CustomField::where('field_name', 'listing_available_for')->first();

    //                 if ($checkCustomField) {
    //                     $custom_field_id = $checkCustomField->id;

    //                     $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
    //                         ->whereRaw("FIND_IN_SET(?, field_meta_value)", [$request->availabel_for])
    //                         ->pluck('properties_listing_id')
    //                         ->toArray();

    //                     $propertiesQuery->whereIn('id', $properties_listing_id_arr);
    //                 }
    //             }
    //         }


    //         if ($request->purpose == 'plot_land') {

    //             if (!empty($request->property_id)) {
    //                 $propertiesQuery->where('property_id', $request->property_id);
    //             }

    //             if (!empty($request->property_type_id)) {
    //                 $explod_property_type_id = explode(',', $request->property_type_id);

    //                 $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
    //                     foreach ($explod_property_type_id as $id) {
    //                         $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
    //                     }
    //                 });
    //             }

    //             if (!empty($request->plot_price_low) && !empty($request->plot_price_high)) {
    //                 $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

    //                 if ($checkCustomField) {
    //                     $custom_field_id = $checkCustomField->id;

    //                     $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
    //                         ->whereBetween('field_meta_value', [$request->plot_price_low, $request->plot_price_high])
    //                         ->pluck('properties_listing_id')
    //                         ->toArray();

    //                     $propertiesQuery->whereIn('id', $properties_listing_id_arr);
    //                 }
    //             }


    //             if (!empty($request->posted_by)) {

    //                 $user_id_arr = [];

    //                 if ($request->posted_by == 'agent') {
    //                     $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
    //                 }

    //                 if ($request->posted_by == 'owner') {
    //                     $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
    //                 }

    //                 $propertiesQuery->whereIn('user_id', $user_id_arr);
    //             }


    //             if (!empty($request->plot_area)) {

    //             }
    //         }


    //         if ($request->purpose == 'project') {

    //             if (!empty($request->property_id)) {
    //                 $propertiesQuery->where('property_id', $request->property_id);
    //             }

    //            if (!empty($request->property_type_id)) {
    //                 $explod_property_type_id = explode(',', $request->property_type_id);

    //                 $propertiesQuery->where(function ($q) use ($explod_property_type_id) {
    //                     foreach ($explod_property_type_id as $id) {
    //                         $q->orWhereRaw("FIND_IN_SET(?, property_type_id)", [$id]);
    //                     }
    //                 });
    //             }

    //             if (!empty($request->project_price_low) && !empty($request->project_price_high)) {
    //                 $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

    //                 if ($checkCustomField) {
    //                     $custom_field_id = $checkCustomField->id;

    //                     $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
    //                         ->whereBetween('field_meta_value', [$request->project_price_low, $request->project_price_high])
    //                         ->pluck('properties_listing_id')
    //                         ->toArray();

    //                     $propertiesQuery->whereIn('id', $properties_listing_id_arr);
    //                 }
    //             }
    //         }


    //         if (isset($request->keyword)) {
    //             $property_id_arr = Keyword::where('keyword', $request->keyword)->where('property_id', '!=', null)->pluck('property_id')->toArray();
    //             $propertiesQuery->whereIn('id', $property_id_arr);
    //         }

    //         // Get the results from the query builder
    //         $properties = $propertiesQuery->get();

    //         // Total property count
    //         $totalCount = $propertiesQuery->count();

    //         // Now use map on the collection
    //         $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
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
    //             $propertyData = [
    //                 'id' => $property->id,
    //                 'property_unique_id' => $property->property_unique_id,
    //                 'property_name' => $property->name,
    //                 'description' => $property->description,
    //                 'country_id' => $property->country_id,
    //                 'country' => $property->country_id ? [
    //                     'id' =>$property->country->id,
    //                     'name' => $property->country->name,
    //                 ] : null,
    //                 'state_id' => $property->state_id,
    //                 'state' => $property->state_id ? [
    //                     'id' => $property->state->id,
    //                     'name'=> $property->state->name,
    //                 ] : null ,
    //                 'city_id' => $property->city_id,
    //                 'city' => $property->city_id ? [
    //                     'id' => $property->city->id,
    //                     'name'=> $property->city->name,
    //                 ] : null ,
    //                 'property_address' => $property->property_address,
    //                 'live_status' => $property->live_status,
    //                 'status_reason' => $property->status_reason,
    //                 'user_id' => $property->user_id,
    //                 'listed_by' => optional(optional($property->user)->role)->name,
    //                 'featured_image' => $property->featured_image ? url($property->featured_image) : null,
    //                 'purpose_id' => $property->purpose_id,
    //                 'purpose_id_name' => optional($property->purpose)->name,
    //                 'property_id' => $property->property_id,
    //                 'property_id_name' => optional($property->property)->name,
    //                 'property_status_id' => $property->property_status_id,
    //                 'property_status_id_name' => optional($property->propertystatus)->name,
    //                 'property_type_id' => $property->property_type_id,
    //                 'property_type_id_name' => $property->property_type_id
    //                                         ? DB::table('property_types')
    //                                             ->whereIn('id', explode(',', $property->property_type_id))
    //                                             ->pluck('name')
    //                                             ->implode(', ')
    //                                         : null,
    //                 'project_id' => $property->project_id,
    //                 'project_id_name' => optional($property->project)->name,
    //                 'total_view' => $property->analytics()->count(),
    //                 'date' => date('d m Y', strtotime($property->created_at)),
    //                 'time' => date('h:m A', strtotime($property->created_at)),
    //                 'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
    //                 'custom_field_values' => $formattedCustomFieldValues,
    //             ];

    //             return $propertyData;
    //         });

    //         return response()->json([
    //             'total_count' => $totalCount,
    //             'properties' => $propertiesData
    //         ],200);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage() . ' on line ' . $th->getLine()], 500);
    //     }
    // }


    public function globleSearchEngine1(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Properties query
            $propertiesQuery = PropertyList::with([
                'country','state','city','user',
                'propertyType', 'purpose', 'property',
                'propertystatus', 'project', 'customFieldValues.customField',
                'customFieldValues.customFieldOption'
            ])->when($request->country_id, function ($q, $country_id) {
                $q->where('country_id', $country_id);
            })
            ->when($request->state_id, function ($q, $state_id) {
                $q->where('state_id', $state_id);
            })
            ->when($request->city_id, function ($q, $city_id) {
                $q->where('city_id', $city_id);
            });

            // Projects query (for project purpose)
            $projectsQuery = ProjectList::with(['country', 'state', 'city', 'user'])
                ->when($request->country_id, function ($q, $country_id) {
                    $q->where('country_id', $country_id);
                })
                ->when($request->state_id, function ($q, $state_id) {
                    $q->where('state_id', $state_id);
                })
                ->when($request->city_id, function ($q, $city_id) {
                    $q->where('city_id', $city_id);
                });

            // Agents query (top agents based on filters)
            $agentsQuery = User::with(['role'])
                ->where('role_id', 3) // Agent role
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'listing_available_for')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                        'field_name' => $customField ? $customField->field_label : null,
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
                    return [
                        'id' => $project->id,
                        'name' => $project->first_name,
                        'description' => $project->description,
                        'country' => $project->country ? $project->country->name : null,
                        'state' => $project->state ? $project->state->name : null,
                        'city' => $project->city ? $project->city->name : null,
                        'address' => $project->address,
                        'featured_image' => $project->featured_image ? url($project->featured_image) : null,
                        'developer' => $project->user ? $project->user->first_name : null,
                        'total_units' => $project->total_units,
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
                });
            }])
            ->orderBy('properties_count', 'desc')
            ->limit(10) // Limit to top 10 agents
            ->get();

            $agentsData = $agents->map(function ($agent) {
                return [
                    'id' => $agent->id,
                    'name' => $agent->first_name,
                    'email' => $agent->email,
                    'phone' => $agent->phone,
                    // 'avatar' => $agent->avatar ? url($agent->avatar) : null,
                    'properties_count' => $agent->properties_count,
                    // Add other agent fields as needed
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'listing_available_for')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();
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
                    'field_name' => $customField ? $customField->field_label : null,
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
                        'field_name' => $customField ? $customField->field_label : null,
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

}
