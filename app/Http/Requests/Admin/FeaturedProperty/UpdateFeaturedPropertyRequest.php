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
            /*
             * Property cannot be changed after promotion creation.
             */
            'dynamic_post_id' => [
                'prohibited',
            ],

            'starts_at' => [
                'sometimes',
                'required',
                'date',
            ],

            'ends_at' => [
                'sometimes',
                'required',
                'date',
            ],

            'priority' => [
                'sometimes',
                'required',
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

            /*
             * Backend-controlled fields.
             */
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
            'dynamic_post_id.prohibited' =>
                'The property of an existing featured promotion cannot be changed.',

            'priority.min' =>
                'Priority cannot be negative.',

            'priority.max' =>
                'Priority may not be greater than 100000.',
        ];
    }
}