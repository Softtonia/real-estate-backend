<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipAddonOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'order_number' => $this->order_number,

            'addon' => $this->whenLoaded('addon', function () {
                return [
                    'id' => (int) $this->addon->id,
                    'name' => $this->addon->name,
                    'slug' => $this->addon->slug,
                    'addon_type' => $this->addon->addon_type,
                    'credit_type' => $this->addon->credit_type,
                    'credit_quantity' => $this->addon->credit_quantity !== null ? (int) $this->addon->credit_quantity : null,
                ];
            }),

            'membership_id' => $this->membership_id ? (int) $this->membership_id : null,

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

            'paid_at' => optional($this->paid_at)->toDateTimeString(),
            'cancelled_at' => optional($this->cancelled_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}