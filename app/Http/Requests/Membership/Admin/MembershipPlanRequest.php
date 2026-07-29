<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        /*
        |--------------------------------------------------------------------------
        | Support both category_id and membership_category_id
        |--------------------------------------------------------------------------
        */
        $categoryId = $data['category_id']
            ?? $data['membership_category_id']
            ?? null;

        if ($categoryId !== null && $categoryId !== '') {
            $data['category_id'] = (int) $categoryId;
            $data['membership_category_id'] = (int) $categoryId;
        }

        /*
        |--------------------------------------------------------------------------
        | Support duration_days and old duration + duration_type
        |--------------------------------------------------------------------------
        */
        if (! empty($data['duration_days'])) {
            $data['duration_days'] = (int) $data['duration_days'];

            if (empty($data['duration'])) {
                $data['duration'] = (int) $data['duration_days'];
            }

            if (empty($data['duration_type'])) {
                $data['duration_type'] = 'days';
            }
        } elseif (! empty($data['duration']) && ! empty($data['duration_type'])) {
            $data['duration_days'] = $this->durationToDays(
                (int) $data['duration'],
                (string) $data['duration_type']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Slug auto-create
        |--------------------------------------------------------------------------
        */
        if (empty($data['slug']) && ! empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        /*
        |--------------------------------------------------------------------------
        | Support both is_featured and is_popular
        |--------------------------------------------------------------------------
        */
        if (array_key_exists('is_featured', $data) && ! array_key_exists('is_popular', $data)) {
            $data['is_popular'] = $data['is_featured'];
        }

        if (array_key_exists('is_popular', $data) && ! array_key_exists('is_featured', $data)) {
            $data['is_featured'] = $data['is_popular'];
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $planId = $this->resolvePlanId();
        $isCreate = $this->isMethod('post');

        return [
            'category_id' => [
                $isCreate ? 'required' : 'sometimes',
                'integer',
                Rule::exists('membership_categories', 'id'),
            ],

            'membership_category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('membership_categories', 'id'),
            ],

            'name' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:255',
            ],

            'slug' => [
                $isCreate ? 'nullable' : 'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_plans', 'slug')->ignore($planId, 'id'),
            ],

            'description' => ['sometimes', 'nullable', 'string'],

            'price' => [
                $isCreate ? 'required' : 'sometimes',
                'numeric',
                'min:0',
            ],

            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:10'],

            'duration_days' => [
                $isCreate ? 'required' : 'sometimes',
                'integer',
                'min:1',
                'max:36500',
            ],

            'duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'duration_type' => [
                'sometimes',
                'nullable',
                Rule::in(['day', 'days', 'month', 'months', 'year', 'years']),
            ],

            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'nullable', 'boolean'],

            'is_featured' => ['sometimes', 'nullable', 'boolean'],
            'is_popular' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    private function resolvePlanId(): ?int
    {
        $parameters = $this->route()?->parameters() ?? [];

        foreach (['plan', 'membershipPlan', 'membership_plan', 'id'] as $key) {
            if (! array_key_exists($key, $parameters)) {
                continue;
            }

            $value = $parameters[$key];

            if ($value instanceof MembershipPlan) {
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

    private function durationToDays(int $duration, string $durationType): int
    {
        return match (strtolower($durationType)) {
            'day', 'days' => $duration,
            'month', 'months' => $duration * 30,
            'year', 'years' => $duration * 365,
            default => $duration,
        };
    }
}