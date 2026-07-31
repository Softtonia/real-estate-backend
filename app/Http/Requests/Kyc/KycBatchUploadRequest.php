<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class KycBatchUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $optionalFileRules = [
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,webp,pdf',
            'max:10240',
        ];

        return [
            /*
             * Dynamic document format:
             *
             * documents[0][document_type]
             * documents[0][file]
             */
            'documents' => [
                'nullable',
                'array',
                'max:10',
            ],

            'documents.*.document_type' => [
                'required_with:documents.*.file',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],

            'documents.*.file' => [
                'required_with:documents.*.document_type',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],

            /*
             * Old frontend flat file names are also supported.
             * These are file input names only. They are not
             * user_details database columns.
             */
            'aadhaar_front' => $optionalFileRules,
            'aadhaar_back' => $optionalFileRules,
            'business_proof' => $optionalFileRules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (empty($this->kycDocumentFiles())) {
                $validator->errors()->add(
                    'documents',
                    'At least one KYC document file is required.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'documents.*.document_type.required_with' =>
                'Document type is required for every uploaded file.',

            'documents.*.file.required_with' =>
                'Document file is required.',

            'documents.*.file.mimes' =>
                'KYC document must be JPG, JPEG, PNG, WEBP, or PDF.',

            'documents.*.file.max' =>
                'Each KYC document must not exceed 10MB.',

            'aadhaar_front.mimes' =>
                'Aadhaar front must be JPG, JPEG, PNG, WEBP, or PDF.',

            'aadhaar_back.mimes' =>
                'Aadhaar back must be JPG, JPEG, PNG, WEBP, or PDF.',

            'business_proof.mimes' =>
                'Business proof must be JPG, JPEG, PNG, WEBP, or PDF.',
        ];
    }

    /**
     * Return files in the format required by
     * KycUploadProgressService and KycBatchUploadService.
     */
    public function kycDocumentFiles(): array
    {
        $files = [];

        /*
         * Dynamic nested documents.
         */
        $documentInputs = (array) $this->input(
            'documents',
            []
        );

        $documentFiles = (array) $this->file(
            'documents',
            []
        );

        foreach ($documentFiles as $index => $fileRow) {
            $uploadedFile = is_array($fileRow)
                ? ($fileRow['file'] ?? null)
                : null;

            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }

            $documentType = data_get(
                $documentInputs,
                $index . '.document_type'
            );

            $documentType = strtolower(
                trim((string) $documentType)
            );

            if ($documentType === '') {
                continue;
            }

            $fileKey = 'document_' . $index;

            $files[$fileKey] = [
                'document_type' => $documentType,
                'file' => $uploadedFile,
            ];
        }

        /*
         * Existing frontend flat fields.
         */
        $flatFields = [
            'aadhaar_front' => 'aadhaar_front',
            'aadhaar_back' => 'aadhaar_back',
            'business_proof' => 'business_proof',
        ];

        foreach ($flatFields as $field => $documentType) {
            $uploadedFile = $this->file($field);

            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }

            $files[$field] = [
                'document_type' => $documentType,
                'file' => $uploadedFile,
            ];
        }

        return $files;
    }
}