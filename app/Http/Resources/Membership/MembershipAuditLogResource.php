<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,

            'user_id' => $this->user_id ? (int) $this->user_id : null,
            'performed_by' => $this->performed_by ? (int) $this->performed_by : null,

            'user' => $this->whenLoaded('user', function () {
                return $this->user ? [
                    'id' => (int) $this->user->id,
                    'name' => trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? '')),
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ] : null;
            }),

            'performer' => $this->whenLoaded('performer', function () {
                return $this->performer ? [
                    'id' => (int) $this->performer->id,
                    'name' => trim(($this->performer->first_name ?? '') . ' ' . ($this->performer->last_name ?? '')),
                    'email' => $this->performer->email,
                    'phone' => $this->performer->phone,
                ] : null;
            }),

            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id ? (int) $this->auditable_id : null,

            'action' => $this->action,

            'old_values' => $this->old_values ?? null,
            'new_values' => $this->new_values ?? null,

            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,

            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}