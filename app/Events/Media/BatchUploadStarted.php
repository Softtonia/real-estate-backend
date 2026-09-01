<?php

namespace App\Events\Media;

use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchUploadStarted implements ShouldBroadcastNow
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
        return 'BatchUploadStarted';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'expected_count' => (int) $this->batch->expected_count,
            'field_slug' => $this->batch->field_slug,
            'status' => $this->batch->status,
            'progress' => $this->batch->calculateProgressPercentage(),
        ];
    }
}
