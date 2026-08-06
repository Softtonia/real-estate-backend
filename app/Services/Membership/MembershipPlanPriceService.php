<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipPlan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MembershipPlanPriceService
{
    public function __construct(
        private readonly MembershipCouponService $couponService,
        private readonly MembershipTaxService $taxService
    ) {}

    public function calculate(
        MembershipPlan $plan,
        ?User $user = null,
        ?string $couponCode = null
    ): array {
        $subtotal = round((float) $plan->payableAmount(), 2);

        $couponCode = $couponCode ? strtoupper(trim($couponCode)) : null;

        $couponResult = [
            'coupon_id' => null,
            'coupon' => null,
            'discount_amount' => 0,
        ];

        if ($couponCode) {
            if (! $user) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['Login is required to apply coupon.'],
                ]);
            }

            $couponResult = $this->couponService->validateForPlan(
                couponCode: $couponCode,
                user: $user,
                plan: $plan,
                subtotal: $subtotal
            );
        }

        $discountAmount = round((float) ($couponResult['discount_amount'] ?? 0), 2);
        $discountAmount = min(max($discountAmount, 0), $subtotal);

        $taxableAmount = round($subtotal - $discountAmount, 2);

        $taxCalculation = $this->taxService->calculate($taxableAmount);

        $gstPercentage = round((float) ($taxCalculation['gst_percentage'] ?? 0), 2);
        $gstAmount = round((float) ($taxCalculation['tax_amount'] ?? 0), 2);
        $totalAmount = round((float) ($taxCalculation['total_amount'] ?? $taxableAmount), 2);

        return [
            'currency' => $plan->currency ?: 'INR',

            'price' => round((float) $plan->price, 2),
            'sale_price' => $plan->sale_price !== null
                ? round((float) $plan->sale_price, 2)
                : null,

            'subtotal' => $subtotal,

            'coupon' => $couponResult['coupon']
                ? [
                    'id' => (int) $couponResult['coupon']->id,
                    'code' => $couponResult['coupon']->code,
                    'title' => $couponResult['coupon']->title,
                    'discount_type' => $couponResult['coupon']->discount_type,
                    'discount_value' => (float) $couponResult['coupon']->discount_value,
                ]
                : null,

            'coupon_code' => $couponCode,
            'coupon_applied' => $couponResult['coupon'] ? true : false,

            'discount_amount' => $discountAmount,
            'taxable_amount' => $taxableAmount,

            'gst_enabled' => (bool) ($taxCalculation['gst_enabled'] ?? false),
            'gst_percentage' => $gstPercentage,
            'gst_amount' => $gstAmount,
            'tax_label' => $taxCalculation['tax_label'] ?? 'GST',
            'prices_include_tax' => (bool) ($taxCalculation['prices_include_tax'] ?? false),

            'total_amount' => $totalAmount,
            'payable_amount' => $totalAmount,
        ];
    }

    public function calculateMany($plans, ?User $user = null, ?string $couponCode = null): array
    {
        $result = [];

        foreach ($plans as $plan) {
            $result[$plan->id] = $this->calculate(
                plan: $plan,
                user: $user,
                couponCode: $couponCode
            );
        }

        return $result;
    }
}