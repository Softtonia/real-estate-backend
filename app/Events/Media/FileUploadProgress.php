<?php

namespace App\Events\Media;

use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploadProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaUploadBatch $batch,
        public string $clientFileId,
        public float $percentage,
        public int $uploadedBytes = 0,
        public int $totalBytes = 0
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
        return 'FileUploadProgress';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'client_file_id' => $this->clientFileId,
            'file_progress' => round($this->percentage, 2),
            'uploaded_bytes' => $this->uploadedBytes,
            'total_bytes' => $this->totalBytes,
            'batch_progress' => $this->batch->calculateProgressPercentage(),
        ];
    }
}
