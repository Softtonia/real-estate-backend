<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipFeature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $featureId = $this->route('feature')?->id ?? $this->route('feature');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_features', 'slug')->ignore($featureId),
            ],
            'description' => ['nullable', 'string'],
            'feature_type' => [
                'required',
                Rule::in([
                    MembershipFeature::TYPE_BOOLEAN,
                    MembershipFeature::TYPE_NUMBER,
                    MembershipFeature::TYPE_TEXT,
                    MembershipFeature::TYPE_LIMIT,
                ]),
            ],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}