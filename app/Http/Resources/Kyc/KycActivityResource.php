<?php

namespace App\Http\Resources\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'kyc_request_id' => $this->kyc_request_id ? (int) $this->kyc_request_id : null,
            'user_id' => $this->user_id ? (int) $this->user_id : null,
            'performed_by' => $this->performed_by ? (int) $this->performed_by : null,

            'action' => $this->action,
            'old_status' => $this->old_status,
            'new_status' => $this->new_status,
            'remarks' => $this->remarks,
            'metadata' => $this->metadata,

            'performer' => $this->whenLoaded('performer', function () {
                return $this->userMini($this->performer);
            }),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'created_at_human' => optional($this->created_at)->diffForHumans(),
        ];
    }

    private function userMini($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: null,
            'email' => $user->email,
        ];
    }
}