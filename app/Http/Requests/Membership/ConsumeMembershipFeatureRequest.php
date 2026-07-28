<?php

namespace App\Http\Requests\Membership;

use App\Models\Membership\MembershipCreditBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsumeMembershipFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credit_type' => [
                'required',
                'string',
                Rule::in([
                    MembershipCreditBalance::TYPE_FEATURED_LISTING,
                    MembershipCreditBalance::TYPE_BOOST,
                    MembershipCreditBalance::TYPE_LEAD_VIEW,
                    MembershipCreditBalance::TYPE_VIDEO_UPLOAD,
                    MembershipCreditBalance::TYPE_VIRTUAL_TOUR,
                    MembershipCreditBalance::TYPE_AI_DESCRIPTION,
                ]),
            ],

            'reference_type' => ['required', 'string', 'max:100'],
            'reference_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}