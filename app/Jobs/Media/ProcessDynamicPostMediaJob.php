<?php

namespace App\Jobs\Media;

use App\Events\Media\BatchUploadCompleted;
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
    public int $timeout = 180;

    public function __construct(
        public int $batchId,
        public int $batchItemId,
        public int $mediaFileId
    ) {
    }

    public function handle(): void
    {
        $batch = MediaUploadBatch::find($this->batchId);
        $item = MediaBatchItem::find($this->batchItemId);
        $mediaFile = MediaFile::find($this->mediaFileId);

        if (!$item || !$mediaFile) {
            return;
        }

        try {
            $item->update(['status' => 'processing']);

            // Image / Video basic inspection
            $path = $mediaFile->path;
            $disk = $mediaFile->disk ?: 'public';

            if (Storage::disk($disk)->exists($path)) {
                $ext = strtolower($mediaFile->extension ?: pathinfo($path, PATHINFO_EXTENSION));

                // If image, inspect dimensions if GD/Imagick available
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $fullPath = Storage::disk($disk)->path($path);
                    if (file_exists($fullPath) && function_exists('getimagesize')) {
                        $imageInfo = @getimagesize($fullPath);
                        if ($imageInfo && isset($imageInfo[0], $imageInfo[1])) {
                            // Update mime_type if more accurate
                            if (!empty($imageInfo['mime'])) {
                                $mediaFile->mime_type = $imageInfo['mime'];
                                $mediaFile->save();
                            }
                        }
                    }
                }
            }

            $item->update(['status' => 'completed']);

            if ($batch) {
                $batch->increment('processed_count');
                $batch->refresh();

                // Check if all expected files in the batch are processed
                if (($batch->processed_count + $batch->failed_count) >= $batch->expected_count) {
                    $batch->update(['status' => $batch->failed_count > 0 && $batch->processed_count === 0 ? 'failed' : 'completed']);
                    event(new BatchUploadCompleted($batch));
                }
            }
        } catch (Throwable $e) {
            Log::error('ProcessDynamicPostMediaJob failed: ' . $e->getMessage(), [
                'batch_id' => $this->batchId,
                'item_id' => $this->batchItemId,
                'media_file_id' => $this->mediaFileId,
            ]);

            $item->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            if ($batch) {
                $batch->increment('failed_count');
                $batch->refresh();

                if (($batch->processed_count + $batch->failed_count) >= $batch->expected_count) {
                    $batch->update(['status' => $batch->processed_count > 0 ? 'completed' : 'failed']);
                    event(new BatchUploadCompleted($batch));
                }
            }
        }
    }
}
