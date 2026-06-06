<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWidgetConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'widget_id' => [
                'required',
                'exists:custom_widgets,id',
            ],

            'configurations' => [
                'required',
                'array',
                'min:1',
            ],

            'configurations.*.field_key' => [
                'required',
                'string',
                'max:255',
            ],

            'configurations.*.field_value' => [
                'nullable',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'widget_id.required' => 'Widget ID is required.',
            'widget_id.exists' => 'Selected widget does not exist.',
            'configurations.required' => 'Widget configuration is required.',
            'configurations.array' => 'Widget configuration must be an array.',
        ];
    }
}