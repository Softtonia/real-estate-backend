<?php

namespace App\Http\Requests\Admin\Notification;

use Illuminate\Foundation\Http\FormRequest;

class RetryFailedNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'log_ids' => ['sometimes', 'array'],
            'log_ids.*' => ['integer', 'exists:notification_logs,id'],

            'include_skipped' => ['sometimes', 'boolean'],
            'include_inactive_devices' => ['sometimes', 'boolean'],
        ];
    }
}