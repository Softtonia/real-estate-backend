<?php

namespace App\Events\Media;

use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchUploadCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public MediaUploadBatch $batch)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('media-batch.' . $this->batch->batch_uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'BatchUploadCompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'status' => $this->batch->status,
            'expected_count' => (int) $this->batch->expected_count,
            'uploaded_count' => (int) $this->batch->uploaded_count,
            'processed_count' => (int) $this->batch->processed_count,
            'failed_count' => (int) $this->batch->failed_count,
            'progress' => 100.0,
            'items' => $this->batch->items()->with('mediaFile')->get()->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'client_file_id' => $item->client_file_id,
                    'media_file_id' => $item->media_file_id ? (int) $item->media_file_id : null,
                    'url' => $item->mediaFile?->url,
                    'path' => $item->mediaFile?->path,
                    'original_name' => $item->original_name,
                    'file_name' => $item->mediaFile?->file_name,
                    'mime_type' => $item->mime_type,
                    'size' => (int) $item->file_size,
                    'is_featured' => (bool) $item->is_featured,
                    'status' => $item->status,
                ];
            })->values()->toArray(),
        ];
    }
}
