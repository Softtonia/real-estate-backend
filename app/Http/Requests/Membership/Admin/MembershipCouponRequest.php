<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipCoupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $couponId = $this->route('coupon')?->id ?? $this->route('coupon');

        return [
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('membership_coupons', 'code')->ignore($couponId),
            ],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'discount_type' => [
                'required',
                Rule::in([
                    MembershipCoupon::DISCOUNT_FIXED,
                    MembershipCoupon::DISCOUNT_PERCENTAGE,
                ]),
            ],

            'discount_value' => ['required', 'numeric', 'min:0.01'],

            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'min:0'],

            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],

            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],

            'allowed_plan_ids' => ['nullable', 'array'],
            'allowed_plan_ids.*' => ['integer', 'exists:membership_plans,id'],

            'allowed_category_ids' => ['nullable', 'array'],
            'allowed_category_ids.*' => ['integer', 'exists:membership_categories,id'],

            'new_user_only' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (
                $this->input('discount_type') === MembershipCoupon::DISCOUNT_PERCENTAGE
                && (float) $this->input('discount_value') > 100
            ) {
                $validator->errors()->add(
                    'discount_value',
                    'Percentage discount cannot be greater than 100.'
                );
            }
        });
    }
}