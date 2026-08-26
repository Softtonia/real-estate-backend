<?php

namespace App\Http\Requests\Kyc;

use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Validator;

class KycBatchUploadRequest extends FormRequest
{
    private const MAX_UPLOAD_KB = 10240;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAadhaarNumber();
        $this->normalizeGstNumber();
        $this->normalizeNullableTextFields();
    }

    public function rules(): array
    {
        $optionalFileRules = [
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,webp,pdf',
            'max:' . self::MAX_UPLOAD_KB,
        ];

        return [
            /*
             * KYC metadata.
             *
             * These fields are stored in kyc_requests,
             * not in user_details.
             */
            'aadhaar_number' => [
                'nullable',
                'digits:12',
            ],

            'business_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'gst_number' => [
                'nullable',
                'string',
                'size:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
            ],

            'rera_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],

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
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],

            'documents.*.file' => [
                'required_with:documents.*.document_type',
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            /*
             * Existing flat frontend file fields.
             */
            'aadhaar_front' => $optionalFileRules,
            'aadhaar_back' => $optionalFileRules,
            'business_proof' => $optionalFileRules,
            'gst_certificate' => $optionalFileRules,
            'rera_certificate' => $optionalFileRules,

            /*
             * Multiple additional documents.
             */
            'other_documents' => [
                'nullable',
                'array',
                'max:5',
            ],

            'other_documents.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $files = $this->kycDocumentFiles();

            if (empty($files)) {
                $validator->errors()->add(
                    'documents',
                    'At least one KYC document file is required.'
                );

                return;
            }

            /*
             * GST number and GST certificate must be provided together.
             *
             * NOTE: hasDocumentType() checks both newly uploaded files in this
             * request AND documents already saved in the DB for the user's
             * active KYC request, so resubmissions that carry over a
             * previously approved GST certificate are not falsely rejected.
             */
            $hasGstNumber = $this->filled('gst_number');
            $hasGstCertificate = $this->hasDocumentType(
                KycDocument::TYPE_GST_CERTIFICATE
            );

            if ($hasGstNumber && !$hasGstCertificate) {
                $validator->errors()->add(
                    'gst_certificate',
                    'GST certificate is required when GST number is provided.'
                );
            }

            if ($hasGstCertificate && !$hasGstNumber) {
                $validator->errors()->add(
                    'gst_number',
                    'GST number is required when GST certificate is uploaded.'
                );
            }

            /*
             * RERA number and RERA certificate must be provided together.
             */
            $hasReraNumber = $this->filled('rera_number');
            $hasReraCertificate = $this->hasDocumentType(
                KycDocument::TYPE_RERA_CERTIFICATE
            );

            if ($hasReraNumber && !$hasReraCertificate) {
                $validator->errors()->add(
                    'rera_certificate',
                    'RERA certificate is required when RERA number is provided.'
                );
            }

            if ($hasReraCertificate && !$hasReraNumber) {
                $validator->errors()->add(
                    'rera_number',
                    'RERA number is required when RERA certificate is uploaded.'
                );
            }

            /*
             * Current business rule:
             * Owner cannot submit business KYC details/documents.
             */
            if ($this->isOwnerCurrentUser()) {
                $blockedMetadata = [
                    'business_name',
                    'gst_number',
                    'rera_number',
                ];

                foreach ($blockedMetadata as $field) {
                    if ($this->filled($field)) {
                        $validator->errors()->add(
                            $field,
                            'Owner role cannot submit business KYC details.'
                        );
                    }
                }

                $blockedDocumentTypes = [
                    KycDocument::TYPE_BUSINESS_PROOF,
                    KycDocument::TYPE_GST_CERTIFICATE,
                    KycDocument::TYPE_RERA_CERTIFICATE,
                ];

                foreach ($blockedDocumentTypes as $documentType) {
                    if ($this->hasDocumentType($documentType)) {
                        $validator->errors()->add(
                            $documentType,
                            'Owner role cannot upload business KYC documents.'
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'aadhaar_number.digits' =>
                'Aadhaar number must contain exactly 12 digits.',

            'gst_number.size' =>
                'GST number must contain exactly 15 characters.',

            'gst_number.regex' =>
                'GST number format is invalid.',

            'documents.max' =>
                'A maximum of 10 dynamic documents can be uploaded.',

            'documents.*.document_type.required_with' =>
                'Document type is required for every uploaded document.',

            'documents.*.document_type.regex' =>
                'Document type may contain only letters, numbers, hyphens, and underscores.',

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

            'gst_certificate.mimes' =>
                'GST certificate must be JPG, JPEG, PNG, WEBP, or PDF.',

            'rera_certificate.mimes' =>
                'RERA certificate must be JPG, JPEG, PNG, WEBP, or PDF.',

            'other_documents.max' =>
                'A maximum of 5 other documents can be uploaded.',

            'other_documents.*.mimes' =>
                'Other documents must be JPG, JPEG, PNG, WEBP, or PDF.',

            '*.max' =>
                'Uploaded file must not exceed 10MB.',
        ];
    }

    /**
     * Return all uploaded KYC documents in the format:
     *
     * [
     *     'aadhaar_front' => [
     *         'document_type' => 'aadhaar_front',
     *         'file' => UploadedFile,
     *     ],
     * ]
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

            $fileKey = 'document_'
                . $index
                . '_'
                . $documentType;

            $files[$fileKey] = [
                'document_type' => $documentType,
                'file' => $uploadedFile,
            ];
        }

        /*
         * Flat frontend fields.
         */
        foreach ($this->documentFieldMap() as $field => $documentType) {
            $uploadedFile = $this->file($field);

            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }

            $files[$field] = [
                'document_type' => $documentType,
                'file' => $uploadedFile,
            ];
        }

        /*
         * Other documents.
         */
        $otherDocuments = $this->file(
            'other_documents',
            []
        );

        if (!is_array($otherDocuments)) {
            $otherDocuments = [];
        }

        foreach ($otherDocuments as $index => $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }

            $fileKey = KycDocument::TYPE_OTHER
                . '_'
                . $index;

            $files[$fileKey] = [
                'document_type' => KycDocument::TYPE_OTHER,
                'file' => $uploadedFile,
            ];
        }

        return $files;
    }

    /**
     * Map flat request field names to document types.
     */
    public function documentFieldMap(): array
    {
        return [
            'aadhaar_front' =>
                KycDocument::TYPE_AADHAAR_FRONT,

            'aadhaar_back' =>
                KycDocument::TYPE_AADHAAR_BACK,

            'business_proof' =>
                KycDocument::TYPE_BUSINESS_PROOF,

            'gst_certificate' =>
                KycDocument::TYPE_GST_CERTIFICATE,

            'rera_certificate' =>
                KycDocument::TYPE_RERA_CERTIFICATE,
        ];
    }

    private function hasDocumentType(string $documentType): bool
    {
        // 1. Check files being uploaded in the current request.
        foreach ($this->kycDocumentFiles() as $filePayload) {
            if (($filePayload['document_type'] ?? null) === $documentType) {
                return true;
            }
        }

        // 2. Check documents already stored in the DB across any of the user's
        //    active KYC requests (draft or rejected).
        //
        //    WHY: On resubmission the batch-upload service creates a new DRAFT
        //    request and copies non-rejected docs from the previous request into
        //    it BEFORE this validator runs — so the DRAFT already contains them.
        //    We also fall back to checking the rejected request directly to be
        //    safe against race conditions or future flow changes.
        $user = $this->currentUser();

        if ($user && Schema::hasTable('kyc_requests') && Schema::hasTable('kyc_documents')) {
            $exists = KycDocument::query()
                ->whereHas('kycRequest', function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->whereIn('status', [
                            KycRequest::STATUS_DRAFT,
                            KycRequest::STATUS_REJECTED,
                        ]);
                })
                ->where('document_type', $documentType)
                ->whereIn('status', [
                    KycDocument::STATUS_APPROVED,
                    KycDocument::STATUS_PENDING,
                ])
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    private function normalizeAadhaarNumber(): void
    {
        $acceptedKeys = [
            'aadhaar_number',
            'aadhaar_no',
            'aadhaar',
            'aadhaarNumber',
            'aadhar_number',
            'aadhar_no',
            'aadhar',
            'aadharNumber',
            'adhar_number',
            'adhar_no',
            'adhar',
            'adharNumber',
        ];

        foreach ($acceptedKeys as $key) {
            if (!$this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if ($value !== null && $value !== '') {
                $value = preg_replace(
                    '/\D+/',
                    '',
                    (string) $value
                );
            }

            $this->merge([
                'aadhaar_number' => $value ?: null,
            ]);

            return;
        }
    }

    private function normalizeGstNumber(): void
    {
        if (!$this->has('gst_number')) {
            return;
        }

        $value = strtoupper(
            trim((string) $this->input('gst_number'))
        );

        $this->merge([
            'gst_number' => $value !== ''
                ? $value
                : null,
        ]);
    }

    private function normalizeNullableTextFields(): void
    {
        foreach (
            [
                'business_name',
                'rera_number',
                'remarks',
            ] as $field
        ) {
            if (!$this->has($field)) {
                continue;
            }

            $value = trim(
                (string) $this->input($field)
            );

            $this->merge([
                $field => $value !== ''
                    ? $value
                    : null,
            ]);
        }
    }

    private function isOwnerCurrentUser(): bool
    {
        $user = $this->currentUser();

        if (
            !$user
            || empty($user->role_id)
            || !Schema::hasTable('roles')
        ) {
            return false;
        }

        $role = Role::query()
            ->select([
                'id',
                'name',
            ])
            ->where('id', $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleName = strtolower(
            trim((string) $role->name)
        );

        $roleName = str_replace(
            [
                ' ',
                '_',
                '-',
            ],
            '',
            $roleName
        );

        return in_array(
            $roleName,
            [
                'owner',
                'propertyowner',
                'landowner',
            ],
            true
        );
    }

    private function currentUser(): ?User
    {
        $authUser = Auth::user();

        if ($authUser instanceof User) {
            return $authUser;
        }

        $token = $this->bearerToken()
            ?: $this->header('api-token')
            ?: $this->header('api_token')
            ?: $this->input('api_token');

        if (
            !$token
            || !Schema::hasColumn(
                'users',
                'api_token'
            )
        ) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }
}