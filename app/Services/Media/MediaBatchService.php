<?php

namespace App\Services\Media;

use App\Events\Media\BatchUploadStarted;
use App\Events\Media\FileUploadCompleted;
use App\Events\Media\FileUploadFailed;
use App\Events\Media\FileUploadProgress;
use App\Jobs\Media\ProcessDynamicPostMediaJob;
use App\Models\CustomField;
use App\Models\MediaBatchItem;
use App\Models\MediaFile;
use App\Models\MediaUploadBatch;
use App\Models\PostType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaBatchService
{
    public function initBatch(array $data, ?int $userId = null): MediaUploadBatch
    {
        $postTypeId = !empty($data['post_type_id']) ? (int) $data['post_type_id'] : null;
        $customFieldId = !empty($data['custom_field_id']) ? (int) $data['custom_field_id'] : null;
        $fieldSlug = trim((string) ($data['field_slug'] ?? 'media'));
        $expectedCount = max(1, (int) ($data['expected_count'] ?? 1));

        // Validate limits against CustomField if provided
        if ($customFieldId) {
            $customField = CustomField::find($customFieldId);
            if ($customField) {
                if (!empty($customField->field_name_slug)) {
                    $fieldSlug = $customField->field_name_slug;
                }

                if (!empty($customField->media_limit) && is_numeric($customField->media_limit)) {
                    $limit = (int) $customField->media_limit;
                    if ($limit > 0 && $expectedCount > $limit) {
                        throw ValidationException::withMessages([
                            'expected_count' => ["This field accepts a maximum of {$limit} files."],
                        ]);
                    }
                }
            }
        }

        $batch = MediaUploadBatch::create([
            'batch_uuid' => (string) Str::uuid(),
            'user_id' => $userId ?: Auth::id(),
            'post_type_id' => $postTypeId,
            'custom_field_id' => $customFieldId,
            'field_slug' => $fieldSlug,
            'expected_count' => $expectedCount,
            'uploaded_count' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? [],
        ]);

        event(new BatchUploadStarted($batch));

        return $batch;
    }

    public function uploadFileToBatch(
        MediaUploadBatch $batch,
        UploadedFile $file,
        ?string $clientFileId = null,
        bool $isFeatured = false,
        int $sortOrder = 0
    ): array {
        $clientFileId = $clientFileId ?: (string) Str::uuid();

        // 1. Idempotency check: if item with client_file_id already uploaded in this batch, return existing
        $existingItem = MediaBatchItem::where('batch_id', $batch->id)
            ->where('client_file_id', $clientFileId)
            ->with('mediaFile')
            ->first();

        if ($existingItem && $existingItem->mediaFile) {
            return [
                'status' => true,
                'is_duplicate' => true,
                'item' => $existingItem,
                'media_file' => $existingItem->mediaFile,
                'batch_progress' => $batch->calculateProgressPercentage(),
            ];
        }

        if (!$file->isValid()) {
            $errorMsg = $file->getErrorMessage() ?: 'Uploaded file is invalid or exceeds upload_max_filesize limit.';
            event(new FileUploadFailed($batch, $clientFileId, $file->getClientOriginalName(), $errorMsg));

            throw ValidationException::withMessages([
                'file' => [$errorMsg],
            ]);
        }

        // 2. Format & size validation
        $this->validateBatchFile($batch, $file);

        try {
            $postType = $batch->post_type_id ? PostType::find($batch->post_type_id) : null;
            $postTypeSlug = Str::slug($postType->slug ?? $postType->name ?? 'common', '-');
            $fieldSlug = Str::slug($batch->field_slug ?: 'media', '-');

            $extension = strtolower($file->getClientOriginalExtension());
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
            $fileSize = $file->getSize();

            $directory = implode('/', [
                'uploads',
                'batches',
                $postTypeSlug,
                $fieldSlug,
                now()->format('Y'),
                now()->format('m'),
            ]);

            $fileName = Str::uuid()->toString() . '.' . $extension;
            $path = $file->storeAs($directory, $fileName, 'public');

            if (empty($path)) {
                throw new \RuntimeException('Failed to store file on disk.');
            }

            // Create MediaFile record
            $mediaFile = MediaFile::firstOrCreate(
                ['path' => $path],
                [
                    'disk' => 'public',
                    'context' => 'batch-upload',
                    'post_type_slug' => $postTypeSlug,
                    'field_slug' => $fieldSlug,
                    'directory' => $directory,
                    'file_name' => $fileName,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'size' => $fileSize,
                    'uploaded_by' => $batch->user_id ?: Auth::id() ?: 1,
                ]
            );

            // Create MediaBatchItem
            $batchItem = MediaBatchItem::create([
                'batch_id' => $batch->id,
                'media_file_id' => $mediaFile->id,
                'client_file_id' => $clientFileId,
                'original_name' => $originalName,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'is_featured' => $isFeatured,
                'sort_order' => $sortOrder,
                'status' => 'uploaded',
            ]);

            $batch->increment('uploaded_count');
            $batch->update(['status' => 'uploading']);
            $batch->refresh();

            // Broadcast upload progress & completion
            event(new FileUploadProgress($batch, $clientFileId, 100.0, $fileSize, $fileSize));
            event(new FileUploadCompleted($batch, $batchItem, $mediaFile));

            // Dispatch background queue job for optimization/thumbnails
            try {
                ProcessDynamicPostMediaJob::dispatch(
                    (int) $batch->id,
                    (int) $batchItem->id,
                    (int) $mediaFile->id
                );
            } catch (Throwable $queueException) {
                // If queue fails to dispatch, mark completed synchronously
                $batchItem->update(['status' => 'completed']);
                $batch->increment('processed_count');
            }

            return [
                'status' => true,
                'is_duplicate' => false,
                'item' => $batchItem,
                'media_file' => $mediaFile,
                'batch_progress' => $batch->calculateProgressPercentage(),
            ];
        } catch (Throwable $e) {
            $batch->increment('failed_count');
            $batch->refresh();

            event(new FileUploadFailed($batch, $clientFileId, $file->getClientOriginalName(), $e->getMessage()));

            throw $e;
        }
    }

    public function getBatchStatus(MediaUploadBatch $batch): array
    {
        $batch->load(['items.mediaFile']);

        return [
            'batch_uuid' => $batch->batch_uuid,
            'status' => $batch->status,
            'expected_count' => (int) $batch->expected_count,
            'uploaded_count' => (int) $batch->uploaded_count,
            'processed_count' => (int) $batch->processed_count,
            'failed_count' => (int) $batch->failed_count,
            'progress' => $batch->calculateProgressPercentage(),
            'is_completed' => $batch->isCompleted(),
            'items' => $batch->items->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'client_file_id' => $item->client_file_id,
                    'media_file_id' => $item->media_file_id ? (int) $item->media_file_id : null,
                    'url' => $item->mediaFile?->url,
                    'path' => $item->mediaFile?->path,
                    'original_name' => $item->original_name,
                    'file_name' => $item->mediaFile?->file_name,
                    'mime_type' => $item->mime_type,
                    'extension' => $item->mediaFile?->extension,
                    'size' => (int) $item->file_size,
                    'is_featured' => (bool) $item->is_featured,
                    'status' => $item->status,
                    'error_message' => $item->error_message,
                ];
            })->values()->toArray(),
        ];
    }

    public function resolveBatchToMediaArray(string $batchUuid): array
    {
        $batch = MediaUploadBatch::where('batch_uuid', $batchUuid)
            ->with(['items.mediaFile'])
            ->first();

        if (!$batch) {
            return [];
        }

        $items = [];

        foreach ($batch->items as $item) {
            $media = $item->mediaFile;
            if (!$media || empty($media->path)) {
                continue;
            }

            $items[] = [
                'id' => (int) $media->id,
                'disk' => $media->disk ?: 'public',
                'path' => $media->path,
                'url' => $media->url ?: Storage::disk($media->disk ?: 'public')->url($media->path),
                'file_name' => $media->file_name,
                'original_name' => $media->original_name,
                'mime_type' => $media->mime_type,
                'extension' => $media->extension,
                'size' => (int) $media->size,
                'size_kb' => round($media->size / 1024, 2),
                'is_featured' => (bool) $item->is_featured,
            ];
        }

        return $items;
    }

    public function cancelBatch(MediaUploadBatch $batch): void
    {
        $batch->load(['items.mediaFile']);

        foreach ($batch->items as $item) {
            if ($item->mediaFile) {
                $path = $item->mediaFile->path;
                $disk = $item->mediaFile->disk ?: 'public';

                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }

                $item->mediaFile->delete();
            }
        }

        $batch->update(['status' => 'abandoned']);
    }

    private function validateBatchFile(MediaUploadBatch $batch, UploadedFile $file): void
    {
        $customField = $batch->custom_field_id ? CustomField::find($batch->custom_field_id) : null;
        $extension = strtolower($file->getClientOriginalExtension());

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'mp4', 'mov', 'webm', 'avi', 'mkv', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];

        if ($customField && !empty($customField->media_format)) {
            $customFormats = array_values(array_filter(array_map('trim', explode(',', strtolower($customField->media_format)))));
            if (!empty($customFormats)) {
                $allowedExtensions = $customFormats;
            }
        }

        if (!in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => ['Invalid file format. Allowed formats: ' . implode(', ', $allowedExtensions)],
            ]);
        }

        $maxSizeKb = in_array($extension, ['mp4', 'mov', 'webm', 'avi', 'mkv'], true) ? 102400 : 20480;

        if ($customField && !empty($customField->media_size)) {
            $sizeStr = strtolower(str_replace(' ', '', (string) $customField->media_size));
            if (is_numeric($sizeStr)) {
                $maxSizeKb = (int) $sizeStr * 1024;
            } elseif (preg_match('/^(\d+)(kb|mb|gb)$/', $sizeStr, $matches)) {
                $val = (int) $matches[1];
                $unit = $matches[2];
                $maxSizeKb = match ($unit) {
                    'gb' => $val * 1024 * 1024,
                    'mb' => $val * 1024,
                    'kb' => $val,
                    default => $maxSizeKb,
                };
            }
        }

        if (($file->getSize() / 1024) > $maxSizeKb) {
            throw ValidationException::withMessages([
                'file' => ['File exceeds maximum allowed size of ' . round($maxSizeKb / 1024, 2) . ' MB.'],
            ]);
        }
    }
}
