<?php

namespace App\Http\Requests\Membership\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],

            'group_by' => [
                'nullable',
                Rule::in(['day', 'week', 'month']),
            ],

            'plan_id' => ['nullable', 'integer', 'exists:membership_plans,id'],
            'addon_id' => ['nullable', 'integer', 'exists:membership_addons,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],

            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}