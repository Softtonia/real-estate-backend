<?php

namespace App\Http\Requests\Kyc;

use App\Models\KycDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycRoleRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['requires_kyc', 'can_publish_without_kyc', 'is_active'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $this->merge([
                $field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],

            'requires_kyc' => [
                'required',
                'boolean',
            ],

            'can_publish_without_kyc' => [
                'required',
                'boolean',
            ],

            'required_documents' => [
                'nullable',
                'array',
            ],

            'required_documents.*' => [
                'string',
                Rule::in(KycDocument::documentTypes()),
            ],

            'is_active' => [
                'required',
                'boolean',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.required' => 'Role is required.',
            'role_id.exists' => 'Selected role does not exist.',
            'required_documents.*.in' => 'Invalid KYC document type selected.',
        ];
    }
}