<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'plan_id' => (int) $this->plan_id,
            'order_id' => $this->order_id ? (int) $this->order_id : null,

            'plan' => $this->whenLoaded('plan', function () {
                return [
                    'id' => (int) $this->plan->id,
                    'name' => $this->plan->name,
                    'slug' => $this->plan->slug,
                    'duration' => (int) $this->plan->duration,
                    'duration_type' => $this->plan->duration_type,
                ];
            }),

            'status' => $this->status,
            'source' => $this->source,
            'auto_renew' => (bool) $this->auto_renew,

            'start_date' => optional($this->start_date)->toDateTimeString(),
            'expiry_date' => optional($this->expiry_date)->toDateTimeString(),
            'cancelled_at' => optional($this->cancelled_at)->toDateTimeString(),
            'expired_at' => optional($this->expired_at)->toDateTimeString(),

            'credits' => $this->whenLoaded('creditBalances', function () {
                return $this->creditBalances->map(function ($balance) {
                    return [
                        'id' => (int) $balance->id,
                        'credit_type' => $balance->credit_type,
                        'is_unlimited' => (bool) $balance->is_unlimited,
                        'total_credits' => $balance->total_credits !== null ? (int) $balance->total_credits : null,
                        'used_credits' => (int) $balance->used_credits,
                        'remaining_credits' => $balance->remaining_credits !== null ? (int) $balance->remaining_credits : null,
                        'expires_at' => optional($balance->expires_at)->toDateTimeString(),
                    ];
                })->values();
            }),
        ];
    }
}