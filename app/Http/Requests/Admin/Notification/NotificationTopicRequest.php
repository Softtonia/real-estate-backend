<?php

namespace App\Http\Requests\Admin\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (! empty($data['name']) && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        if (! empty($data['slug']) && is_string($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $topicId = $this->resolveTopicId();
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH'], true);

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:191'],

            'slug' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:191',
                Rule::unique('notification_topics', 'slug')->ignore($topicId, 'id'),
            ],

            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    private function resolveTopicId(): ?int
    {
        $topic = $this->route('topic');

        if (is_object($topic) && isset($topic->id)) {
            return (int) $topic->id;
        }

        return is_numeric($topic) ? (int) $topic : null;
    }
}