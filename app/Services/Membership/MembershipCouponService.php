<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipCoupon;
use App\Models\Membership\MembershipCouponUsage;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\UserMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipCouponService
{
    public function validateForPlan(
        ?string $couponCode,
        User $user,
        mixed $plan,
        float $subtotal
    ): array {
        if (!$couponCode) {
            return $this->emptyCouponResult();
        }

        $coupon = MembershipCoupon::query()
            ->where('code', strtoupper(trim($couponCode)))
            ->first();

        if (!$coupon) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Invalid coupon code.'],
            ]);
        }

        $this->validateCouponRules($coupon, $user, $plan, $subtotal);

        $discountAmount = $this->calculateDiscount($coupon, $subtotal);

        return [
            'is_valid' => true,
            'coupon' => $coupon,
            'coupon_id' => (int) $coupon->id,
            'code' => $coupon->code,
            'title' => $coupon->title,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'discount_amount' => $discountAmount,
            'message' => 'Coupon applied successfully.',
        ];
    }

    public function calculateDiscount(MembershipCoupon $coupon, float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0;
        }

        if ($coupon->discount_type === MembershipCoupon::DISCOUNT_PERCENTAGE) {
            $discount = ($subtotal * (float) $coupon->discount_value) / 100;
        } else {
            $discount = (float) $coupon->discount_value;
        }

        if ($coupon->maximum_discount_amount !== null) {
            $discount = min($discount, (float) $coupon->maximum_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }

    public function recordUsageForMembershipOrder(MembershipOrder $order): void
    {
        if (!$order->coupon_id) {
            return;
        }

        if ($order->payment_status !== MembershipOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['Coupon usage can be recorded only after successful payment.'],
            ]);
        }

        DB::transaction(function () use ($order) {
            $alreadyUsed = MembershipCouponUsage::query()
                ->where('membership_order_id', $order->id)
                ->exists();

            if ($alreadyUsed) {
                return;
            }

            $coupon = MembershipCoupon::query()
                ->where('id', $order->coupon_id)
                ->lockForUpdate()
                ->first();

            if (!$coupon) {
                return;
            }

            MembershipCouponUsage::query()->create([
                'coupon_id' => $coupon->id,
                'user_id' => $order->user_id,
                'membership_order_id' => $order->id,
                'addon_order_id' => null,
                'used_at' => now(),
            ]);

            $coupon->increment('used_count');
        });
    }

    public function emptyCouponResult(): array
    {
        return [
            'is_valid' => false,
            'coupon' => null,
            'coupon_id' => null,
            'code' => null,
            'title' => null,
            'discount_type' => null,
            'discount_value' => 0,
            'discount_amount' => 0,
            'message' => 'No coupon applied.',
        ];
    }

    private function validateCouponRules(
        MembershipCoupon $coupon,
        User $user,
        mixed $plan,
        float $subtotal
    ): void {
        if (!$coupon->isActive()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is not active or has expired.'],
            ]);
        }

        if ((float) $coupon->minimum_order_amount > 0 && $subtotal < (float) $coupon->minimum_order_amount) {
            throw ValidationException::withMessages([
                'coupon_code' => [
                    'Minimum order amount for this coupon is ₹' . number_format((float) $coupon->minimum_order_amount, 2),
                ],
            ]);
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon usage limit has been reached.'],
            ]);
        }

        $userUsageCount = MembershipCouponUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->count();

        if ($coupon->usage_limit_per_user > 0 && $userUsageCount >= $coupon->usage_limit_per_user) {
            throw ValidationException::withMessages([
                'coupon_code' => ['You have already used this coupon.'],
            ]);
        }

        if ($coupon->new_user_only && $this->userHasPreviousMembership($user)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is available only for new membership users.'],
            ]);
        }

        if (!empty($coupon->allowed_plan_ids)) {
            $allowedPlanIds = collect($coupon->allowed_plan_ids)
                ->map(fn($id) => (int) $id)
                ->values()
                ->all();

            if (!in_array((int) $plan->id, $allowedPlanIds, true)) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['This coupon is not valid for the selected plan.'],
                ]);
            }
        }

        if (!empty($coupon->allowed_category_ids)) {
            $allowedCategoryIds = collect($coupon->allowed_category_ids)
                ->map(fn($id) => (int) $id)
                ->values()
                ->all();

            if (!in_array((int) $plan->category_id, $allowedCategoryIds, true)) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['This coupon is not valid for this plan category.'],
                ]);
            }
        }
    }

    private function userHasPreviousMembership(User $user): bool
    {
        return UserMembership::query()
            ->where('user_id', $user->id)
            ->exists();
    }
    public function validateForAddon(
        ?string $couponCode,
        User $user,
        mixed $addon,
        float $subtotal
    ): array {
        if (!$couponCode) {
            return $this->emptyCouponResult();
        }

        $coupon = MembershipCoupon::query()
            ->where('code', strtoupper(trim($couponCode)))
            ->first();

        if (!$coupon) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Invalid coupon code.'],
            ]);
        }

        if (!$coupon->isActive()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is not active or has expired.'],
            ]);
        }

        if ((float) $coupon->minimum_order_amount > 0 && $subtotal < (float) $coupon->minimum_order_amount) {
            throw ValidationException::withMessages([
                'coupon_code' => [
                    'Minimum order amount for this coupon is ₹' . number_format((float) $coupon->minimum_order_amount, 2),
                ],
            ]);
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon usage limit has been reached.'],
            ]);
        }

        $userUsageCount = MembershipCouponUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->count();

        if ($coupon->usage_limit_per_user > 0 && $userUsageCount >= $coupon->usage_limit_per_user) {
            throw ValidationException::withMessages([
                'coupon_code' => ['You have already used this coupon.'],
            ]);
        }

        /*
     * Current coupon table is plan/category-based.
     * So add-on coupons should usually be created without allowed_plan_ids and allowed_category_ids.
     */
        if (!empty($coupon->allowed_plan_ids) || !empty($coupon->allowed_category_ids)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is not valid for add-ons.'],
            ]);
        }

        $discountAmount = $this->calculateDiscount($coupon, $subtotal);

        return [
            'is_valid' => true,
            'coupon' => $coupon,
            'coupon_id' => (int) $coupon->id,
            'code' => $coupon->code,
            'title' => $coupon->title,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'discount_amount' => $discountAmount,
            'message' => 'Coupon applied successfully.',
        ];
    }

    public function recordUsageForAddonOrder(\App\Models\Membership\MembershipAddonOrder $order): void
    {
        if (!$order->coupon_id) {
            return;
        }

        if ($order->payment_status !== \App\Models\Membership\MembershipAddonOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['Coupon usage can be recorded only after successful payment.'],
            ]);
        }

        DB::transaction(function () use ($order) {
            $alreadyUsed = MembershipCouponUsage::query()
                ->where('addon_order_id', $order->id)
                ->exists();

            if ($alreadyUsed) {
                return;
            }

            $coupon = MembershipCoupon::query()
                ->where('id', $order->coupon_id)
                ->lockForUpdate()
                ->first();

            if (!$coupon) {
                return;
            }

            MembershipCouponUsage::query()->create([
                'coupon_id' => $coupon->id,
                'user_id' => $order->user_id,
                'membership_order_id' => null,
                'addon_order_id' => $order->id,
                'used_at' => now(),
            ]);

            $coupon->increment('used_count');
        });
    }
}
