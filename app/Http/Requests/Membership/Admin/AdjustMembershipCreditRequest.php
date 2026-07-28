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

            'remaining_credits' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}