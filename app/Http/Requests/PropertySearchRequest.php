<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PropertySearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Taxonomy Filters
            'purpose' => ['nullable'],
            'purpose.*' => ['string', 'max:50'],
            'category' => ['nullable'],
            'category.*' => ['string', 'max:50'],
            'categories' => ['nullable'],
            'type' => ['nullable'],
            'type.*' => ['string', 'max:100'],
            'property_type' => ['nullable'],
            'property_type_id' => ['nullable'],
            'possession_status' => ['nullable'],
            'possession_status.*' => ['string', 'max:50'],
            'amenity' => ['nullable'],
            'amenity.*' => ['string', 'max:100'],
            'amenities' => ['nullable'],

            // Location Filters
            'city_id' => ['nullable'],
            'city_id.*' => ['integer'],
            'cities' => ['nullable'],
            'state_id' => ['nullable', 'integer'],
            'locality' => ['nullable'],
            'locality.*' => ['string', 'max:255'],
            'area_locality' => ['nullable'],
            'localities' => ['nullable'],

            // Numeric Ranges
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'rent_min' => ['nullable', 'numeric', 'min:0'],
            'rent_max' => ['nullable', 'numeric', 'min:0'],
            'area_min' => ['nullable', 'numeric', 'min:0'],
            'area_max' => ['nullable', 'numeric', 'min:0'],
            'floor_min' => ['nullable', 'integer'],
            'floor_max' => ['nullable', 'integer'],

            // Multi-Select Custom Fields
            'bhk' => ['nullable'],
            'bhk.*' => ['string', 'max:50'],
            'bedrooms' => ['nullable'],
            'bathrooms' => ['nullable'],
            'bathrooms.*' => ['string', 'max:50'],
            'furnishing' => ['nullable'],
            'furnishing.*' => ['string', 'max:50'],
            'furnishing_status' => ['nullable'],
            'facing' => ['nullable'],
            'facing.*' => ['string', 'max:50'],
            'property_facing' => ['nullable'],
            'ownership' => ['nullable'],
            'ownership.*' => ['string', 'max:100'],
            'ownership_type' => ['nullable'],

            // Boolean and Workflow Filters
            'verified' => ['nullable'],
            'has_photos' => ['nullable'],
            'has_videos' => ['nullable'],
            'rera' => ['nullable'],
            'featured' => ['nullable'],
            'availability_status' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],

            // Author and Date Filters
            'posted_by' => ['nullable'],
            'posted_by.*' => ['string', 'max:50'],
            'author_role' => ['nullable'],
            'posted_since' => ['nullable', 'string', 'in:1d,7d,30d,3m,6m,1y'],

            // Keyword Search and Sorting
            'q' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,price_asc,price_desc,price_low_to_high,price_high_to_low,area_asc,area_desc,featured_first,relevance'],
            'sort_by' => ['nullable', 'string'],

            // Pagination and Facets
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'include_facets' => ['nullable'],
        ];
    }
}
