<?php

declare(strict_types=1);

namespace App\Events\Media;

use App\Models\MediaBatchItem;
use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploadProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaUploadBatch $batch,
        public MediaBatchItem $item
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('media-batch.' . $this->batch->batch_uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'file.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'client_file_id' => $this->item->client_file_id,
            'file_name' => $this->item->original_name,
            'status' => $this->item->status,
            'progress_percent' => $this->batch->progress_percent,
            'uploaded_count' => $this->batch->uploaded_count,
            'expected_count' => $this->batch->expected_count,
        ];
    }
}
