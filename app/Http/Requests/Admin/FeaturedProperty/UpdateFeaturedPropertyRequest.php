<?php

namespace App\Http\Requests\Admin\FeaturedProperty;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeaturedPropertyRequest extends FormRequest
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

            'starts_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'ends_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'priority' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:100000',
            ],

            'admin_notes' => [
                'sometimes',
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
                'Listing ID is required.',

            'dynamic_post_id.exists' =>
                'Selected listing does not exist.',

            'priority.min' =>
                'Priority cannot be negative.',

            'priority.max' =>
                'Priority may not be greater than 100000.',
        ];
    }
}