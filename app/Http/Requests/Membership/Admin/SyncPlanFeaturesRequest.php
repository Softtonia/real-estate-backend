<?php

namespace App\Http\Requests\Membership\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncPlanFeaturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'features' => ['required', 'array'],
            'features.*.feature_id' => ['required', 'integer', 'exists:membership_features,id'],
            'features.*.feature_value' => ['nullable', 'string', 'max:255'],
            'features.*.value' => ['nullable', 'string', 'max:255'],
            'features.*.is_unlimited' => ['nullable', 'boolean'],
            'features.*.metadata' => ['nullable', 'array'],
        ];
    }
}