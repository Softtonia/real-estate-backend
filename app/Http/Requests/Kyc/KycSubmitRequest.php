<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class KycSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('declaration')) {
            $this->merge([
                'declaration' => filter_var(
                    $this->input('declaration'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            /*
             * uploads/start API se returned upload_id.
             */
            'upload_id' => [
                'required',
                'string',
                'max:255',
            ],

            /*
             * Optional declaration.
             * Required karna ho to nullable ko required kar dena.
             */
            'declaration' => [
                'nullable',
                'boolean',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'upload_id.required' =>
                'Upload ID is required before submitting KYC.',

            'upload_id.string' =>
                'Upload ID must be a valid string.',

            'declaration.boolean' =>
                'Declaration must be true or false.',

            'remarks.max' =>
                'Remarks must not exceed 1000 characters.',
        ];
    }

    /**
     * Final submit endpoint files receive nahi karega.
     *
     * Files uploads/start endpoint se upload honge.
     */
    public function kycDocumentFiles(): array
    {
        return [];
    }
}