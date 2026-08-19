<?php

namespace App\Http\Requests\Guest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuestDynamicPostListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'featured' => [
                'nullable',
                'boolean',
            ],

            'country_id' => [
                'nullable',
                'integer',
            ],

            'state_id' => [
                'nullable',
                'integer',
            ],

            'city_id' => [
                'nullable',
                'integer',
            ],

            'area_locality' => [
                'nullable',
                'string',
                'max:255',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'sort_by' => [
                'nullable',
                Rule::in([
                    'latest',
                    'oldest',
                    'title_asc',
                    'title_desc',
                ]),
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }
}