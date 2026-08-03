<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'title' => $this->title,
            'body' => $this->body,
            'image_url' => $this->image_url,

            'data' => $this->data,
            'type' => $this->type,

            'is_read' => $this->read_at !== null,
            'read_at' => optional($this->read_at)->toDateTimeString(),

            'batch_id' => $this->batch_id,

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}