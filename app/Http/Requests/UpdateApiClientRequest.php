<?php

namespace App\Http\Requests;

use App\Models\ApiClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApiClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('status')) {
            $data['status'] = $this->toBool($this->input('status'));
        }

        if ($this->has('requires_signature')) {
            $data['requires_signature'] = $this->toBool($this->input('requires_signature'));
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $apiClient = $this->route('apiClient');

        $apiClientId = $apiClient instanceof ApiClient
            ? $apiClient->id
            : $apiClient;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('api_clients', 'slug')
                    ->ignore($apiClientId)
                    ->whereNull('deleted_at'),
            ],

            'type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom']),
            ],

            'status' => ['sometimes', 'boolean'],

            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['required', 'string', 'max:255'],

            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'required',
                'string',
                'max:150',
                'regex:/^(\*|[a-z0-9_-]+|\*)(\.([a-z0-9_-]+|\*))*$/i',
            ],

            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],

            'requires_signature' => ['sometimes', 'boolean'],

            'description' => ['nullable', 'string', 'max:1000'],
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

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}