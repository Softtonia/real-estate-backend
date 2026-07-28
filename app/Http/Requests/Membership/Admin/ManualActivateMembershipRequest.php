<?php

namespace App\Http\Requests\Membership\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ManualActivateMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'start_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}