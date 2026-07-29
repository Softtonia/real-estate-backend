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
                'sometimes',
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

    private function resolveCategoryId(): mixed
    {
        $category = $this->route('category')
            ?? $this->route('membershipCategory')
            ?? $this->route('membership_category')
            ?? $this->route('id');

        if ($category instanceof MembershipCategory) {
            return $category->id;
        }

        if (is_object($category) && method_exists($category, 'getKey')) {
            return $category->getKey();
        }

        if (is_object($category) && isset($category->id)) {
            return $category->id;
        }

        return $category;
    }
}