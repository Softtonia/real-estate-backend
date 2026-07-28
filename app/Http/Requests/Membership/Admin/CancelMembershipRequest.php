<?php

namespace App\Http\Requests\Membership\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CancelMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}