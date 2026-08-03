<?php

namespace App\Http\Requests\Admin\Notification;

use App\Models\Notification\NotificationDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FirebaseTestNotificationRequest extends FormRequest
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

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'fcm_token' => ['required', 'string', 'max:512'],

            'platform' => [
                'nullable',
                Rule::in(NotificationDevice::PLATFORMS),
            ],

            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url', 'max:1000'],
            'data' => ['nullable', 'array'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}