<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->resolveCategoryId();

        return [
            'name' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:255',
            ],
            'slug' => [
                $this->isMethod('post') ? 'nullable' : 'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_categories', 'slug')->ignore($categoryId, 'id'),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    private function resolveCategoryId(): ?int
    {
        $parameters = $this->route()?->parameters() ?? [];

        foreach (['category', 'membershipCategory', 'membership_category', 'id'] as $key) {
            if (! array_key_exists($key, $parameters)) {
                continue;
            }

            $value = $parameters[$key];

            if ($value instanceof MembershipCategory) {
                return (int) $value->getKey();
            }

            if (is_object($value) && method_exists($value, 'getKey')) {
                return (int) $value->getKey();
            }

            if (is_object($value) && isset($value->id)) {
                return (int) $value->id;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        $lastSegment = last($this->segments());

        return is_numeric($lastSegment) ? (int) $lastSegment : null;
    }
}