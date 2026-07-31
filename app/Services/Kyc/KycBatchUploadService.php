<?php

namespace App\Services\Kyc;

use App\Http\Requests\Kyc\KycBatchUploadRequest;
use App\Jobs\Kyc\ProcessKycDocumentUploadJob;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class KycBatchUploadService
{
    public function __construct(
        private readonly KycUploadProgressService $progressService
    ) {}

    public function start(
        User $user,
        KycBatchUploadRequest $request
    ): array {
        $files = $this->preparedFiles($request);

        if (empty($files)) {
            throw ValidationException::withMessages([
                'documents' => [
                    'Please upload at least one KYC document.',
                ],
            ]);
        }

        $role = $this->resolveUserRole($user);

        /*
         * Owner business documents upload nahi kar sakta.
         */
        if ($this->isOwnerRole($role?->name)) {
            foreach ($files as $file) {
                if (
                    in_array(
                        $file['document_type'],
                        [
                            KycDocument::TYPE_BUSINESS_PROOF,
                            KycDocument::TYPE_GST_CERTIFICATE,
                            KycDocument::TYPE_RERA_CERTIFICATE,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'documents' => [
                            'Owner role cannot upload business KYC documents.',
                        ],
                    ]);
                }
            }
        }

        $kycRequest = $this->draftRequestForUpload(
            $user,
            $request,
            $role
        );

        $uploadId = 'kyc_'
            . $user->id
            . '_'
            . Str::uuid()->toString();

        $stagedFiles = [];
        $jobs = [];

        try {
            foreach ($files as $fileKey => $filePayload) {
                /** @var UploadedFile $uploadedFile */
                $uploadedFile = $filePayload['file'];

                $documentType = $filePayload['document_type'];

                $extension = strtolower(
                    $uploadedFile->getClientOriginalExtension()
                        ?: $uploadedFile->extension()
                        ?: 'bin'
                );

                $tempFileName = Str::slug(
                    $documentType,
                    '_'
                )
                    . '_'
                    . now()->format('YmdHis')
                    . '_'
                    . Str::random(12)
                    . '.'
                    . $extension;

                $tempPath = Storage::disk('kyc_temp')
                    ->putFileAs(
                        'uploads/'
                            . $uploadId
                            . '/'
                            . $documentType,
                        $uploadedFile,
                        $tempFileName
                    );

                if (!$tempPath) {
                    throw ValidationException::withMessages([
                        'documents' => [
                            'Unable to stage KYC file for upload.',
                        ],
                    ]);
                }

                $stagedFiles[$fileKey] = [
                    'document_type' => $documentType,
                    'temp_path' => $tempPath,
                ];

                $jobs[] = new ProcessKycDocumentUploadJob(
                    uploadId: $uploadId,
                    fileKey: (string) $fileKey,
                    kycRequestId: (int) $kycRequest->id,
                    userId: (int) $user->id,
                    tempDisk: 'kyc_temp',
                    tempPath: $tempPath,
                    documentType: $documentType,
                    documentNumber: $this->documentNumberForType(
                        $documentType,
                        $request
                    ),
                    originalName: $uploadedFile
                        ->getClientOriginalName(),
                    mimeType: $uploadedFile
                        ->getClientMimeType(),
                    uploadedById: (int) $user->id
                );
            }

            $progress = $this->progressService->create(
                uploadId: $uploadId,
                user: $user,
                kycRequest: $kycRequest,
                files: $stagedFiles
            );

            /** @var Batch $batch */
            $batch = Bus::batch($jobs)
                ->name(
                    'KYC document upload #'
                        . $uploadId
                )
                ->onConnection('redis')
                ->onQueue('kyc')
                ->dispatch();

            $progress = $this->progressService
                ->attachBatchId(
                    $uploadId,
                    $batch->id
                );

            return [
                'upload_id' => $uploadId,
                'batch_id' => $batch->id,
                'kyc_request_id' => (int) $kycRequest->id,
                'progress' => $progress,
            ];
        } catch (Throwable $e) {
            /*
             * Batch dispatch ya staging fail ho to
             * temporary files clean kar do.
             */
            foreach ($stagedFiles as $stagedFile) {
                $tempPath = $stagedFile['temp_path'] ?? null;

                if (
                    $tempPath
                    && Storage::disk('kyc_temp')
                    ->exists($tempPath)
                ) {
                    Storage::disk('kyc_temp')
                        ->delete($tempPath);
                }
            }

            throw $e;
        }
    }

    /**
     * KycBatchUploadRequest ke files ko standard format
     * me convert karta hai.
     *
     * Supported response formats:
     *
     * 1. key => UploadedFile
     *
     * 2. key => [
     *      'document_type' => 'aadhaar_front',
     *      'file' => UploadedFile
     *    ]
     */
    private function preparedFiles(
        KycBatchUploadRequest $request
    ): array {
        $preparedFiles = [];

        foreach (
            $request->kycDocumentFiles()
            as $fileKey => $filePayload
        ) {
            $uploadedFile = null;
            $documentType = null;

            /*
             * Old/raw request format.
             */
            if ($filePayload instanceof UploadedFile) {
                $uploadedFile = $filePayload;
                $documentType = (string) $fileKey;
            }

            /*
             * New normalized request format.
             */
            if (is_array($filePayload)) {
                $uploadedFile = $filePayload['file'] ?? null;

                $documentType = $filePayload['document_type'] ?? $fileKey;
            }

            if (!$uploadedFile instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'documents.' . $fileKey => [
                        'Invalid KYC document file.',
                    ],
                ]);
            }

            if (!$uploadedFile->isValid()) {
                throw ValidationException::withMessages([
                    'documents.' . $fileKey => [
                        'The uploaded KYC document is invalid.',
                    ],
                ]);
            }

            $documentType = strtolower(
                trim((string) $documentType)
            );

            if ($documentType === '') {
                throw ValidationException::withMessages([
                    'documents.' . $fileKey => [
                        'Document type is required.',
                    ],
                ]);
            }

            /*
             * other_1, other_2 jaise document keys ko
             * common "other" document type me convert karo.
             */
            if (
                str_starts_with(
                    (string) $fileKey,
                    KycDocument::TYPE_OTHER . '_'
                )
                || str_starts_with(
                    $documentType,
                    KycDocument::TYPE_OTHER . '_'
                )
            ) {
                $documentType = KycDocument::TYPE_OTHER;
            }

            $preparedFiles[(string) $fileKey] = [
                'document_type' => $documentType,
                'file' => $uploadedFile,
            ];
        }

        return $preparedFiles;
    }

    private function draftRequestForUpload(
        User $user,
        KycBatchUploadRequest $request,
        ?Role $role
    ): KycRequest {
        $latestRequest = KycRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if (
            $latestRequest
            && $latestRequest->isApproved()
        ) {
            throw ValidationException::withMessages([
                'kyc' => [
                    'KYC is already approved.',
                ],
            ]);
        }

        if (
            $latestRequest
            && in_array(
                $latestRequest->status,
                [
                    KycRequest::STATUS_SUBMITTED,
                    KycRequest::STATUS_UNDER_REVIEW,
                    KycRequest::STATUS_RESUBMITTED,
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'kyc' => [
                    'Your KYC is already submitted and waiting for review.',
                ],
            ]);
        }

        $isOwner = $this->isOwnerRole(
            $role?->name
        );

        $payload = [
            'user_id' => (int) $user->id,

            'role_id' => $role?->id
                ? (int) $role->id
                : (
                    $user->role_id
                    ? (int) $user->role_id
                    : null
                ),

            'status' => KycRequest::STATUS_DRAFT,
        ];

        /*
     * Aadhaar:
     * New value sent ho to update karo.
     * Field omitted ho to existing value preserve karo.
     */
        if ($request->exists('aadhaar_number')) {
            $payload['aadhaar_number'] =
                $request->input('aadhaar_number');
        } elseif ($latestRequest) {
            $payload['aadhaar_number'] =
                $latestRequest->aadhaar_number;
        }

        /*
     * Owner ke liye business metadata allowed nahi hai.
     */
        if ($isOwner) {
            $payload['gst_number'] = null;
            $payload['rera_number'] = null;
            $payload['business_name'] = null;
        } else {
            /*
         * Business metadata:
         *
         * Request me field present:
         *     submitted value update karo.
         *
         * Request me field absent:
         *     existing draft/rejected value preserve karo.
         */
            foreach (
                [
                    'gst_number',
                    'rera_number',
                    'business_name',
                ] as $field
            ) {
                if ($request->exists($field)) {
                    $payload[$field] =
                        $request->input($field);

                    continue;
                }

                if ($latestRequest) {
                    $payload[$field] =
                        $latestRequest->{$field};
                }
            }
        }

        /*
     * Existing draft ko update karo.
     */
        if (
            $latestRequest
            && $latestRequest->isDraft()
        ) {
            $latestRequest->fill($payload);
            $latestRequest->save();

            return $latestRequest->fresh([
                'user',
                'role',
                'documents',
                'activities',
            ]);
        }

        /*
     * Rejected KYC ke baad new resubmission draft.
     */
        if (
            $latestRequest
            && $latestRequest->isRejected()
        ) {
            $parentRequestId =
                $latestRequest->parent_kyc_request_id
                ?: $latestRequest->id;

            $maxVersion = KycRequest::query()
                ->where(function ($query) use (
                    $parentRequestId
                ) {
                    $query
                        ->where(
                            'id',
                            $parentRequestId
                        )
                        ->orWhere(
                            'parent_kyc_request_id',
                            $parentRequestId
                        );
                })
                ->max('version');

            $payload['parent_kyc_request_id'] =
                $parentRequestId;

            $payload['version'] =
                ((int) $maxVersion) + 1;

            $payload['resubmission_count'] =
                ((int) $latestRequest->resubmission_count)
                + 1;

            return KycRequest::query()
                ->create($payload)
                ->fresh([
                    'user',
                    'role',
                    'documents',
                    'activities',
                ]);
        }

        /*
     * First KYC draft.
     */
        $payload['version'] = 1;
        $payload['resubmission_count'] = 0;

        return KycRequest::query()
            ->create($payload)
            ->fresh([
                'user',
                'role',
                'documents',
                'activities',
            ]);
    }
    private function documentNumberForType(
        string $documentType,
        KycBatchUploadRequest $request
    ): ?string {
        return match ($documentType) {
            KycDocument::TYPE_AADHAAR_FRONT,
            KycDocument::TYPE_AADHAAR_BACK =>
            $request->input('aadhaar_number'),

            KycDocument::TYPE_GST_CERTIFICATE =>
            $request->input('gst_number'),

            KycDocument::TYPE_RERA_CERTIFICATE =>
            $request->input('rera_number'),

            default => null,
        };
    }

    private function resolveUserRole(
        User $user
    ): ?Role {
        if (empty($user->role_id)) {
            return null;
        }

        return Role::query()
            ->select([
                'id',
                'name',
            ])
            ->where('id', $user->role_id)
            ->first();
    }

    private function isOwnerRole(
        ?string $roleName
    ): bool {
        $roleName = strtolower(
            trim((string) $roleName)
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
}
