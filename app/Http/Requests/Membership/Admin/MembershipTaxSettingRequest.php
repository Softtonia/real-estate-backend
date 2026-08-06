<?php

namespace App\Http\Requests\Membership\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MembershipTaxSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['tax_label']) && is_string($data['tax_label'])) {
            $data['tax_label'] = strtoupper(trim($data['tax_label']));
        }

        if (isset($data['business_state']) && is_string($data['business_state'])) {
            $data['business_state'] = trim($data['business_state']);
        }

        if (isset($data['gstin']) && is_string($data['gstin'])) {
            $data['gstin'] = strtoupper(trim($data['gstin']));
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'gst_enabled' => ['required', 'boolean'],
            'gst_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['nullable', 'string', 'max:50'],
            'prices_include_tax' => ['required', 'boolean'],
            'business_state' => ['nullable', 'string', 'max:100'],
            'gstin' => ['nullable', 'string', 'max:50'],
        ];
    }
}