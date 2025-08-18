<?php

namespace App\Http\Controllers\SearchEngine;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Customfieldvalue;
use App\Models\Keyword;
use App\Models\PropertyList;
use App\Models\User;
use Illuminate\Http\Request;

class SearchEngineController extends Controller
{

     // this is for search property
    public function globleSearchEngine(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            $propertiesQuery = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption']);

            if ($request->purpose == 'buy') {

                if (!empty($request->property_id)) {
                    $propertiesQuery->where('property_id', $request->property_id);
                }

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
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

                if (!empty($request->property_id)) {
                    $propertiesQuery->where('property_id', $request->property_id);
                }

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
                }

                if (!empty($request->property_status_id)) {
                    $explod_property_status_id = explode(',', $request->property_status_id);
                    $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
                }

                if (!empty($request->rent_price_low) && !empty($request->rent_price_high)) {
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

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
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
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
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
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

                }
            }


            if ($request->purpose == 'project') {

                if (!empty($request->property_id)) {
                    $propertiesQuery->where('property_id', $request->property_id);
                }

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
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


            if (isset($request->keyword)) {
                $property_id_arr = Keyword::where('keyword', $request->keyword)->where('property_id', '!=', null)->pluck('property_id')->toArray();
                $propertiesQuery->whereIn('id', $property_id_arr);
            }

            // Get the results from the query builder
            $properties = $propertiesQuery->get();

            // Now use map on the collection
            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
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
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
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
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' on line ' . $th->getLine()], 500);
        }
    }

}
