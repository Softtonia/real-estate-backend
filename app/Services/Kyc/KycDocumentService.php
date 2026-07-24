<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class KycDocumentService
{
    public function __construct(
        private readonly KycActivityService $activityService
    ) {}

    public function storeDocument(
        KycRequest $kycRequest,
        User $user,
        UploadedFile $file,
        string $documentType,
        ?string $documentNumber = null,
        ?User $uploadedBy = null,
        array $metadata = [],
        ?Request $request = null
    ): KycDocument {
        $documentType = strtolower(trim($documentType));

        if (!in_array($documentType, KycDocument::documentTypes(), true)) {
            throw new InvalidArgumentException('Invalid KYC document type.');
        }

        if (!$file->isValid()) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        $lockKey = 'kyc:document-upload:' . $kycRequest->id . ':' . $documentType;

        return Cache::store('redis')->lock($lockKey, 15)->block(5, function () use (
            $kycRequest,
            $user,
            $file,
            $documentType,
            $documentNumber,
            $uploadedBy,
            $metadata,
            $request
        ) {
            $storedPath = null;

            try {
                $version = $this->nextDocumentVersion($kycRequest, $documentType);

                $storedPath = $this->storePrivateFile(
                    file: $file,
                    user: $user,
                    kycRequest: $kycRequest,
                    documentType: $documentType,
                    version: $version
                );

                $document = DB::transaction(function () use (
                    $kycRequest,
                    $user,
                    $file,
                    $documentType,
                    $documentNumber,
                    $uploadedBy,
                    $metadata,
                    $storedPath,
                    $version
                ) {
                    return KycDocument::query()->create([
                        'kyc_request_id' => $kycRequest->id,
                        'user_id' => $user->id,
                        'document_type' => $documentType,
                        'document_number' => $documentNumber,
                        'file_disk' => 'kyc_private',
                        'file_path' => $storedPath,
                        'file_original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => (int) $file->getSize(),
                        'status' => KycDocument::STATUS_PENDING,
                        'uploaded_by' => $uploadedBy?->id ?? $user->id,
                        'uploaded_at' => now(),
                        'version' => $version,
                        'metadata' => !empty($metadata) ? $metadata : null,
                    ]);
                });

                $this->activityService->documentUploaded(
                    kycRequest: $kycRequest,
                    performedBy: $uploadedBy ?? $user,
                    documentType: $documentType,
                    documentId: (int) $document->id,
                    request: $request
                );

                return $document;
            } catch (Throwable $e) {
                if ($storedPath) {
                    Storage::disk('kyc_private')->delete($storedPath);
                }

                throw $e;
            }
        });
    }

    public function reviewDocument(
        KycDocument $document,
        string $status,
        ?string $rejectionReason,
        User $reviewer,
        ?Request $request = null
    ): KycDocument {
        $status = strtolower(trim($status));

        if (!in_array($status, KycDocument::statuses(), true)) {
            throw new InvalidArgumentException('Invalid document review status.');
        }

        if ($status === KycDocument::STATUS_REJECTED && empty($rejectionReason)) {
            throw new InvalidArgumentException('Document rejection reason is required.');
        }

        DB::transaction(function () use ($document, $status, $rejectionReason, $reviewer) {
            $document->update([
                'status' => $status,
                'rejection_reason' => $status === KycDocument::STATUS_REJECTED
                    ? $rejectionReason
                    : null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
        });

        $document->refresh();

        $kycRequest = $document->kycRequest;

        if ($status === KycDocument::STATUS_APPROVED) {
            $this->activityService->documentApproved(
                kycRequest: $kycRequest,
                performedBy: $reviewer,
                documentType: $document->document_type,
                documentId: (int) $document->id,
                request: $request
            );
        }

        if ($status === KycDocument::STATUS_REJECTED) {
            $this->activityService->documentRejected(
                kycRequest: $kycRequest,
                performedBy: $reviewer,
                documentType: $document->document_type,
                documentId: (int) $document->id,
                reason: (string) $rejectionReason,
                request: $request
            );
        }

        return $document;
    }

    public function latestDocumentsForRequest(KycRequest $kycRequest)
    {
        return KycDocument::query()
            ->where('kyc_request_id', $kycRequest->id)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->unique('document_type')
            ->values();
    }

    public function hasRequiredDocuments(KycRequest $kycRequest, array $requiredDocuments): bool
    {
        if (empty($requiredDocuments)) {
            return true;
        }

        $existingTypes = $this->latestDocumentsForRequest($kycRequest)
            ->pluck('document_type')
            ->unique()
            ->values()
            ->toArray();

        foreach ($requiredDocuments as $requiredDocument) {
            if (!in_array($requiredDocument, $existingTypes, true)) {
                return false;
            }
        }

        return true;
    }

    public function missingRequiredDocuments(KycRequest $kycRequest, array $requiredDocuments): array
    {
        if (empty($requiredDocuments)) {
            return [];
        }

        $existingTypes = $this->latestDocumentsForRequest($kycRequest)
            ->pluck('document_type')
            ->unique()
            ->values()
            ->toArray();

        return collect($requiredDocuments)
            ->reject(fn(string $type) => in_array($type, $existingTypes, true))
            ->values()
            ->toArray();
    }

    public function deletePrivateFile(KycDocument $document): void
    {
        if (!empty($document->file_disk) && !empty($document->file_path)) {
            Storage::disk($document->file_disk)->delete($document->file_path);
        }
    }

    public function fileExists(KycDocument $document): bool
    {
        if (empty($document->file_disk) || empty($document->file_path)) {
            return false;
        }

        return Storage::disk($document->file_disk)->exists($document->file_path);
    }

    public function absolutePath(KycDocument $document): string
    {
        if (!$this->fileExists($document)) {
            throw new RuntimeException('KYC document file not found.');
        }

        return Storage::disk($document->file_disk)->path($document->file_path);
    }

    private function nextDocumentVersion(KycRequest $kycRequest, string $documentType): int
    {
        $latestVersion = KycDocument::query()
            ->where('kyc_request_id', $kycRequest->id)
            ->where('document_type', $documentType)
            ->max('version');

        return ((int) $latestVersion) + 1;
    }

    private function storePrivateFile(
        UploadedFile $file,
        User $user,
        KycRequest $kycRequest,
        string $documentType,
        int $version
    ): string {
        $extension = strtolower(
            $file->getClientOriginalExtension()
                ?: $file->extension()
                ?: 'bin'
        );

        $fileName = Str::slug($documentType, '_')
            . '_v'
            . $version
            . '_'
            . now()->format('YmdHis')
            . '_'
            . Str::random(12)
            . '.'
            . $extension;

        $folder = 'users/'
            . $user->id
            . '/requests/'
            . $kycRequest->id
            . '/'
            . $documentType;

        $storedPath = Storage::disk('kyc_private')->putFileAs(
            $folder,
            $file,
            $fileName
        );

        if (!$storedPath || !Storage::disk('kyc_private')->exists($storedPath)) {
            throw new RuntimeException('KYC document could not be saved.');
        }

        return $storedPath;
    }
}