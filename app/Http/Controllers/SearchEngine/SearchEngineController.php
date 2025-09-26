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


use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


use App\Models\PropertyType;

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
            ['field_name_slug' => 'property-rent-amount'],
            ['field_name_slug' => 'price'],
            ['field_label' => 'Price'],
        ];

        foreach ($candidates as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_name_slug'])) $cfQuery->where('field_name_slug', $cond['field_name_slug']);
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
            ['field_name_slug' => 'property-rent-amount'],
            ['field_name_slug' => 'price'],
            ['field_label' => 'Price']
        ];

        foreach ($candidates as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_name_slug'])) $cfQuery->where('field_name_slug', $cond['field_name_slug']);
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
            ['field_name_slug' => 'bedrooms'],
            ['field_label' => 'BHK'],
            ['field_name_slug' => 'bhk'],
        ];

        $valuesCollected = collect();

        foreach ($possible as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_name_slug'])) $cfQuery->where('field_name_slug', $cond['field_name_slug']);
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
            ['field_name_slug' => 'bedrooms'],
            ['field_label' => 'BHK'],
            ['field_name_slug' => 'bhk'],
        ];

        $matchedPropertyIds = collect();

        foreach ($possible as $cond) {
            $cfQuery = CustomField::query();
            if (isset($cond['field_name_slug'])) $cfQuery->where('field_name_slug', $cond['field_name_slug']);
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


      // =======================
    // 1. Global Search API
    // =======================
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
                    }else{
                        $propertiesQuery->where($field,$request->$field);
                    }
                }
            }

            $propertyIds = $propertiesQuery->pluck('id');

            $filters = [];

            // Property types
            $filters['property_types'] = PropertyList::whereIn('id',$propertyIds)
                ->pluck('property_type_id')
                ->map(fn($v)=>explode(',',$v))->flatten()->unique()->values();

            // Property statuses
            $filters['property_statuses'] = PropertyList::whereIn('id',$propertyIds)
                ->pluck('property_status_id')->unique()->values();

            // Price range
            // $filters['price_min'] = PropertyList::whereIn('id',$propertyIds)->min('price') ?? 0;
            // $filters['price_max'] = PropertyList::whereIn('id',$propertyIds)->max('price') ?? 0;

            // Price range from custom fields
            $priceFieldIds = CustomField::whereIn('field_label', ['property_rent_amount','plot_price','project_price'])->pluck('id');
            $priceValues = CustomFieldValue::whereIn('custom_field_id', $priceFieldIds)
                ->whereIn('properties_listing_id', $propertyIds)
                ->pluck('field_meta_value')
                ->map(fn($v) => (float)$v);

            $filters['price_min'] = $priceValues->min() ?? 0;
            $filters['price_max'] = $priceValues->max() ?? 0;
            // Custom fields
            $customFieldValues = CustomFieldValue::whereIn('properties_listing_id',$propertyIds)->get();
            $filters['custom_fields'] = $customFieldValues->groupBy('custom_field_id')
                ->map(fn($group)=>$group->pluck('field_meta_value')->unique()->values());

            return response()->json(['status'=>true,'filters'=>$filters],200);

        } catch(\Throwable $th){
            return response()->json(['status'=>false,'error'=>$th->getMessage().' on line '.$th->getLine()],500);
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
    // Helper Functions
    // =======================

    private function applyPurposeFilters(&$query, $request)
    {
        if(!$request->filled('purpose')) return;

        $purpose = $request->purpose;

        // Property Type filter
        if($request->filled('property_type_id')){
            $ids = explode(',',$request->property_type_id);
            $query->where(function($q) use($ids){
                foreach($ids as $id) $q->orWhereRaw("FIND_IN_SET(?,property_type_id)",[$id]);
            });
        }

        // Property Status filter
        if($request->filled('property_status_id')){
            $ids = explode(',',$request->property_status_id);
            $query->whereIn('property_status_id',$ids);
        }

        // Posted By filter
        if($request->filled('posted_by')){
            $user_ids = [];
            if($request->posted_by=='agent') $user_ids = User::where('role_id',3)->pluck('id')->toArray();
            if($request->posted_by=='owner') $user_ids = User::where('role_id',2)->pluck('id')->toArray();
            $query->whereIn('user_id',$user_ids);
        }

        // Price filters based on purpose
        if($purpose=='buy' && $request->filled('property_price_low') && $request->filled('property_price_high')){
            $cf = CustomField::where('field_name','property_rent_amount')->first();
            if($cf){
                $ids = CustomFieldValue::where('custom_field_id',$cf->id)
                    ->whereBetween('field_meta_value',[$request->property_price_low,$request->property_price_high])
                    ->pluck('properties_listing_id')->toArray();
                $query->whereIn('id',$ids);
            }
        }

        if($purpose=='rent' && $request->filled('rent_price_low') && $request->filled('rent_price_high')){
            $cf = CustomField::where('field_label','property_rent_amount')->first();
            if($cf){
                $ids = CustomFieldValue::where('custom_field_id',$cf->id)
                    ->whereBetween('field_meta_value',[$request->rent_price_low,$request->rent_price_high])
                    ->pluck('properties_listing_id')->toArray();
                $query->whereIn('id',$ids);
            }
        }

        // Similar logic for pgco-living, plot_land, project
        if($purpose=='pgco-living'){
            if($request->filled('pg_rent_price_low') && $request->filled('pg_rent_price_high')){
                $cf = CustomField::where('field_name','property_rent_amount')->first();
                if($cf){
                    $ids = CustomFieldValue::where('custom_field_id',$cf->id)
                        ->whereBetween('field_meta_value',[$request->pg_rent_price_low,$request->pg_rent_price_high])
                        ->pluck('properties_listing_id')->toArray();
                    $query->whereIn('id',$ids);
                }
            }
            if($request->filled('availabel_for')){
                $cf = CustomField::where('field_name','listing_available_for')->first();
                if($cf){
                    $ids = CustomFieldValue::where('custom_field_id',$cf->id)
                        ->whereRaw("FIND_IN_SET(?,field_meta_value)",[$request->availabel_for])
                        ->pluck('properties_listing_id')->toArray();
                    $query->whereIn('id',$ids);
                }
            }
        }

        if($purpose=='plot_land'){
            if($request->filled('plot_price_low') && $request->filled('plot_price_high')){
                $cf = CustomField::where('field_name','property_rent_amount')->first();
                if($cf){
                    $ids = CustomFieldValue::where('custom_field_id',$cf->id)
                        ->whereBetween('field_meta_value',[$request->plot_price_low,$request->plot_price_high])
                        ->pluck('properties_listing_id')->toArray();
                    $query->whereIn('id',$ids);
                }
            }
        }

        if($purpose=='project'){
            if($request->filled('project_price_low') && $request->filled('project_price_high')){
                $cf = CustomField::where('field_name','property_rent_amount')->first();
                if($cf){
                    $ids = CustomFieldValue::where('custom_field_id',$cf->id)
                        ->whereBetween('field_meta_value',[$request->project_price_low,$request->project_price_high])
                        ->pluck('properties_listing_id')->toArray();
                    $query->whereIn('id',$ids);
                }
            }
        }
    }

    private function formatProperties($properties,$baseURL)
    {
        return $properties->map(function($p) use($baseURL){
            $customFields = $p->customFieldValues->map(function($cfv) use($baseURL){
                $value = $cfv->field_meta_value;
                if($cfv->customField->field_type=='checkbox') $value = explode(',',$value);
                elseif($cfv->customField->field_type=='media') $value = collect(json_decode($value))->map(fn($v)=>$baseURL.'/uploads/media/'.$v);
                return [
                    'id'=>$cfv->customField->id,
                    'type'=>$cfv->customField->field_type,
                    'value'=>$value,
                    'label'=>$cfv->customField->field_label
                ];
            });
            return [
                'id'=>$p->id,
                'name'=>$p->name,
                'country'=>$p->country->name ?? null,
                'state'=>$p->state->name ?? null,
                'city'=>$p->city->name ?? null,
                'custom_fields'=>$customFields
            ];
        });
    }

    private function formatProjects($projects,$baseURL)
    {
        return $projects->map(function($p) use($baseURL){
            $customFields = $p->customFieldValues->map(function($cfv) use($baseURL){
                $value = $cfv->field_meta_value;
                if($cfv->customField->field_type=='checkbox') $value = explode(',',$value);
                elseif($cfv->customField->field_type=='media') $value = collect(json_decode($value))->map(fn($v)=>$baseURL.'/uploads/media/'.$v);
                return [
                    'id'=>$cfv->customField->id,
                    'type'=>$cfv->customField->field_type,
                    'value'=>$value,
                    'label'=>$cfv->customField->field_label
                ];
            });
            return [
                'id'=>$p->id,
                'name'=>$p->name,
                'country'=>$p->country->name ?? null,
                'state'=>$p->state->name ?? null,
                'city'=>$p->city->name ?? null,
                'custom_fields'=>$customFields
            ];
        });
    }

    private function formatAgents($agents,$AuthUser,$baseURL)
    {
        return $agents->map(function($a) use($AuthUser){
            $email = $a->email; $phone = $a->phone;
            if(!$AuthUser){
                if($email) $email = preg_replace('/(?<=.{2}).(?=.*@)/','*',$email);
                if($phone) $phone = substr($phone,0,3).'****'.substr($phone,-3);
            }
            return [
                'id'=>$a->id,
                'name'=>$a->first_name.' '.$a->last_name,
                'email'=>$email,
                'phone'=>$phone,
                'properties_count'=>$a->properties_count
            ];
        });
    }



}
