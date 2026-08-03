<?php

namespace App\Http\Requests\Admin\Notification;

use App\Models\Notification\NotificationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['template_key']) && is_string($data['template_key'])) {
            $data['template_key'] = strtolower(trim($data['template_key']));
            $data['template_key'] = preg_replace('/[^a-z0-9_\-\.]/', '_', $data['template_key']);
        }

        if (isset($data['channel']) && is_string($data['channel'])) {
            $data['channel'] = strtolower(trim($data['channel']));
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $templateId = $this->resolveTemplateId();

        $isUpdate = in_array($this->method(), ['PUT', 'PATCH'], true);

        return [
            'template_key' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:191',
                Rule::unique('notification_templates', 'template_key')
                    ->ignore($templateId, 'id'),
            ],

            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'body' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:2000'],

            'image_url' => ['sometimes', 'nullable', 'url', 'max:1000'],

            'data' => ['sometimes', 'nullable', 'array'],

            'channel' => [
                'sometimes',
                Rule::in(NotificationTemplate::CHANNELS),
            ],

            'status' => ['sometimes', 'boolean'],
        ];
    }

    private function resolveTemplateId(): ?int
    {
        $routeTemplate = $this->route('template');

        if (is_object($routeTemplate) && isset($routeTemplate->id)) {
            return (int) $routeTemplate->id;
        }

        if (is_numeric($routeTemplate)) {
            return (int) $routeTemplate;
        }

        return null;
    }
}