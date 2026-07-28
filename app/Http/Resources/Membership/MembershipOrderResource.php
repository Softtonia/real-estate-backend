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
            'order_number' => $this->order_number,

            'plan' => $this->whenLoaded('plan', function () {
                return [
                    'id' => (int) $this->plan->id,
                    'name' => $this->plan->name,
                    'slug' => $this->plan->slug,
                    'duration' => (int) $this->plan->duration,
                    'duration_type' => $this->plan->duration_type,
                ];
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