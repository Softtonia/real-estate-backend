<?php

namespace App\Http\Requests\Admin\Notification;

use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

        foreach (['title', 'body', 'fcm_token', 'image_url'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize notification payload data
        |--------------------------------------------------------------------------
        | Admin panel should send:
        | data.type
        | data.screen
        |
        | If old API sends no data, default it to general/home.
        */
        if (! isset($data['data']) || $data['data'] === null || $data['data'] === '') {
            $data['data'] = [];
        }

        if (is_string($data['data'])) {
            $decoded = json_decode($data['data'], true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data['data'] = $decoded;
            }
        }

        if (is_array($data['data'])) {
            if (isset($data['data']['type']) && is_string($data['data']['type'])) {
                $data['data']['type'] = strtolower(trim($data['data']['type']));
            }

            if (isset($data['data']['screen']) && is_string($data['data']['screen'])) {
                $data['data']['screen'] = strtolower(trim($data['data']['screen']));
            }

            if (isset($data['data']['url']) && is_string($data['data']['url'])) {
                $data['data']['url'] = trim($data['data']['url']);
            }

            $data['data']['type'] = $data['data']['type'] ?? 'general';
            $data['data']['screen'] = $data['data']['screen'] ?? 'home';
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'template_id' => [
                'nullable',
                'integer',
                'exists:notification_templates,id',
            ],

            'title' => [
                'nullable',
                'string',
                'max:255',
            ],

            'body' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'image_url' => [
                'nullable',
                'url',
                'max:1000',
            ],

            'channel' => [
                'nullable',
                Rule::in(NotificationTemplate::CHANNELS),
            ],

            'target_type' => [
                'required',
                Rule::in(NotificationBatch::TARGETS),
            ],

            /*
            |--------------------------------------------------------------------------
            | Notification click payload
            |--------------------------------------------------------------------------
            */
            'data' => [
                'nullable',
                'array',
            ],

            'data.type' => [
                'nullable',
                'string',
                'max:100',
            ],

            'data.screen' => [
                'nullable',
                'string',
                'max:100',
            ],

            'data.property_id' => [
                'nullable',
                'integer',
            ],

            'data.membership_id' => [
                'nullable',
                'integer',
            ],

            'data.order_id' => [
                'nullable',
                'integer',
            ],

            'data.lead_id' => [
                'nullable',
                'integer',
            ],

            'data.ticket_id' => [
                'nullable',
                'integer',
            ],

            'data.url' => [
                'nullable',
                'url',
                'max:1000',
            ],

            'data.link' => [
                'nullable',
                'url',
                'max:1000',
            ],

            'data.click_url' => [
                'nullable',
                'url',
                'max:1000',
            ],

            'data.icon' => [
                'nullable',
                'url',
                'max:1000',
            ],

            'data.icon_url' => [
                'nullable',
                'url',
                'max:1000',
            ],

            'data.badge' => [
                'nullable',
                'url',
                'max:1000',
            ],

            'scheduled_at' => [
                'nullable',
                'date',
                'after_or_equal:now',
            ],

            /*
            |--------------------------------------------------------------------------
            | target_type = user
            |--------------------------------------------------------------------------
            */
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | target_type = users
            |--------------------------------------------------------------------------
            */
            'user_ids' => [
                'nullable',
                'array',
            ],

            'user_ids.*' => [
                'integer',
                'distinct',
                'exists:users,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | target_type = role
            |--------------------------------------------------------------------------
            */
            'role_ids' => [
                'nullable',
                'array',
            ],

            'role_ids.*' => [
                'integer',
                'distinct',
            ],

            /*
            |--------------------------------------------------------------------------
            | target_type = topic
            |--------------------------------------------------------------------------
            */
            'topic_id' => [
                'nullable',
                'integer',
                'exists:notification_topics,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | target_type = token
            |--------------------------------------------------------------------------
            */
            'fcm_token' => [
                'nullable',
                'string',
                'max:512',
            ],

            'platform' => [
                'nullable',
                'string',
                Rule::in(['android', 'ios', 'web']),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateTitleAndBody($validator);
            $this->validateTargetPayload($validator);
            $this->validateNotificationPayloadData($validator);
        });
    }

    private function validateTitleAndBody(Validator $validator): void
    {
        $hasTemplate = $this->filled('template_id');

        if (! $hasTemplate && ! $this->filled('title')) {
            $validator->errors()->add(
                'title',
                'Title is required when template_id is not provided.'
            );
        }

        if (! $hasTemplate && ! $this->filled('body')) {
            $validator->errors()->add(
                'body',
                'Body is required when template_id is not provided.'
            );
        }
    }

    private function validateTargetPayload(Validator $validator): void
    {
        match ($this->input('target_type')) {
            NotificationBatch::TARGET_USER => $this->validateRequired($validator, 'user_id'),
            NotificationBatch::TARGET_USERS => $this->validateArrayRequired($validator, 'user_ids'),
            NotificationBatch::TARGET_ROLE => $this->validateArrayRequired($validator, 'role_ids'),
            NotificationBatch::TARGET_TOPIC => $this->validateRequired($validator, 'topic_id'),
            NotificationBatch::TARGET_TOKEN => $this->validateRequired($validator, 'fcm_token'),
            default => null,
        };
    }

    private function validateNotificationPayloadData(Validator $validator): void
    {
        $data = $this->input('data', []);

        if (! is_array($data)) {
            $validator->errors()->add(
                'data',
                'Notification data must be a valid object.'
            );

            return;
        }

        $type = $data['type'] ?? null;
        $screen = $data['screen'] ?? null;

        $types = config('notification_payload.types', []);

        if (! is_string($type) || $type === '' || ! isset($types[$type])) {
            $validator->errors()->add(
                'data.type',
                'Invalid notification type.'
            );

            return;
        }

        if (
            ! is_string($screen)
            || $screen === ''
            || ! isset($types[$type]['screens'][$screen])
        ) {
            $validator->errors()->add(
                'data.screen',
                'Invalid notification screen for selected type.'
            );

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Important:
    |--------------------------------------------------------------------------
    | We only validate type and screen.
    | Extra fields like property_id, order_id, lead_id, membership_id are optional.
    */
    }

    private function validateRequired(Validator $validator, string $field): void
    {
        if (! $this->filled($field)) {
            $validator->errors()->add(
                $field,
                "{$field} is required for selected target_type."
            );
        }
    }

    private function validateArrayRequired(Validator $validator, string $field): void
    {
        $value = $this->input($field);

        if (! is_array($value) || count($value) === 0) {
            $validator->errors()->add(
                $field,
                "{$field} is required for selected target_type."
            );
        }
    }
}
