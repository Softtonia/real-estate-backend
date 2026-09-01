<?php

declare(strict_types=1);

namespace App\Events\Media;

use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchUploadCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaUploadBatch $batch,
        public array $savedMedia = []
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
        return 'batch.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'dynamic_post_id' => $this->batch->dynamic_post_id,
            'custom_field_id' => $this->batch->custom_field_id,
            'status' => $this->batch->status,
            'uploaded_count' => $this->batch->uploaded_count,
            'processed_count' => $this->batch->processed_count,
            'failed_count' => $this->batch->failed_count,
            'progress_percent' => $this->batch->progress_percent,
            'saved_media' => $this->savedMedia,
        ];
    }
}
