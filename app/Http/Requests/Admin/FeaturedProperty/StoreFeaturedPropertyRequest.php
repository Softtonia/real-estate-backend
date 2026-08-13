<?php

namespace App\Http\Requests\Admin\FeaturedProperty;

use App\Models\PropertyFeaturedPromotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeaturedPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dynamic_post_id' => [
                'required',
                'integer',
                'exists:dynamic_posts,id',
            ],

            'promotion_type' => [
                'nullable',
                'string',
                Rule::in(
                    PropertyFeaturedPromotion::TYPES
                ),
            ],

            'show_on_home' => [
                'nullable',
                'boolean',
            ],

            'show_on_search' => [
                'nullable',
                'boolean',
            ],

            'show_on_detail' => [
                'nullable',
                'boolean',
            ],

            'starts_at' => [
                'nullable',
                'date',
            ],

            'ends_at' => [
                'nullable',
                'date',
            ],

            'priority' => [
                'nullable',
                'integer',
                'min:0',
                'max:100000',
            ],

            'admin_notes' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'source' => [
                'prohibited',
            ],

            'status' => [
                'prohibited',
            ],

            'created_by' => [
                'prohibited',
            ],

            'updated_by' => [
                'prohibited',
            ],

            'cancelled_by' => [
                'prohibited',
            ],

            'cancelled_at' => [
                'prohibited',
            ],

            'cancellation_reason' => [
                'prohibited',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'dynamic_post_id.required' =>
                'Please select a listing.',

            'dynamic_post_id.integer' =>
                'Selected listing id must be valid.',

            'dynamic_post_id.exists' =>
                'Selected listing does not exist.',

            'promotion_type.in' =>
                'Promotion type must be featured or sponsored.',

            'show_on_home.boolean' =>
                'Home page display value must be true or false.',

            'show_on_search.boolean' =>
                'Search page display value must be true or false.',

            'show_on_detail.boolean' =>
                'Property detail display value must be true or false.',

            'starts_at.date' =>
                'Featured start date must be a valid date.',

            'ends_at.date' =>
                'Featured end date must be a valid date.',

            'priority.integer' =>
                'Priority must be a valid number.',

            'priority.min' =>
                'Priority cannot be negative.',

            'priority.max' =>
                'Priority may not be greater than 100000.',

            'admin_notes.max' =>
                'Admin notes may not be greater than 1000 characters.',
        ];
    }
}