<?php

namespace App\Http\Requests\Membership\Admin;

use App\Http\Requests\Concerns\ResolvesRouteModelId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipCategoryRequest extends FormRequest
{
    use ResolvesRouteModelId;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->routeModelId([
            'category',
            'membershipCategory',
            'membership_category',
            'id',
        ]);

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_categories', 'slug')->ignore($categoryId),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}