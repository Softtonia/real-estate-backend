<?php

namespace App\Http\Resources\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycRoleRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'role_id' => (int) $this->role_id,

            'role' => $this->whenLoaded('role', function () {
                return $this->role ? [
                    'id' => (int) $this->role->id,
                    'name' => $this->role->name,
                ] : null;
            }),

            'requires_kyc' => (bool) $this->requires_kyc,
            'can_publish_without_kyc' => (bool) $this->can_publish_without_kyc,
            'required_documents' => $this->required_documents ?? [],
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}