<?php

namespace App\Http\Requests\Membership\Admin;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        $map = [
            'min_order_amount' => 'minimum_order_amount',
            'minimum_amount' => 'minimum_order_amount',

            'max_discount_amount' => 'maximum_discount_amount',
            'maximum_amount' => 'maximum_discount_amount',

            'starts_at' => 'start_at',
            'start_date' => 'start_at',
            'valid_from' => 'start_at',

            'expires_at' => 'end_at',
            'expiry_date' => 'end_at',
            'expiry_at' => 'end_at',
            'expire_at' => 'end_at',
            'end_date' => 'end_at',
            'valid_until' => 'end_at',

            'total_usage_limit' => 'usage_limit',
            'limit_per_user' => 'usage_limit_per_user',
        ];

        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data) && ! array_key_exists($to, $data)) {
                $data[$to] = $data[$from];
            }
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        if (array_key_exists('discount_type', $data)) {
            $data['discount_type'] = $this->normalizeDiscountType($data['discount_type']);
        }

        foreach (['status', 'new_user_only'] as $boolField) {
            if (array_key_exists($boolField, $data)) {
                $data[$boolField] = $this->normalizeBoolean($data[$boolField]);
            }
        }

        if (! empty($data['start_at'])) {
            $data['start_at'] = $this->normalizeDate($data['start_at'], false);
        }

        if (! empty($data['end_at'])) {
            $data['end_at'] = $this->normalizeDate($data['end_at'], true);
        }

        $this->replace($data);
    }

    public function rules(): array
    {
        $coupon = $this->route('coupon');
        $couponId = is_object($coupon) ? $coupon->id : $coupon;

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:100',
                Rule::unique('membership_coupons', 'code')->ignore($couponId),
            ],

            'title' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:255',
            ],

            'description' => ['nullable', 'string'],

            'discount_type' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                Rule::in(['percentage', 'fixed_amount']),
            ],

            'discount_value' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'numeric',
                'min:0.01',
            ],

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
                $this->input('discount_type') === 'percentage'
                && (float) $this->input('discount_value') > 100
            ) {
                $validator->errors()->add(
                    'discount_value',
                    'Percentage discount cannot be greater than 100.'
                );
            }
        });
    }

    private function normalizeDiscountType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace([' ', '-'], '_', $value);

        return match ($value) {
            'percentage', 'percent' => 'percentage',
            'fixed', 'fixed_amount', 'flat', 'flat_amount', 'amount' => 'fixed_amount',
            default => $value,
        };
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, [
            '1',
            'true',
            'yes',
            'y',
            'active',
            'enabled',
            'on',
        ], true);
    }

    private function normalizeDate(mixed $value, bool $endOfDay): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        $formats = [
            'Y-m-d',
            'd-m-Y',
            'd/m/Y',
            'Y/m/d',
            'Y-m-d H:i:s',
            'd-m-Y H:i:s',
            'd/m/Y H:i:s',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                return $endOfDay
                    ? $date->endOfDay()->format('Y-m-d H:i:s')
                    : $date->startOfDay()->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                //
            }
        }

        $date = Carbon::parse($value);

        return $endOfDay
            ? $date->endOfDay()->format('Y-m-d H:i:s')
            : $date->startOfDay()->format('Y-m-d H:i:s');
    }
}