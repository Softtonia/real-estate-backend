<?php

namespace App\Services\Kyc;

use App\Http\Requests\Kyc\KycSubmitRequest;
use App\Jobs\Kyc\ProcessKycDocumentUploadJob;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\KycRoleRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KycBatchUploadService
{
    public function __construct(
        private readonly KycUploadProgressService $progressService
    ) {}

    public function start(User $user, KycSubmitRequest $request): array
    {
        $files = $this->preparedFiles($request);

        if (empty($files)) {
            throw ValidationException::withMessages([
                'files' => 'Please upload at least one KYC document.',
            ]);
        }

        $role = $this->resolveUserRole($user);

        if ($this->isOwnerRole($role?->name)) {
            foreach ($files as $file) {
                if (in_array($file['document_type'], [
                    KycDocument::TYPE_BUSINESS_PROOF,
                    KycDocument::TYPE_GST_CERTIFICATE,
                    KycDocument::TYPE_RERA_CERTIFICATE,
                ], true)) {
                    throw ValidationException::withMessages([
                        'documents' => 'Owner role cannot upload business KYC documents.',
                    ]);
                }
            }
        }

        $kycRequest = $this->draftRequestForUpload($user, $request, $role);

        $uploadId = 'kyc_' . $user->id . '_' . Str::uuid()->toString();

        $stagedFiles = [];
        $jobs = [];

        foreach ($files as $fileKey => $filePayload) {
            $uploadedFile = $filePayload['file'];
            $documentType = $filePayload['document_type'];

            $extension = strtolower(
                $uploadedFile->getClientOriginalExtension()
                    ?: $uploadedFile->extension()
                    ?: 'bin'
            );

            $tempFileName = Str::slug($documentType, '_')
                . '_'
                . now()->format('YmdHis')
                . '_'
                . Str::random(12)
                . '.'
                . $extension;

            $tempPath = Storage::disk('kyc_temp')->putFileAs(
                'uploads/' . $uploadId . '/' . $documentType,
                $uploadedFile,
                $tempFileName
            );

            if (!$tempPath) {
                throw ValidationException::withMessages([
                    'files' => 'Unable to stage KYC file for upload.',
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
                documentNumber: $this->documentNumberForType($documentType, $request),
                originalName: $uploadedFile->getClientOriginalName(),
                mimeType: $uploadedFile->getClientMimeType(),
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
            ->name('KYC document upload #' . $uploadId)
            ->onConnection('redis')
            ->onQueue('kyc')
            ->dispatch();

        $progress = $this->progressService->attachBatchId($uploadId, $batch->id);

        return [
            'upload_id' => $uploadId,
            'batch_id' => $batch->id,
            'kyc_request_id' => (int) $kycRequest->id,
            'progress' => $progress,
        ];
    }

    private function preparedFiles(KycSubmitRequest $request): array
    {
        $files = [];

        foreach ($request->kycDocumentFiles() as $key => $file) {
            $documentType = str_starts_with($key, KycDocument::TYPE_OTHER . '_')
                ? KycDocument::TYPE_OTHER
                : $key;

            $files[(string) $key] = [
                'document_type' => $documentType,
                'file' => $file,
            ];
        }

        return $files;
    }

    private function draftRequestForUpload(User $user, KycSubmitRequest $request, ?Role $role): KycRequest
    {
        $latestRequest = KycRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($latestRequest && $latestRequest->isApproved()) {
            throw ValidationException::withMessages([
                'kyc' => 'KYC is already approved.',
            ]);
        }

        if ($latestRequest && in_array($latestRequest->status, [
            KycRequest::STATUS_SUBMITTED,
            KycRequest::STATUS_UNDER_REVIEW,
            KycRequest::STATUS_RESUBMITTED,
        ], true)) {
            throw ValidationException::withMessages([
                'kyc' => 'Your KYC is already submitted and waiting for review.',
            ]);
        }

        $payload = [
            'user_id' => $user->id,
            'role_id' => $role?->id ?? $user->role_id,
            'status' => KycRequest::STATUS_DRAFT,
            'aadhaar_number' => $request->input('aadhaar_number'),
            'gst_number' => $this->isOwnerRole($role?->name) ? null : $request->input('gst_number'),
            'rera_number' => $this->isOwnerRole($role?->name) ? null : $request->input('rera_number'),
            'business_name' => $this->isOwnerRole($role?->name) ? null : $request->input('business_name'),
        ];

        if ($latestRequest && $latestRequest->isDraft()) {
            $latestRequest->update($payload);

            return $latestRequest->fresh();
        }

        if ($latestRequest && $latestRequest->isRejected()) {
            $parentRequestId = $latestRequest->parent_kyc_request_id ?: $latestRequest->id;

            $nextVersion = ((int) KycRequest::query()
                ->where(function ($query) use ($parentRequestId) {
                    $query
                        ->where('id', $parentRequestId)
                        ->orWhere('parent_kyc_request_id', $parentRequestId);
                })
                ->max('version')) + 1;

            return KycRequest::query()->create(array_merge($payload, [
                'parent_kyc_request_id' => $parentRequestId,
                'version' => $nextVersion,
                'resubmission_count' => (int) $latestRequest->resubmission_count + 1,
            ]));
        }

        return KycRequest::query()->create($payload);
    }

    private function documentNumberForType(string $documentType, KycSubmitRequest $request): ?string
    {
        return match ($documentType) {
            KycDocument::TYPE_AADHAAR_FRONT,
            KycDocument::TYPE_AADHAAR_BACK => $request->input('aadhaar_number'),

            KycDocument::TYPE_GST_CERTIFICATE => $request->input('gst_number'),

            KycDocument::TYPE_RERA_CERTIFICATE => $request->input('rera_number'),

            default => null,
        };
    }

    private function resolveUserRole(User $user): ?Role
    {
        if (empty($user->role_id)) {
            return null;
        }

        return Role::query()
            ->select(['id', 'name'])
            ->where('id', $user->role_id)
            ->first();
    }

    private function isOwnerRole(?string $roleName): bool
    {
        $roleName = strtolower(trim((string) $roleName));
        $roleName = str_replace([' ', '_', '-'], '', $roleName);

        return in_array($roleName, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }
}