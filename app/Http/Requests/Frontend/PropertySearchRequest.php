<?php

namespace App\Http\Requests\Frontend;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropertySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach (['purpose', 'property_type', 'bedrooms', 'area_locality', 'search', 'sort_by'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],

            'area_locality' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],

            'purpose' => ['nullable', 'string', 'max:100'],
            'property_type' => ['nullable', 'string', 'max:100'],
            'bedrooms' => ['nullable', 'string', 'max:100'],

            'taxonomy_term_ids' => ['nullable'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'gte:price_min'],

            'sort_by' => [
                'nullable',
                Rule::in([
                    'newest',
                    'oldest',
                    'price_low',
                    'price_high',
                    'relevance',
                ]),
            ],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}