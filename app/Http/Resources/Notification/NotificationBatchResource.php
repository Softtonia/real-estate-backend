<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_uuid' => $this->batch_uuid,

            'template_id' => $this->template_id,

            'title' => $this->title,
            'body' => $this->body,
            'image_url' => $this->image_url,

            'target_type' => $this->target_type,
            'target_value' => $this->decodeTargetValue($this->target_value),

            'payload' => $this->payload,

            'total_count' => (int) $this->total_count,
            'success_count' => (int) $this->success_count,
            'failed_count' => (int) $this->failed_count,

            'status' => $this->status,

            'scheduled_at' => optional($this->scheduled_at)->toDateTimeString(),
            'started_at' => optional($this->started_at)->toDateTimeString(),
            'finished_at' => optional($this->finished_at)->toDateTimeString(),

            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy?->id,
                    'name' => trim(($this->createdBy?->first_name ?? '') . ' ' . ($this->createdBy?->last_name ?? '')),
                    'email' => $this->createdBy?->email,
                ];
            }),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }

    private function decodeTargetValue(?string $value): mixed
    {
        if (! $value) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}