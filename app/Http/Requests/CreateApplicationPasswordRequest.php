<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateApplicationPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:100'],

            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}