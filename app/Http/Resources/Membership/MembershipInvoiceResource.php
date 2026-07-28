<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,

            'membership_order_id' => $this->membership_order_id ? (int) $this->membership_order_id : null,
            'addon_order_id' => $this->addon_order_id ? (int) $this->addon_order_id : null,
            'user_id' => (int) $this->user_id,

            'invoice_number' => $this->invoice_number,
            'invoice_date' => optional($this->invoice_date)->toDateTimeString(),

            'currency' => $this->currency,
            'taxable_amount' => (float) $this->taxable_amount,
            'gst_percentage' => (float) $this->gst_percentage,
            'cgst_amount' => (float) $this->cgst_amount,
            'sgst_amount' => (float) $this->sgst_amount,
            'igst_amount' => (float) $this->igst_amount,
            'gst_amount' => (float) $this->gst_amount,
            'total_amount' => (float) $this->total_amount,

            'billing' => [
                'name' => $this->billing_name,
                'email' => $this->billing_email,
                'phone' => $this->billing_phone,
                'gst_number' => $this->billing_gst_number,
                'address' => $this->billing_address,
                'city' => $this->billing_city,
                'state' => $this->billing_state,
                'country' => $this->billing_country,
                'pincode' => $this->billing_pincode,
            ],

            'membership_order' => $this->whenLoaded('membershipOrder', function () {
                return $this->membershipOrder ? [
                    'id' => (int) $this->membershipOrder->id,
                    'order_number' => $this->membershipOrder->order_number,
                    'total_amount' => (float) $this->membershipOrder->total_amount,
                    'payment_status' => $this->membershipOrder->payment_status,
                    'order_status' => $this->membershipOrder->order_status,
                    'plan' => $this->membershipOrder->relationLoaded('plan') && $this->membershipOrder->plan ? [
                        'id' => (int) $this->membershipOrder->plan->id,
                        'name' => $this->membershipOrder->plan->name,
                        'slug' => $this->membershipOrder->plan->slug,
                    ] : null,
                ] : null;
            }),

            'addon_order' => $this->whenLoaded('addonOrder', function () {
                return $this->addonOrder ? [
                    'id' => (int) $this->addonOrder->id,
                    'order_number' => $this->addonOrder->order_number,
                    'total_amount' => (float) $this->addonOrder->total_amount,
                    'payment_status' => $this->addonOrder->payment_status,
                    'order_status' => $this->addonOrder->order_status,
                    'addon' => $this->addonOrder->relationLoaded('addon') && $this->addonOrder->addon ? [
                        'id' => (int) $this->addonOrder->addon->id,
                        'name' => $this->addonOrder->addon->name,
                        'slug' => $this->addonOrder->addon->slug,
                    ] : null,
                ] : null;
            }),

            'has_pdf' => !empty($this->invoice_pdf_disk) && !empty($this->invoice_pdf_path),
            'status' => $this->status,
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}