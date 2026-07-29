<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipAddon;
use App\Models\Membership\MembershipCreditBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MembershipAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (empty($data['slug']) && ! empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        if (isset($data['validity_days']) && ! isset($data['duration_days'])) {
            $data['duration_days'] = $data['validity_days'];
        }

        if (isset($data['duration_days']) && ! isset($data['validity_days'])) {
            $data['validity_days'] = $data['duration_days'];
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $addonId = $this->resolveAddonId();
        $isCreate = $this->isMethod('post');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],

            'slug' => [
                $isCreate ? 'nullable' : 'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_addons', 'slug')->ignore($addonId, 'id'),
            ],

            'description' => ['sometimes', 'nullable', 'string'],

            'addon_type' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                Rule::in([
                    'credit',
                    'promotion',
                    'media',
                    'ai',
                    'service',
                    'legal',
                    'marketing',
                    'support',
                    'other',
                ]),
            ],

            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'price' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'lte:price'],

            'credit_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    MembershipCreditBalance::TYPE_LISTING,
                    MembershipCreditBalance::TYPE_FEATURED_LISTING,
                    MembershipCreditBalance::TYPE_BOOST,
                    MembershipCreditBalance::TYPE_LEAD_VIEW,
                    MembershipCreditBalance::TYPE_VIDEO_UPLOAD,
                    MembershipCreditBalance::TYPE_VIRTUAL_TOUR,
                    MembershipCreditBalance::TYPE_AI_DESCRIPTION,
                ]),
            ],

            'credit_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'validity_days' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'status' => ['sometimes', 'nullable', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $addonType = $this->input('addon_type');
            $creditType = $this->input('credit_type');
            $creditQuantity = $this->input('credit_quantity');

            if ($creditType && ! $creditQuantity) {
                $validator->errors()->add(
                    'credit_quantity',
                    'Credit quantity is required when credit type is selected.'
                );
            }

            if ($creditQuantity && ! $creditType) {
                $validator->errors()->add(
                    'credit_type',
                    'Credit type is required when credit quantity is selected.'
                );
            }

            if (($creditType || $creditQuantity) && $addonType && $addonType !== 'credit') {
                $validator->errors()->add(
                    'addon_type',
                    'Addon type must be credit when credit type or credit quantity is selected.'
                );
            }
        });
    }

    private function resolveAddonId(): ?int
    {
        $parameters = $this->route()?->parameters() ?? [];

        foreach (['addon', 'membershipAddon', 'membership_addon', 'id'] as $key) {
            if (! array_key_exists($key, $parameters)) {
                continue;
            }

            $value = $parameters[$key];

            if ($value instanceof MembershipAddon) {
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