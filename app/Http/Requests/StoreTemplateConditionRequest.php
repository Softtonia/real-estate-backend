<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateConditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'show_type' => ['required', 'in:include,exclude'],
            'post_type' => ['required', 'in:property-listing,project-listing,developer-listing'],
            'condition_type' => ['required', 'in:all,purpose,property,property-type,property-status'],
            'condition_value' => ['nullable', 'string', 'max:255'],
        ];
    }
}