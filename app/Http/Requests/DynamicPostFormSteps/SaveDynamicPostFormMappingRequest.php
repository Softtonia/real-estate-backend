<?php

namespace App\Http\Requests\DynamicPostFormSteps;

use Illuminate\Foundation\Http\FormRequest;

class SaveDynamicPostFormMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'taxonomy_term_ids' => ['nullable'],

            'steps' => ['required', 'array', 'min:1'],

            'steps.*.step_key' => [
                'required',
                'string',
                'max:50',
            ],

            'steps.*.custom_field_ids' => [
                'nullable',
                'array',
            ],

            'steps.*.custom_field_ids.*' => [
                'nullable',
                'integer',
                'exists:custom_fields,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'steps.required' => 'Steps are required.',
            'steps.array' => 'Steps must be an array.',
            'steps.*.step_key.required' => 'Step key is required.',
            'steps.*.custom_field_ids.array' => 'Custom field ids must be an array.',
            'steps.*.custom_field_ids.*.exists' => 'One or more custom fields are invalid.',
        ];
    }
}
