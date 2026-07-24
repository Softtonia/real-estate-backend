<?php

namespace App\Http\Resources\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycUserExemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,

            'user' => $this->whenLoaded('user', function () {
                return $this->userMini($this->user);
            }),

            'created_by' => $this->created_by ? (int) $this->created_by : null,
            'revoked_by' => $this->revoked_by ? (int) $this->revoked_by : null,

            'creator' => $this->whenLoaded('creator', function () {
                return $this->userMini($this->creator);
            }),

            'revoker' => $this->whenLoaded('revoker', function () {
                return $this->userMini($this->revoker);
            }),

            'reason' => $this->reason,
            'expires_at' => optional($this->expires_at)->toDateTimeString(),
            'revoked_at' => optional($this->revoked_at)->toDateTimeString(),

            'is_active' => $this->isActive(),

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
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
            'role_id' => $user->role_id ?? null,
        ];
    }
}