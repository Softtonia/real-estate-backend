<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTopicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => (bool) $this->status,

            'subscribers_count' => $this->whenCounted('subscribers'),
            'active_subscribers_count' => $this->whenCounted('activeSubscribers'),

            'is_subscribed' => $this->when(
                array_key_exists('is_subscribed', $this->getAttributes()),
                (bool) $this->is_subscribed
            ),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}