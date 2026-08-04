<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'created_by' => $this->created_by ? (int) $this->created_by : null,

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

            'created_by_user' => $this->whenLoaded('createdBy', function () {
                return $this->createdBy ? [
                    'id' => (int) $this->createdBy->id,
                    'name' => trim(($this->createdBy->first_name ?? '') . ' ' . ($this->createdBy->last_name ?? '')),
                    'first_name' => $this->createdBy->first_name ?? null,
                    'last_name' => $this->createdBy->last_name ?? null,
                    'email' => $this->createdBy->email ?? null,
                    'phone' => $this->createdBy->phone ?? null,
                    'role_id' => $this->createdBy->role_id ?? null,
                ] : null;
            }),

            'order_number' => $this->order_number,

            'plan' => $this->whenLoaded('plan', function () {
                return $this->plan ? [
                    'id' => (int) $this->plan->id,
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

            'coupon' => $this->whenLoaded('coupon', function () {
                return $this->coupon ? [
                    'id' => (int) $this->coupon->id,
                    'code' => $this->coupon->code,
                    'title' => $this->coupon->title,
                ] : null;
            }),

            'gateway_name' => $this->gateway_name,
            'razorpay_order_id' => $this->razorpay_order_id,

            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'taxable_amount' => (float) $this->taxable_amount,
            'gst_percentage' => (float) $this->gst_percentage,
            'gst_amount' => (float) $this->gst_amount,
            'total_amount' => (float) $this->total_amount,

            'payment_status' => $this->payment_status,
            'order_status' => $this->order_status,
            'payment_method' => $this->payment_method,

            'expires_at' => optional($this->expires_at)->toDateTimeString(),
            'paid_at' => optional($this->paid_at)->toDateTimeString(),
            'cancelled_at' => optional($this->cancelled_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}