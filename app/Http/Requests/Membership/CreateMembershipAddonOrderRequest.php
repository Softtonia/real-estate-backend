<?php

namespace App\Http\Requests\Membership;

use Illuminate\Foundation\Http\FormRequest;

class CreateMembershipAddonOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'addon_id' => ['required', 'integer', 'exists:membership_addons,id'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:50'],

            'billing' => ['nullable', 'array'],
            'billing.name' => ['nullable', 'string', 'max:255'],
            'billing.email' => ['nullable', 'email', 'max:255'],
            'billing.phone' => ['nullable', 'string', 'max:30'],
            'billing.gst_number' => ['nullable', 'string', 'max:50'],
            'billing.address' => ['nullable', 'string', 'max:1000'],
            'billing.city' => ['nullable', 'string', 'max:100'],
            'billing.state' => ['nullable', 'string', 'max:100'],
            'billing.country' => ['nullable', 'string', 'max:100'],
            'billing.pincode' => ['nullable', 'string', 'max:20'],
        ];
    }
}