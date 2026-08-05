<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipCreditBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustMembershipCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'credit_type' => strtolower(trim((string) $this->credit_type)),
            'transaction_type' => strtolower(trim((string) ($this->transaction_type ?? 'credit'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],

            'credit_type' => [
                'required',
                'string',
                Rule::in([
                    MembershipCreditBalance::TYPE_LISTING,
                    MembershipCreditBalance::TYPE_FEATURED_LISTING,
                    MembershipCreditBalance::TYPE_BOOST,
                    MembershipCreditBalance::TYPE_LEAD_VIEW,
                    MembershipCreditBalance::TYPE_VIDEO_UPLOAD,
                    MembershipCreditBalance::TYPE_VIRTUAL_TOUR,
                    MembershipCreditBalance::TYPE_AI_DESCRIPTION,
                ]),
            ],

            'transaction_type' => [
                'required',
                'string',
                Rule::in([
                    'credit',
                    'debit',
                    'adjust',
                    'refund',
                    'expire',
                ]),
            ],

            // Use this for credit/debit/refund/expire.
            'quantity' => [
                'required_unless:transaction_type,adjust',
                'nullable',
                'integer',
                'min:1',
            ],

            // Optional. Only needed when admin wants to set exact remaining balance.
            'remaining_credits' => [
                'required_if:transaction_type,adjust',
                'nullable',
                'integer',
                'min:0',
            ],

            'reason' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required_unless' => 'The quantity field is required for this transaction type.',
            'remaining_credits.required_if' => 'The remaining credits field is required when transaction type is adjust.',
        ];
    }
}