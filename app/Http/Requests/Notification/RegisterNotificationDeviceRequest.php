<?php

namespace App\Http\Requests\Notification;

use App\Models\Notification\NotificationDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterNotificationDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['platform']) && is_string($data['platform'])) {
            $data['platform'] = strtolower(trim($data['platform']));
        }

        if (isset($data['fcm_token']) && is_string($data['fcm_token'])) {
            $data['fcm_token'] = trim($data['fcm_token']);
        }

        if (isset($data['app_type']) && is_string($data['app_type'])) {
            $data['app_type'] = trim($data['app_type']);
        }

        if (isset($data['device_id']) && is_string($data['device_id'])) {
            $data['device_id'] = trim($data['device_id']);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'fcm_token' => ['required', 'string', 'max:512'],

            'platform' => [
                'required',
                Rule::in(NotificationDevice::PLATFORMS),
            ],

            'app_type' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'device_name' => ['nullable', 'string', 'max:191'],

            'browser' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:100'],
        ];
    }
}