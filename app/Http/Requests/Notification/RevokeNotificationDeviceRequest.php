<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class RevokeNotificationDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['fcm_token']) && is_string($data['fcm_token'])) {
            $data['fcm_token'] = trim($data['fcm_token']);
        }

        if (isset($data['device_id']) && is_string($data['device_id'])) {
            $data['device_id'] = trim($data['device_id']);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'fcm_token' => ['nullable', 'string', 'max:512'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('fcm_token') && ! $this->filled('device_id')) {
                $validator->errors()->add(
                    'device',
                    'Either fcm_token or device_id is required.'
                );
            }
        });
    }
}