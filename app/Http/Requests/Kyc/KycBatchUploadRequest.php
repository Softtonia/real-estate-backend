<?php

namespace App\Http\Requests\Kyc;

use App\Models\KycDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Validator;

class KycBatchUploadRequest extends FormRequest
{
    private const MAX_UPLOAD_KB = 5120;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAadhaarNumber();
        $this->normalizeGstNumber();
    }

    public function rules(): array
    {
        return [
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

            'aadhaar_front' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            'aadhaar_back' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            /*
             * Business proof is conditionally required
             * inside withValidator().
             */
            'business_proof' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            /*
             * GST certificate is required only when
             * gst_number is provided.
             */
            'gst_certificate' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            /*
             * RERA certificate is required only when
             * rera_number is provided.
             */
            'rera_certificate' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            'other_documents' => [
                'nullable',
                'array',
                'max:5',
            ],

            'other_documents.*' => [
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $isOwner = $this->isOwnerCurrentUser();

            if ($isOwner) {
                $blockedFields = [
                    'business_name',
                    'gst_number',
                    'rera_number',
                    'business_proof',
                    'gst_certificate',
                    'rera_certificate',
                ];

                foreach ($blockedFields as $field) {
                    if (
                        $this->filled($field)
                        || $this->hasFile($field)
                    ) {
                        $validator->errors()->add(
                            $field,
                            'Owner role cannot submit business KYC details.'
                        );
                    }
                }

                return;
            }

            /*
             * Non-owner/business role requirements.
             */
            if (!$this->filled('business_name')) {
                $validator->errors()->add(
                    'business_name',
                    'Business name is required.'
                );
            }

            if (!$this->hasFile('business_proof')) {
                $validator->errors()->add(
                    'business_proof',
                    'Business proof is required.'
                );
            }

            /*
             * GST number and certificate must be sent together.
             */
            if (
                $this->filled('gst_number')
                && !$this->hasFile('gst_certificate')
            ) {
                $validator->errors()->add(
                    'gst_certificate',
                    'GST certificate is required when GST number is provided.'
                );
            }

            if (
                $this->hasFile('gst_certificate')
                && !$this->filled('gst_number')
            ) {
                $validator->errors()->add(
                    'gst_number',
                    'GST number is required when GST certificate is uploaded.'
                );
            }

            /*
             * RERA number and certificate must be sent together.
             */
            if (
                $this->filled('rera_number')
                && !$this->hasFile('rera_certificate')
            ) {
                $validator->errors()->add(
                    'rera_certificate',
                    'RERA certificate is required when RERA number is provided.'
                );
            }

            if (
                $this->hasFile('rera_certificate')
                && !$this->filled('rera_number')
            ) {
                $validator->errors()->add(
                    'rera_number',
                    'RERA number is required when RERA certificate is uploaded.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'aadhaar_number.digits' =>
                'Aadhaar number must contain exactly 12 digits.',

            'aadhaar_front.required' =>
                'Aadhaar front document is required.',

            'aadhaar_back.required' =>
                'Aadhaar back document is required.',

            'gst_number.size' =>
                'GST number must be exactly 15 characters.',

            'gst_number.regex' =>
                'GST number format is invalid.',

            '*.max' =>
                'Uploaded file must not exceed 5MB.',

            '*.mimes' =>
                'Only JPG, JPEG, PNG, and PDF files are allowed.',
        ];
    }

    public function kycDocumentFiles(): array
    {
        $files = [];

        foreach ($this->documentFieldMap() as $field => $type) {
            if (!$this->hasFile($field)) {
                continue;
            }

            $files[$type] = [
                'document_type' => $type,
                'file' => $this->file($field),
            ];
        }

        if ($this->hasFile('other_documents')) {
            foreach (
                (array) $this->file('other_documents')
                as $index => $file
            ) {
                $fileKey = KycDocument::TYPE_OTHER
                    . '_'
                    . $index;

                $files[$fileKey] = [
                    'document_type' => KycDocument::TYPE_OTHER,
                    'file' => $file,
                ];
            }
        }

        return $files;
    }

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
            ->select(['id', 'name'])
            ->where('id', $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleName = strtolower(
            trim((string) $role->name)
        );

        $roleName = str_replace(
            [' ', '_', '-'],
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
            || !Schema::hasColumn('users', 'api_token')
        ) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }
}