<?php

namespace App\Http\Requests\Membership;

use Illuminate\Foundation\Http\FormRequest;

class UnlockMembershipLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lead_reference_type' => ['required', 'string', 'max:100'],
            'lead_reference_id' => ['required', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}