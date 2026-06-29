<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualBlockApiIpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ip_address' => ['required', 'ip'],
            'reason' => ['nullable', 'string', 'max:255'],
            'permanent' => ['sometimes', 'boolean'],
            'blocked_until' => ['nullable', 'date', 'after:now'],
        ];
    }
}