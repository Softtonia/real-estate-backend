<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->has('status')
                ? $this->toBool($this->input('status'))
                : true,

            'requires_signature' => $this->has('requires_signature')
                ? $this->toBool($this->input('requires_signature'))
                : false,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('api_clients', 'slug')->whereNull('deleted_at'),
            ],

            'type' => [
                'required',
                'string',
                Rule::in([
                    'admin',
                    'business',
                    'website',
                    'mobile',
                    'mobile-app',
                    'server',
                    'custom',
                ]),
            ],

            'status' => ['required', 'boolean'],

            'allowed_origins' => ['nullable', 'array'],

            'allowed_origins.*' => [
                'required',
                'string',
                'max:255',
                'regex:#^(https?)://([^/\s:]+|\*\.[^/\s:]+)(?::(\d+|\*))?$#i',
            ],

            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'required',
                'string',
                'max:150',
                'regex:/^(\*|[a-z0-9_-]+|\*)(\.([a-z0-9_-]+|\*))*$/i',
            ],

            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],

            'requires_signature' => ['required', 'boolean'],

            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'allowed_origins.*.regex' => 'Allowed origin must be valid, for example https://example.com, https://*.example.com, http://localhost:* or http://localhost:5173.',
            'permissions.*.regex' => 'Permission format is invalid. Use formats like *, post_types.*.read, post_types.properties.write.',
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), [
            '1',
            'true',
            'yes',
            'on',
            'active',
            'enabled',
        ], true);
    }
}