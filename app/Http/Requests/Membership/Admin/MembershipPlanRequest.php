<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $planId = $this->route('plan')?->id ?? $this->route('plan');

        return [
            'category_id' => ['required', 'integer', 'exists:membership_categories,id'],

            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_plans', 'slug')->ignore($planId),
            ],

            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],

            'currency' => ['nullable', 'string', 'size:3'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],

            'duration' => ['required', 'integer', 'min:1'],
            'duration_type' => [
                'required',
                Rule::in([
                    MembershipPlan::DURATION_DAYS,
                    MembershipPlan::DURATION_MONTHS,
                    MembershipPlan::DURATION_YEARS,
                ]),
            ],

            'trial_days' => ['nullable', 'integer', 'min:0'],
            'is_popular' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],

            'features' => ['nullable', 'array'],
            'features.*.feature_id' => ['required_with:features', 'integer', 'exists:membership_features,id'],
            'features.*.feature_value' => ['nullable', 'string', 'max:255'],
            'features.*.value' => ['nullable', 'string', 'max:255'],
            'features.*.is_unlimited' => ['nullable', 'boolean'],
            'features.*.metadata' => ['nullable', 'array'],

            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }
}