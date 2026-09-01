<?php

namespace App\Events\Media;

use App\Models\MediaBatchItem;
use App\Models\MediaFile;
use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploadCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaUploadBatch $batch,
        public MediaBatchItem $item,
        public MediaFile $mediaFile
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('media-batch.' . $this->batch->batch_uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'FileUploadCompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'client_file_id' => $this->item->client_file_id,
            'item_id' => (int) $this->item->id,
            'media_file_id' => (int) $this->mediaFile->id,
            'file_name' => $this->mediaFile->file_name,
            'original_name' => $this->mediaFile->original_name,
            'url' => $this->mediaFile->url,
            'path' => $this->mediaFile->path,
            'mime_type' => $this->mediaFile->mime_type,
            'extension' => $this->mediaFile->extension,
            'size' => (int) $this->mediaFile->size,
            'is_featured' => (bool) $this->item->is_featured,
            'uploaded_count' => (int) $this->batch->uploaded_count,
            'expected_count' => (int) $this->batch->expected_count,
            'batch_progress' => $this->batch->calculateProgressPercentage(),
        ];
    }
}
