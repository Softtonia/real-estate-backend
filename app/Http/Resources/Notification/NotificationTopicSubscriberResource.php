<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTopicSubscriberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topic_id' => $this->topic_id,
            'user_id' => $this->user_id,
            'device_id' => $this->device_id,
            'status' => (bool) $this->status,

            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user?->id,
                    'name' => trim(($this->user?->first_name ?? '') . ' ' . ($this->user?->last_name ?? '')),
                    'email' => $this->user?->email,
                    'phone' => $this->user?->phone,
                ];
            }),

            'device' => $this->whenLoaded('device', function () {
                return [
                    'id' => $this->device?->id,
                    'platform' => $this->device?->platform,
                    'app_type' => $this->device?->app_type,
                    'device_id' => $this->device?->device_id,
                    'device_name' => $this->device?->device_name,
                    'status' => (bool) $this->device?->status,
                ];
            }),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}