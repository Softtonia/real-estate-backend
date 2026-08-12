<?php

namespace App\Http\Requests\Admin\FeaturedProperty;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    public function messages(): array
    {
        return [
            'dynamic_post_id.required' =>
                'Please select a listing.',

            'dynamic_post_id.exists' =>
                'Selected listing does not exist.',

            'priority.min' =>
                'Priority cannot be negative.',

            'priority.max' =>
                'Priority may not be greater than 100000.',
        ];
    }
}