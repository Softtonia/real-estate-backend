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

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (array_key_exists('mode', $data) && is_string($data['mode'])) {
            $data['mode'] = strtolower(trim($data['mode']));
        }

        if (array_key_exists('currency', $data) && is_string($data['currency'])) {
            $data['currency'] = strtoupper(trim($data['currency']));
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],

            'mode' => [
                'sometimes',
                Rule::in(['test', 'live']),
            ],

            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],

            'test_key_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'test_key_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'test_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'live_key_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'live_key_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'live_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}