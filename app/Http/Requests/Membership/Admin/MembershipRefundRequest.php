<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipRefund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_type' => [
                'required',
                Rule::in([
                    MembershipRefund::TYPE_MEMBERSHIP_ORDER,
                    MembershipRefund::TYPE_ADDON_ORDER,
                ]),
            ],

            'order_id' => ['required', 'integer', 'min:1'],
            'payment_id' => ['nullable', 'integer', 'exists:membership_payments,id'],

            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],

            'refund_status' => [
                'nullable',
                Rule::in([
                    MembershipRefund::STATUS_PENDING,
                    MembershipRefund::STATUS_PROCESSED,
                ]),
            ],

            'metadata' => ['nullable', 'array'],
        ];
    }
}