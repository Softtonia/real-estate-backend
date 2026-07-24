<?php

namespace App\Http\Requests\Kyc;

use App\Models\KycDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $action = $this->route('review_action') ?: $this->input('action');

        if ($action) {
            $action = strtolower(trim((string) $action));
            $action = str_replace('-', '_', $action);

            $this->merge([
                'action' => $action,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'action' => [
                'required',
                Rule::in([
                    'start_review',
                    'approve',
                    'reject',
                ]),
            ],

            'rejection_reason' => [
                'nullable',
                'string',
                'min:3',
                'max:2000',
            ],

            'reviewer_notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'documents' => [
                'nullable',
                'array',
            ],

            'documents.*.id' => [
                'required_with:documents',
                'integer',
                'exists:kyc_documents,id',
            ],

            'documents.*.status' => [
                'required_with:documents',
                Rule::in(KycDocument::statuses()),
            ],

            'documents.*.rejection_reason' => [
                'nullable',
                'string',
                'min:3',
                'max:1000',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('action') === 'reject' && !$this->filled('rejection_reason')) {
                $validator->errors()->add(
                    'rejection_reason',
                    'Rejection reason is required when rejecting KYC.'
                );
            }

            foreach ((array) $this->input('documents', []) as $index => $document) {
                $status = $document['status'] ?? null;
                $reason = $document['rejection_reason'] ?? null;

                if ($status === KycDocument::STATUS_REJECTED && empty($reason)) {
                    $validator->errors()->add(
                        "documents.$index.rejection_reason",
                        'Document rejection reason is required when document status is rejected.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Review action is required.',
            'action.in' => 'Review action must be start_review, approve, or reject.',
            'documents.*.id.exists' => 'Selected KYC document does not exist.',
        ];
    }
}
