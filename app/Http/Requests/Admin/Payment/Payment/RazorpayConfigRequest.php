<?php

namespace App\Http\Requests\Admin\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RazorpayConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['required', Rule::in(['test', 'live'])],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],

            'test_key_id' => ['nullable', 'string', 'max:255'],
            'test_key_secret' => ['nullable', 'string', 'max:500'],
            'test_webhook_secret' => ['nullable', 'string', 'max:500'],

            'live_key_id' => ['nullable', 'string', 'max:255'],
            'live_key_secret' => ['nullable', 'string', 'max:500'],
            'live_webhook_secret' => ['nullable', 'string', 'max:500'],
        ];
    }
}