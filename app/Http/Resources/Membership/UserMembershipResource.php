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

            'user' => $this->whenLoaded('user', function () {
                return $this->user ? [
                    'id' => (int) $this->user->id,
                    'name' => trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? '')),
                    'first_name' => $this->user->first_name ?? null,
                    'last_name' => $this->user->last_name ?? null,
                    'email' => $this->user->email ?? null,
                    'phone' => $this->user->phone ?? null,
                    'role_id' => $this->user->role_id ?? null,
                ] : null;
            }),

            'plan_id' => (int) $this->plan_id,

            'plan' => $this->whenLoaded('plan', function () {
                return $this->plan ? [
                    'id' => (int) $this->plan->id,
                    'category_id' => $this->plan->category_id ? (int) $this->plan->category_id : null,
                    'name' => $this->plan->name,
                    'slug' => $this->plan->slug,
                    'duration' => (int) $this->plan->duration,
                    'duration_type' => $this->plan->duration_type,

                    'category' => $this->plan->relationLoaded('category') && $this->plan->category ? [
                        'id' => (int) $this->plan->category->id,
                        'name' => $this->plan->category->name,
                        'slug' => $this->plan->category->slug,
                    ] : null,
                ] : null;
            }),

            'order_id' => $this->order_id ? (int) $this->order_id : null,

            'order' => $this->whenLoaded('order', function () {
                return $this->order ? [
                    'id' => (int) $this->order->id,
                    'order_number' => $this->order->order_number,
                    'gateway_name' => $this->order->gateway_name,
                    'razorpay_order_id' => $this->order->razorpay_order_id,
                    'currency' => $this->order->currency,
                    'subtotal' => (float) $this->order->subtotal,
                    'discount_amount' => (float) $this->order->discount_amount,
                    'taxable_amount' => (float) $this->order->taxable_amount,
                    'gst_percentage' => (float) $this->order->gst_percentage,
                    'gst_amount' => (float) $this->order->gst_amount,
                    'total_amount' => (float) $this->order->total_amount,
                    'payment_status' => $this->order->payment_status,
                    'order_status' => $this->order->order_status,
                    'payment_method' => $this->order->payment_method,
                    'paid_at' => optional($this->order->paid_at)->toDateTimeString(),
                    'created_at' => optional($this->order->created_at)->toDateTimeString(),
                ] : null;
            }),

            'parent_membership_id' => $this->parent_membership_id ? (int) $this->parent_membership_id : null,

            'status' => $this->status,
            'source' => $this->source,
            'auto_renew' => (bool) $this->auto_renew,

            'start_date' => optional($this->start_date)->toDateTimeString(),
            'expiry_date' => optional($this->expiry_date)->toDateTimeString(),
            'cancelled_at' => optional($this->cancelled_at)->toDateTimeString(),
            'expired_at' => optional($this->expired_at)->toDateTimeString(),
            'grace_until' => optional($this->grace_until)->toDateTimeString(),

            'created_by' => $this->created_by ? (int) $this->created_by : null,

            'creator' => $this->whenLoaded('creator', function () {
                return $this->creator ? [
                    'id' => (int) $this->creator->id,
                    'name' => trim(($this->creator->first_name ?? '') . ' ' . ($this->creator->last_name ?? '')),
                    'first_name' => $this->creator->first_name ?? null,
                    'last_name' => $this->creator->last_name ?? null,
                    'email' => $this->creator->email ?? null,
                    'phone' => $this->creator->phone ?? null,
                    'role_id' => $this->creator->role_id ?? null,
                ] : null;
            }),

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

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}