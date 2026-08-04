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
            'features' => ['required', 'array', 'min:1'],

            'features.*.feature_id' => [
                'nullable',
                'integer',
                'exists:membership_features,id',
            ],

            'features.*.slug' => [
                'nullable',
                'string',
                'exists:membership_features,slug',
            ],

            'features.*.feature_value' => ['nullable'],
            'features.*.value' => ['nullable'],

            'features.*.is_unlimited' => ['sometimes', 'nullable', 'boolean'],
            'features.*.status' => ['sometimes', 'nullable', 'boolean'],
            'features.*.sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'detach_missing' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('features', []) as $index => $feature) {
                if (empty($feature['feature_id']) && empty($feature['slug'])) {
                    $validator->errors()->add(
                        "features.{$index}.feature_id",
                        'Feature id or slug is required.'
                    );
                }
            }
        });
    }
}