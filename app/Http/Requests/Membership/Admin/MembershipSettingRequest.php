<?php

namespace App\Http\Requests\Membership\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $settingId = $this->route('setting')?->id ?? $this->route('setting');

        return [
            'key' => [
                'required',
                'string',
                'max:150',
                'regex:/^[a-z0-9_.-]+$/',
                Rule::unique('membership_settings', 'key')->ignore($settingId),
            ],

            'value' => ['nullable'],

            'value_type' => [
                'required',
                Rule::in([
                    'string',
                    'integer',
                    'float',
                    'boolean',
                    'array',
                    'json',
                ]),
            ],

            'is_public' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}