<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);

         return [
            'id'             => $this->id,
            'user_unique_id' => $this->unique_id ?? null,
            'first_name'           => $this->first_name,
            'last_name'            => $this->last_name,
            'name'            => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')),
            'role'           => $this->role,
            'email'          => $this->when(isset($this->email), $this->email),
            // Add public profile fields here (avoid private fields)
        ];
    }
}
