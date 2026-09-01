<?php

declare(strict_types=1);

namespace App\Events\Media;

use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchUploadStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaUploadBatch $batch
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
        return 'batch.started';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'status' => $this->batch->status,
            'expected_count' => $this->batch->expected_count,
            'uploaded_count' => $this->batch->uploaded_count,
            'progress_percent' => $this->batch->progress_percent,
        ];
    }
}
