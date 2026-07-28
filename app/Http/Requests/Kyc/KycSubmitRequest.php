<?php

namespace App\Http\Requests\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\Role;

class KycSubmitRequest extends FormRequest
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
        $userId = $this->currentUserId();

        return [
            'aadhaar_number' => [
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($userId, 'user_id'),
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

            'business_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'aadhaar_front' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            'aadhaar_back' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            'gst_certificate' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            'rera_certificate' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            'business_proof' => [
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

    public function messages(): array
    {
        return [
            'aadhaar_number.required' => 'Aadhaar number is required.',
            'aadhaar_number.digits' => 'Aadhaar number must contain exactly 12 digits.',
            'aadhaar_number.unique' => 'This Aadhaar number is already linked with another user.',

            'gst_number.size' => 'GST number must be exactly 15 characters.',
            'gst_number.regex' => 'GST number format is invalid.',

            '*.max' => 'Uploaded file size must not be greater than 5MB.',
            '*.mimes' => 'Only JPG, JPEG, PNG, and PDF files are allowed.',
        ];
    }

    public function kycDocumentFiles(): array
    {
        $files = [];

        foreach ($this->documentFieldMap() as $field => $type) {
            if ($this->hasFile($field)) {
                $files[$type] = $this->file($field);
            }
        }

        if ($this->hasFile('other_documents')) {
            foreach ((array) $this->file('other_documents') as $index => $file) {
                $files[KycDocument::TYPE_OTHER . '_' . $index] = $file;
            }
        }

        return $files;
    }

    public function documentFieldMap(): array
    {
        return [
            'aadhaar_front' => KycDocument::TYPE_AADHAAR_FRONT,
            'aadhaar_back' => KycDocument::TYPE_AADHAAR_BACK,
            'gst_certificate' => KycDocument::TYPE_GST_CERTIFICATE,
            'rera_certificate' => KycDocument::TYPE_RERA_CERTIFICATE,
            'business_proof' => KycDocument::TYPE_BUSINESS_PROOF,
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
                $value = preg_replace('/\D+/', '', (string) $value);
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

        $this->merge([
            'gst_number' => strtoupper(trim((string) $this->input('gst_number'))),
        ]);
    }

    private function currentUserId(): ?int
    {
        return $this->currentUser()?->id;
    }
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->isOwnerCurrentUser()) {
                return;
            }

            $blockedFields = [
                'business_name',
                'gst_number',
                'rera_number',
                'business_proof',
                'gst_certificate',
                'rera_certificate',
            ];

            foreach ($blockedFields as $field) {
                if ($this->filled($field) || $this->hasFile($field)) {
                    $validator->errors()->add(
                        $field,
                        'Owner role cannot submit business KYC details or business documents.'
                    );
                }
            }
        });
    }

    private function isOwnerCurrentUser(): bool
    {
        $user = $this->currentUser();

        if (!$user || empty($user->role_id) || !Schema::hasTable('roles')) {
            return false;
        }

        $role = Role::query()
            ->select(['id', 'name'])
            ->where('id', $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleName = strtolower(trim((string) $role->name));
        $roleName = str_replace([' ', '_', '-'], '', $roleName);

        return in_array($roleName, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
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

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }
}
