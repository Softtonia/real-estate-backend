<?php

namespace App\Jobs\Kyc;

use App\Models\KycRequest;
use App\Models\User;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycUploadProgressService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessKycDocumentUploadJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [
        10,
        30,
        60,
    ];

    public function __construct(
        public string $uploadId,
        public string $fileKey,
        public int $kycRequestId,
        public int $userId,
        public string $tempDisk,
        public string $tempPath,
        public string $documentType,
        public ?string $documentNumber,
        public ?string $originalName,
        public ?string $mimeType,
        public int $uploadedById
    ) {}

    public function handle(
        KycDocumentService $documentService,
        KycUploadProgressService $progressService
    ): void {
        if ($this->batch()?->cancelled()) {
            $progressService->markFailed(
                uploadId: $this->uploadId,
                fileKey: $this->fileKey,
                error: 'Upload batch was cancelled.'
            );

            Storage::disk($this->tempDisk)
                ->delete($this->tempPath);

            return;
        }

        $progressService->markProcessing(
            $this->uploadId,
            $this->fileKey
        );

        $kycRequest = KycRequest::query()
            ->findOrFail($this->kycRequestId);

        $user = User::query()
            ->findOrFail($this->userId);

        $uploadedBy = User::query()
            ->find($this->uploadedById)
            ?: $user;

        $absolutePath = Storage::disk(
            $this->tempDisk
        )->path(
            $this->tempPath
        );

        if (!is_file($absolutePath)) {
            throw new RuntimeException(
                'Temporary KYC file not found.'
            );
        }

        $file = new UploadedFile(
            $absolutePath,
            $this->originalName
                ?: basename($absolutePath),
            $this->mimeType,
            null,
            true
        );

        $document = $documentService->storeDocument(
            kycRequest: $kycRequest,
            user: $user,
            file: $file,
            documentType: $this->documentType,
            documentNumber: $this->documentNumber,
            uploadedBy: $uploadedBy,
            metadata: [
                'source' => 'queued_batch_upload',
                'upload_id' => $this->uploadId,
                'file_key' => $this->fileKey,
            ],
            request: null
        );

        $progressService->markCompleted(
            uploadId: $this->uploadId,
            fileKey: $this->fileKey,
            documentId: (int) $document->id
        );

        Storage::disk($this->tempDisk)
            ->delete($this->tempPath);
    }

    public function failed(
        Throwable $exception
    ): void {
        app(KycUploadProgressService::class)
            ->markFailed(
                uploadId: $this->uploadId,
                fileKey: $this->fileKey,
                error: $exception->getMessage()
            );

        Storage::disk($this->tempDisk)
            ->delete($this->tempPath);
    }
}