<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipCreditBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $addonId = $this->route('addon')?->id ?? $this->route('addon');

        return [
            'name' => ['required', 'string', 'max:255'],

            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_addons', 'slug')->ignore($addonId),
            ],

            'description' => ['nullable', 'string'],

            'addon_type' => [
                'required',
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

            'currency' => ['nullable', 'string', 'size:3'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],

            'credit_type' => [
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

            'credit_quantity' => ['nullable', 'integer', 'min:1'],
            'duration_days' => ['nullable', 'integer', 'min:1'],

            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $creditType = $this->input('credit_type');
            $creditQuantity = $this->input('credit_quantity');

            if ($creditType && !$creditQuantity) {
                $validator->errors()->add(
                    'credit_quantity',
                    'Credit quantity is required when credit type is selected.'
                );
            }

            if ($creditQuantity && !$creditType) {
                $validator->errors()->add(
                    'credit_type',
                    'Credit type is required when credit quantity is selected.'
                );
            }
        });
    }
}