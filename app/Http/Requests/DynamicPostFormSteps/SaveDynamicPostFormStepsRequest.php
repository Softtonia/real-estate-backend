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
}