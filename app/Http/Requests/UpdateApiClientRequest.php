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
        if ($this->has('type')) {
            $this->merge([
                'type' => $this->normalizeType($this->input('type')),
            ]);
        }
    }

    private function normalizeType($type): string
    {
        $type = strtolower(trim((string) $type));

        $type = preg_replace('/\s+/', '-', $type);
        $type = preg_replace('/[^a-z0-9_-]/', '-', $type);
        $type = preg_replace('/-+/', '-', $type);

        return trim($type, '-_');
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
                'sometimes',
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
                'min:2',
                'max:80',
                'regex:/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/i',
            ],

            'status' => ['sometimes', 'boolean'],

            'allowed_origins' => ['sometimes', 'nullable', 'array'],

            'allowed_origins.*' => [
                'required',
                'string',
                'max:255',
                'regex:#^(https?)://([^/\s:]+|\*\.[^/\s:]+)(?::(\d+|\*))?$#i',
            ],

            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => [
                'required',
                'string',
                'max:150',
                'regex:/^(\*|[a-z0-9_-]+|\*)(\.([a-z0-9_-]+|\*))*$/i',
            ],

            'rate_limit_per_minute' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],

            'requires_signature' => ['sometimes', 'boolean'],

            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
