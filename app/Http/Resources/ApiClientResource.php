<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'status' => (bool) $this->status,

            'allowed_origins' => $this->allowed_origins ?? [],
            'permissions' => $this->permissions ?? [],

            'rate_limit_per_minute' => $this->rate_limit_per_minute,
            'requires_signature' => (bool) $this->requires_signature,

            'description' => $this->description,

            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'application_passwords_count' => $this->whenCounted('applicationPasswords'),

            'application_passwords' => ApplicationPasswordResource::collection(
                $this->whenLoaded('applicationPasswords')
            ),
        ];
    }
}