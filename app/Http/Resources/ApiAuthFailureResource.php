<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiAuthFailureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'api_client_id' => $this->api_client_id,
            'reason' => $this->reason,
            'token_prefix' => $this->token_prefix,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'method' => $this->method,
            'path' => $this->path,
            'origin' => $this->origin,
            'created_at' => $this->created_at,
        ];
    }
}