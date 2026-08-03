<?php

namespace App\Http\Requests\Admin\Notification;

use Illuminate\Foundation\Http\FormRequest;

class FirebaseConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (array_key_exists('project_id', $data) && is_string($data['project_id'])) {
            $data['project_id'] = trim($data['project_id']);
        }

        if (array_key_exists('client_email', $data) && is_string($data['client_email'])) {
            $data['client_email'] = trim($data['client_email']);
        }

        if (array_key_exists('private_key_id', $data) && is_string($data['private_key_id'])) {
            $data['private_key_id'] = trim($data['private_key_id']);
        }

        if (array_key_exists('service_account_json', $data) && is_string($data['service_account_json'])) {
            $decoded = json_decode($data['service_account_json'], true);

            if (is_array($decoded)) {
                $data['service_account_json'] = $decoded;
            }
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],

            'project_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'private_key' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'private_key_id' => ['sometimes', 'nullable', 'string', 'max:255'],

            'service_account_json' => ['sometimes', 'nullable', 'array'],
            'service_account_json.project_id' => ['nullable', 'string', 'max:255'],
            'service_account_json.client_email' => ['nullable', 'email', 'max:255'],
            'service_account_json.private_key' => ['nullable', 'string', 'max:10000'],
            'service_account_json.private_key_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}