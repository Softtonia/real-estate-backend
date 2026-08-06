<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyAvailabilityHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,

            'dynamic_post_id' =>
                (int) $this->dynamic_post_id,

            'revision_id' => $this->revision_id
                ? (int) $this->revision_id
                : null,

            'event' => $this->event,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_context' => $this->actor_context,
            'notes' => $this->notes,
            'metadata' => $this->metadata,

            'changed_by' => $this->changedBy
                ? [
                    'id' =>
                        (int) $this->changedBy->id,

                    'name' => trim(
                        ($this->changedBy
                            ->first_name ?? '')
                        . ' '
                        . ($this->changedBy
                            ->last_name ?? '')
                    ),

                    'email' =>
                        $this->changedBy->email,
                ]
                : null,

            'created_at' =>
                optional(
                    $this->created_at
                )->toISOString(),
        ];
    }
}
