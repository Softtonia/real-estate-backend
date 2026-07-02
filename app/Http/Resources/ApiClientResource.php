<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiClientResource extends JsonResource
{
public function toArray($request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'slug' => $this->slug,
        'type' => $this->type,
        'status' => method_exists($this->resource, 'isActive')
            ? $this->isActive()
            : (bool) $this->status,

        'description' => $this->description,
        'application_passwords_count' => $this->whenCounted('applicationPasswords'),

        'application_passwords' => $this->whenLoaded('applicationPasswords', function () {
            return $this->applicationPasswords->map(function ($password) {
                return [
                    'application_password_id' => $password->id,
                    'application_token_id' => $password->id,
                    'application_password_name' => $password->name,
                    'token_prefix' => $password->token_prefix,
                    'abilities' => $password->abilities ?? [],
                    'is_valid' => $password->isValid(),
                    'last_used_at' => $password->last_used_at,
                    'expires_at' => $password->expires_at,
                    'revoked_at' => $password->revoked_at,
                    'created_at' => $password->created_at,
                ];
            })->values();
        }),

        'last_used_at' => $this->last_used_at,
        'created_at' => $this->created_at,
        'updated_at' => $this->updated_at,
    ];
}
}