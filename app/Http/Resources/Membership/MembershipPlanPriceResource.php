<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipPlanPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : [];

        return [
            'currency' => $data['currency'] ?? 'INR',
            'price' => (float) ($data['price'] ?? 0),
            'sale_price' => array_key_exists('sale_price', $data) && $data['sale_price'] !== null
                ? (float) $data['sale_price']
                : null,

            'subtotal' => (float) ($data['subtotal'] ?? 0),

            'coupon' => $data['coupon'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? null,
            'coupon_applied' => (bool) ($data['coupon_applied'] ?? false),

            'discount_amount' => (float) ($data['discount_amount'] ?? 0),
            'taxable_amount' => (float) ($data['taxable_amount'] ?? 0),

            'gst_enabled' => (bool) ($data['gst_enabled'] ?? false),
            'gst_percentage' => (float) ($data['gst_percentage'] ?? 0),
            'gst_amount' => (float) ($data['gst_amount'] ?? 0),
            'tax_label' => $data['tax_label'] ?? 'GST',
            'prices_include_tax' => (bool) ($data['prices_include_tax'] ?? false),

            'total_amount' => (float) ($data['total_amount'] ?? 0),
            'payable_amount' => (float) ($data['payable_amount'] ?? 0),
        ];
    }
}