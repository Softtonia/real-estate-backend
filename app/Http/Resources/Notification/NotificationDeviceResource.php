<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            'platform' => $this->platform,
            'app_type' => $this->app_type,
            'device_id' => $this->device_id,
            'device_name' => $this->device_name,
            'browser' => $this->browser,
            'os' => $this->os,

            'status' => (bool) $this->status,
            'last_used_at' => optional($this->last_used_at)->toDateTimeString(),
            'revoked_at' => optional($this->revoked_at)->toDateTimeString(),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}