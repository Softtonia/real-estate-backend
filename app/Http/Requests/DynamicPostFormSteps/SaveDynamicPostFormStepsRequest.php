<?php

namespace App\Http\Requests\DynamicPostFormSteps;

use Illuminate\Foundation\Http\FormRequest;

class SaveDynamicPostFormStepsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1'],

            'steps.*.step_key' => [
                'required',
                'string',
                'max:50',
                'regex:/^step-[0-9]+$/',
            ],

            'steps.*.step_label' => [
                'required',
                'string',
                'max:255',
            ],

            'steps.*.description' => [
                'nullable',
                'string',
            ],

            'steps.*.sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'steps.*.is_active' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'steps.required' => 'Steps are required.',
            'steps.array' => 'Steps must be an array.',
            'steps.*.step_key.required' => 'Step key is required.',
            'steps.*.step_key.regex' => 'Step key format must be like step-1, step-2.',
            'steps.*.step_label.required' => 'Step label is required.',
        ];
    }
}