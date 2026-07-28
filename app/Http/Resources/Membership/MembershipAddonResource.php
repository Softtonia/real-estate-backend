<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipAddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            'addon_type' => $this->addon_type,

            'currency' => $this->currency,
            'price' => (float) $this->price,
            'sale_price' => $this->sale_price !== null ? (float) $this->sale_price : null,
            'payable_amount' => $this->payableAmount(),

            'credit_type' => $this->credit_type,
            'credit_quantity' => $this->credit_quantity !== null ? (int) $this->credit_quantity : null,
            'duration_days' => $this->duration_days !== null ? (int) $this->duration_days : null,

            'status' => (bool) $this->status,
            'sort_order' => (int) $this->sort_order,
            'metadata' => $this->metadata ?? [],
        ];
    }
}