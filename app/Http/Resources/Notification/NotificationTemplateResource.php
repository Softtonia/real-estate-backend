<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'template_key' => $this->template_key,
            'title' => $this->title,
            'body' => $this->body,
            'image_url' => $this->image_url,
            'data' => $this->data,

            'channel' => $this->channel,
            'status' => (bool) $this->status,

            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy?->id,
                    'name' => trim(($this->createdBy?->first_name ?? '') . ' ' . ($this->createdBy?->last_name ?? '')),
                    'email' => $this->createdBy?->email,
                ];
            }),

            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return [
                    'id' => $this->updatedBy?->id,
                    'name' => trim(($this->updatedBy?->first_name ?? '') . ' ' . ($this->updatedBy?->last_name ?? '')),
                    'email' => $this->updatedBy?->email,
                ];
            }),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}