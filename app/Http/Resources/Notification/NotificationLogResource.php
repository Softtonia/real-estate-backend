<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'batch_id' => $this->batch_id,
            'user_id' => $this->user_id,
            'device_id' => $this->device_id,

            'platform' => $this->platform,

            'fcm_token' => $this->maskToken($this->fcm_token),

            'title' => $this->title,
            'body' => $this->body,
            'payload' => $this->payload,

            'firebase_message_id' => $this->firebase_message_id,

            'status' => $this->status,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,

            'sent_at' => optional($this->sent_at)->toDateTimeString(),

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
                    'last_used_at' => optional($this->device?->last_used_at)->toDateTimeString(),
                    'revoked_at' => optional($this->device?->revoked_at)->toDateTimeString(),
                ];
            }),

            'batch' => $this->whenLoaded('batch', function () {
                return [
                    'id' => $this->batch?->id,
                    'batch_uuid' => $this->batch?->batch_uuid,
                    'target_type' => $this->batch?->target_type,
                    'status' => $this->batch?->status,
                ];
            }),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }

    private function maskToken(?string $token): ?string
    {
        if (! $token) {
            return null;
        }

        $token = (string) $token;

        if (strlen($token) <= 12) {
            return '********';
        }

        return substr($token, 0, 6) . str_repeat('*', 12) . substr($token, -6);
    }
}