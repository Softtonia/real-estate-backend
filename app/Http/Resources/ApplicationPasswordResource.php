<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationPasswordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'api_client_id' => $this->api_client_id,
            'name' => $this->name,
            'token_prefix' => $this->token_prefix,
            'abilities' => $this->abilities ?? [],
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'last_used_at' => $this->last_used_at,
            'last_used_ip' => $this->last_used_ip,
            'created_at' => $this->created_at,
        ];
    }
}