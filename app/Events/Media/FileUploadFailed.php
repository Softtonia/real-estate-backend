<?php

namespace App\Events\Media;

use App\Models\MediaUploadBatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploadFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaUploadBatch $batch,
        public string $clientFileId,
        public string $fileName,
        public string $errorMessage
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
        return 'FileUploadFailed';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'client_file_id' => $this->clientFileId,
            'file_name' => $this->fileName,
            'error' => $this->errorMessage,
            'failed_count' => (int) $this->batch->failed_count,
            'batch_progress' => $this->batch->calculateProgressPercentage(),
        ];
    }
}
