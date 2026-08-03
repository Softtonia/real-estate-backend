<?php

namespace App\Http\Requests\Admin\Notification;

use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach (['target_type', 'channel', 'platform'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = strtolower(trim($data[$field]));
            }
        }

        if (isset($data['title']) && is_string($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        if (isset($data['body']) && is_string($data['body'])) {
            $data['body'] = trim($data['body']);
        }

        if (isset($data['fcm_token']) && is_string($data['fcm_token'])) {
            $data['fcm_token'] = trim($data['fcm_token']);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'template_id' => ['nullable', 'integer', 'exists:notification_templates,id'],

            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['nullable', 'url', 'max:1000'],

            'channel' => [
                'nullable',
                Rule::in(NotificationTemplate::CHANNELS),
            ],

            'target_type' => [
                'required',
                Rule::in(NotificationBatch::TARGETS),
            ],

            'data' => ['nullable', 'array'],

            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],

            // target_type = user
            'user_id' => ['nullable', 'integer', 'exists:users,id'],

            // target_type = users
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],

            // target_type = role
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer'],

            // target_type = topic
            'topic_id' => ['nullable', 'integer', 'exists:notification_topics,id'],

            // target_type = token
            'fcm_token' => ['nullable', 'string', 'max:512'],
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'web'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasTemplate = $this->filled('template_id');

            if (! $hasTemplate && ! $this->filled('title')) {
                $validator->errors()->add('title', 'Title is required when template_id is not provided.');
            }

            if (! $hasTemplate && ! $this->filled('body')) {
                $validator->errors()->add('body', 'Body is required when template_id is not provided.');
            }

            match ($this->input('target_type')) {
                NotificationBatch::TARGET_USER => $this->validateRequired($validator, 'user_id'),
                NotificationBatch::TARGET_USERS => $this->validateArrayRequired($validator, 'user_ids'),
                NotificationBatch::TARGET_ROLE => $this->validateArrayRequired($validator, 'role_ids'),
                NotificationBatch::TARGET_TOPIC => $this->validateRequired($validator, 'topic_id'),
                NotificationBatch::TARGET_TOKEN => $this->validateRequired($validator, 'fcm_token'),
                default => null,
            };
        });
    }

    private function validateRequired($validator, string $field): void
    {
        if (! $this->filled($field)) {
            $validator->errors()->add($field, "{$field} is required for selected target_type.");
        }
    }

    private function validateArrayRequired($validator, string $field): void
    {
        if (! is_array($this->input($field)) || count($this->input($field)) === 0) {
            $validator->errors()->add($field, "{$field} is required for selected target_type.");
        }
    }
}