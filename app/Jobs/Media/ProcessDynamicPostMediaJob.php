<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Models\MediaBatchItem;
use App\Models\MediaFile;
use App\Models\MediaUploadBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDynamicPostMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 180;

    public function __construct(
        public int $batchItemId
    ) {
    }

    public function handle(): void
    {
        $item = MediaBatchItem::with('batch')->find($this->batchItemId);

        if (!$item || !$item->batch) {
            return;
        }

        $item->update([
            'status' => 'processing',
            'attempts' => $item->attempts + 1,
        ]);

        try {
            $path = $item->path;
            $disk = 'public';

            if (!Storage::disk($disk)->exists($path)) {
                throw new \RuntimeException("Media file not found on disk at: {$path}");
            }

            $mimeType = $item->mime_type ?: Storage::disk($disk)->mimeType($path);
            $size = $item->size ?: Storage::disk($disk)->size($path);
            $extension = strtolower($item->extension ?: pathinfo($path, PATHINFO_EXTENSION));

            $metadata = $item->metadata ?? [];
            $metadata['processed_at'] = now()->toIso8601String();

            $isImage = str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
            $isVideo = str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov', 'webm', 'mkv', 'avi']);

            if ($isImage) {
                $metadata['media_type'] = 'image';
                $fullPath = Storage::disk($disk)->path($path);
                if (file_exists($fullPath)) {
                    $imageInfo = @getimagesize($fullPath);
                    if ($imageInfo) {
                        $metadata['width'] = $imageInfo[0] ?? null;
                        $metadata['height'] = $imageInfo[1] ?? null;
                    }
                }
            } elseif ($isVideo) {
                $metadata['media_type'] = 'video';
                $metadata['is_video'] = true;
            }

            $item->update([
                'status' => 'completed',
                'mime_type' => $mimeType,
                'size' => $size,
                'metadata' => $metadata,
                'error_message' => null,
            ]);

            if ($item->media_file_id) {
                MediaFile::where('id', $item->media_file_id)->update([
                    'mime_type' => $mimeType,
                    'size' => $size,
                ]);
            }

            // Update batch counters atomically
            $batch = MediaUploadBatch::find($item->batch_id);
            if ($batch) {
                $processedCount = MediaBatchItem::where('batch_id', $batch->id)->where('status', 'completed')->count();
                $failedCount = MediaBatchItem::where('batch_id', $batch->id)->where('status', 'failed')->count();
                $totalExpected = max((int) $batch->expected_count, (int) $batch->uploaded_count, 1);
                $progress = round((($processedCount + $failedCount) / $totalExpected) * 100, 2);

                $batchStatus = ($processedCount + $failedCount >= $totalExpected) ? 'completed' : 'processing';

                $batch->update([
                    'processed_count' => $processedCount,
                    'failed_count' => $failedCount,
                    'progress_percent' => min(100.00, $progress),
                    'status' => $batchStatus,
                ]);
            }
        } catch (Throwable $e) {
            Log::error("ProcessDynamicPostMediaJob failed for item {$this->batchItemId}: " . $e->getMessage());

            $item->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $batch = MediaUploadBatch::find($item->batch_id);
            if ($batch) {
                $batch->increment('failed_count');
            }

            throw $e;
        }
    }
}
