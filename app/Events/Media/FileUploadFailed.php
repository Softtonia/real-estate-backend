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

class FileUploadFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaUploadBatch $batch,
        public MediaBatchItem $item,
        public string $errorMessage
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
        return 'file.failed';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_uuid' => $this->batch->batch_uuid,
            'client_file_id' => $this->item->client_file_id,
            'file_name' => $this->item->original_name,
            'error_message' => $this->errorMessage,
            'status' => 'failed',
            'progress_percent' => $this->batch->progress_percent,
        ];
    }
}
