<?php

namespace App\Http\Requests;

use App\Models\CustomWidget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->json()->all();

        if (empty($payload) && $this->getContent()) {
            $decoded = json_decode($this->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (!empty($payload)) {
            $this->merge($payload);
        }

        if ($this->has('post_type')) {
            $this->merge([
                'post_type' => strtolower(trim($this->post_type)),
            ]);
        }
    }

    public function rules(): array
    {
        $widgetId = $this->route('id') ?? $this->route('custom_widget');

        return [
            'widget_name' => [
                'required',
                'string',
                'max:255',
            ],

            'post_type' => [
                'required',
                Rule::in(CustomWidget::postTypes()),
            ],

            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('custom_widgets', 'slug')->ignore($widgetId),
            ],

            'configurations' => [
                'nullable',
                'array',
            ],

            'configurations.*.field_key' => [
                'required_with:configurations',
                'string',
                'max:255',
            ],

            'configurations.*.field_value' => [
                'nullable',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'widget_name.required' => 'Widget name is required.',
            'post_type.required' => 'Post type is required.',
            'post_type.in' => 'Invalid post type selected.',
            'slug.unique' => 'The slug has already been taken.',
        ];
    }
}