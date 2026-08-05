<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipCreditBalance;
use App\Models\Membership\MembershipCreditTransaction;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipActivationService
{
    public function __construct(
        private readonly MembershipAccessService $accessService
    ) {}

    public function activateFromOrder(MembershipOrder $order): UserMembership
    {
        $order = MembershipOrder::query()
            ->with([
                'user',
                'plan.category',
                'plan.planFeatures.feature',
                'membership',
            ])
            ->findOrFail($order->id);

        if ($order->payment_status !== MembershipOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['Only paid membership orders can be activated.'],
            ]);
        }

        if ($order->membership) {
            return $order->membership->loadMissing([
                'user:id,first_name,last_name,email,phone,role_id',
                'creator:id,first_name,last_name,email,phone,role_id',
                'plan.category',
                'plan.planFeatures.feature',
                'order',
                'creditBalances',
            ]);
        }

        return DB::transaction(function () use ($order) {
            $order = MembershipOrder::query()
                ->with([
                    'user',
                    'plan.category',
                    'plan.planFeatures.feature',
                    'membership',
                ])
                ->where('id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->membership) {
                return $order->membership->loadMissing([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'creator:id,first_name,last_name,email,phone,role_id',
                    'plan.category',
                    'plan.planFeatures.feature',
                    'order',
                    'creditBalances',
                ]);
            }

            $user = $order->user;
            $plan = $order->plan;

            if (! $user || ! $plan) {
                throw ValidationException::withMessages([
                    'order_id' => ['Order user or plan is missing.'],
                ]);
            }

            $this->expireExistingActiveMemberships($user);

            $startDate = now();
            $expiryDate = $this->calculateExpiryDate($plan, $startDate);

            $membership = UserMembership::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'order_id' => $order->id,
                'parent_membership_id' => null,
                'start_date' => $startDate,
                'expiry_date' => $expiryDate,
                'status' => UserMembership::STATUS_ACTIVE,
                'auto_renew' => false,
                'cancelled_at' => null,
                'expired_at' => null,
                'grace_until' => null,
                'source' => 'purchase',
                'created_by' => $order->created_by ?: $user->id,
                'metadata' => [
                    'activated_from_order' => true,
                    'order_number' => $order->order_number,
                    'payment_status' => $order->payment_status,
                    'plan_snapshot' => $order->metadata['plan_snapshot'] ?? null,
                ],
            ]);

            $this->createCreditBalances($membership, $plan, $user);

            $this->audit(
                action: 'membership_activated',
                user: $user,
                auditable: $membership,
                performedBy: null,
                oldValues: null,
                newValues: $membership->fresh(['plan', 'creditBalances'])->toArray()
            );

            $this->clearCaches($user);

            $this->dispatchInvoiceJobIfExists($order);

            return $membership->fresh([
                'user:id,first_name,last_name,email,phone,role_id',
                'creator:id,first_name,last_name,email,phone,role_id',
                'plan.category',
                'plan.planFeatures.feature',
                'order',
                'creditBalances',
            ]);
        });
    }

    public function manualActivate(
        User $user,
        MembershipPlan $plan,
        ?User $admin = null,
        array $options = []
    ): UserMembership {
        return DB::transaction(function () use ($user, $plan, $admin, $options) {
            $plan->loadMissing(['category', 'planFeatures.feature']);

            if (! $plan->status) {
                throw ValidationException::withMessages([
                    'plan_id' => ['Inactive plan cannot be activated.'],
                ]);
            }

            $startDate = ! empty($options['start_date'])
                ? Carbon::parse($options['start_date'])->startOfDay()
                : now();

            $expiryDate = ! empty($options['expiry_date'])
                ? Carbon::parse($options['expiry_date'])->endOfDay()
                : $this->calculateExpiryDate($plan, $startDate);

            if ($expiryDate->lessThanOrEqualTo($startDate)) {
                throw ValidationException::withMessages([
                    'expiry_date' => ['Expiry date must be greater than start date.'],
                ]);
            }

            $this->expireExistingActiveMemberships($user);

            $subtotal = round((float) $plan->payableAmount(), 2);
            $gstPercentage = 18.00;
            $gstAmount = round(($subtotal * $gstPercentage) / 100, 2);
            $totalAmount = round($subtotal + $gstAmount, 2);

            $order = MembershipOrder::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'coupon_id' => null,
                'order_number' => $this->generateManualOrderNumber(),

                'gateway_name' => 'manual',
                'razorpay_order_id' => null,

                'currency' => $plan->currency ?: 'INR',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'taxable_amount' => $subtotal,
                'gst_percentage' => $gstPercentage,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,

                'payment_status' => MembershipOrder::PAYMENT_PAID,
                'order_status' => MembershipOrder::STATUS_COMPLETED,
                'payment_method' => 'manual',

                'expires_at' => null,
                'paid_at' => now(),
                'cancelled_at' => null,

                'created_by' => $admin?->id,
                'notes' => $options['reason'] ?? $options['notes'] ?? 'Manual membership activation by admin.',
                'metadata' => [
                    'source' => 'manual',
                    'manual_activation' => true,
                    'activated_by_admin' => $admin?->id,
                    'reason' => $options['reason'] ?? null,
                    'notes' => $options['notes'] ?? null,
                    'plan_snapshot' => [
                        'id' => (int) $plan->id,
                        'category_id' => (int) $plan->category_id,
                        'name' => $plan->name,
                        'slug' => $plan->slug,
                        'currency' => $plan->currency,
                        'price' => (float) $plan->price,
                        'sale_price' => $plan->sale_price !== null ? (float) $plan->sale_price : null,
                        'payable_amount' => $plan->payableAmount(),
                        'duration' => (int) $plan->duration,
                        'duration_type' => $plan->duration_type,
                    ],
                ],
            ]);

            $membership = UserMembership::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'order_id' => $order->id,
                'parent_membership_id' => null,
                'start_date' => $startDate,
                'expiry_date' => $expiryDate,
                'status' => UserMembership::STATUS_ACTIVE,
                'auto_renew' => false,
                'cancelled_at' => null,
                'expired_at' => null,
                'grace_until' => null,
                'source' => 'manual',
                'created_by' => $admin?->id,
                'metadata' => [
                    'manual_reason' => $options['reason'] ?? null,
                    'manual_notes' => $options['notes'] ?? null,
                    'activated_by_admin' => $admin?->id,
                    'manual_order_id' => $order->id,
                    'manual_order_number' => $order->order_number,
                ],
            ]);

            $this->createCreditBalances($membership, $plan, $user);

            $this->audit(
                action: 'membership_manual_activated',
                user: $user,
                auditable: $membership,
                performedBy: $admin,
                oldValues: null,
                newValues: $membership->fresh(['plan', 'order', 'creditBalances'])->toArray()
            );

            $this->clearCaches($user);

            return $membership->fresh([
                'user:id,first_name,last_name,email,phone,role_id',
                'creator:id,first_name,last_name,email,phone,role_id',
                'plan.category',
                'plan.planFeatures.feature',
                'order',
                'creditBalances',
            ]);
        });
    }

    public function expireMembership(UserMembership $membership, ?User $performedBy = null): UserMembership
    {
        return DB::transaction(function () use ($membership, $performedBy) {
            $membership = UserMembership::query()
                ->where('id', $membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->status === UserMembership::STATUS_EXPIRED) {
                return $membership->fresh([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'creator:id,first_name,last_name,email,phone,role_id',
                    'plan.category',
                    'order',
                    'creditBalances',
                ]);
            }

            $oldValues = $membership->toArray();

            $membership->update([
                'status' => UserMembership::STATUS_EXPIRED,
                'expired_at' => now(),
            ]);

            MembershipCreditBalance::query()
                ->where('membership_id', $membership->id)
                ->update([
                    'status' => false,
                    'updated_at' => now(),
                ]);

            $this->audit(
                action: 'membership_expired',
                user: $membership->user,
                auditable: $membership,
                performedBy: $performedBy,
                oldValues: $oldValues,
                newValues: $membership->fresh()->toArray()
            );

            $this->clearCaches($membership->user);

            return $membership->fresh([
                'user:id,first_name,last_name,email,phone,role_id',
                'creator:id,first_name,last_name,email,phone,role_id',
                'plan.category',
                'order',
                'creditBalances',
            ]);
        });
    }

    public function cancelMembership(
        UserMembership $membership,
        ?User $performedBy = null,
        ?string $reason = null
    ): UserMembership {
        return DB::transaction(function () use ($membership, $performedBy, $reason) {
            $membership = UserMembership::query()
                ->where('id', $membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->status === UserMembership::STATUS_CANCELLED) {
                return $membership->fresh([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'creator:id,first_name,last_name,email,phone,role_id',
                    'plan.category',
                    'order',
                    'creditBalances',
                ]);
            }

            $oldValues = $membership->toArray();

            $membership->update([
                'status' => UserMembership::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'metadata' => array_merge($membership->metadata ?? [], [
                    'cancel_reason' => $reason,
                    'cancelled_by' => $performedBy?->id,
                ]),
            ]);

            MembershipCreditBalance::query()
                ->where('membership_id', $membership->id)
                ->update([
                    'status' => false,
                    'updated_at' => now(),
                ]);

            $this->audit(
                action: 'membership_cancelled',
                user: $membership->user,
                auditable: $membership,
                performedBy: $performedBy,
                oldValues: $oldValues,
                newValues: $membership->fresh()->toArray()
            );

            $this->clearCaches($membership->user);

            return $membership->fresh([
                'user:id,first_name,last_name,email,phone,role_id',
                'creator:id,first_name,last_name,email,phone,role_id',
                'plan.category',
                'order',
                'creditBalances',
            ]);
        });
    }

    private function expireExistingActiveMemberships(User $user): void
    {
        $memberships = UserMembership::query()
            ->where('user_id', $user->id)
            ->where('status', UserMembership::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($memberships as $membership) {
            $membership->update([
                'status' => UserMembership::STATUS_EXPIRED,
                'expired_at' => now(),
            ]);

            MembershipCreditBalance::query()
                ->where('membership_id', $membership->id)
                ->update([
                    'status' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    private function calculateExpiryDate(MembershipPlan $plan, Carbon $startDate): Carbon
    {
        $duration = max((int) $plan->duration, 1);
        $durationType = strtolower((string) $plan->duration_type);

        return match ($durationType) {
            'day', 'days' => $startDate->copy()->addDays($duration),
            'month', 'months' => $startDate->copy()->addMonths($duration),
            'year', 'years' => $startDate->copy()->addYears($duration),
            default => $startDate->copy()->addDays($duration),
        };
    }

    private function createCreditBalances(
        UserMembership $membership,
        MembershipPlan $plan,
        User $user
    ): void {
        $plan->loadMissing(['planFeatures.feature']);

        foreach ($plan->planFeatures as $planFeature) {
            $feature = $planFeature->feature;

            if (! $feature || ! $feature->status) {
                continue;
            }

            $creditType = $this->creditTypeFromFeatureSlug($feature->slug);

            if (! $creditType) {
                continue;
            }

            $isUnlimited = (bool) $planFeature->is_unlimited
                || strtolower((string) $planFeature->feature_value) === 'unlimited';

            $totalCredits = $isUnlimited
                ? null
                : max(0, (int) $planFeature->feature_value);

            $remainingCredits = $isUnlimited
                ? null
                : $totalCredits;

            $balance = MembershipCreditBalance::query()->create([
                'user_id' => $user->id,
                'membership_id' => $membership->id,
                'credit_type' => $creditType,
                'is_unlimited' => $isUnlimited,
                'total_credits' => $totalCredits,
                'used_credits' => 0,
                'remaining_credits' => $remainingCredits,
                'status' => true,
                'expires_at' => $membership->expiry_date,
            ]);

            MembershipCreditTransaction::query()->create([
                'user_id' => $user->id,
                'membership_id' => $membership->id,
                'balance_id' => $balance->id,
                'credit_type' => $creditType,
                'transaction_type' => MembershipCreditTransaction::TYPE_CREDIT,
                'quantity' => $totalCredits ?? 0,
                'balance_before' => 0,
                'balance_after' => $remainingCredits,
                'reference_type' => 'membership_activation',
                'reference_id' => $membership->id,
                'reason' => 'Credits created from membership activation.',
                'performed_by' => null,
                'metadata' => [
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'feature_slug' => $feature->slug,
                    'is_unlimited' => $isUnlimited,
                ],
            ]);
        }
    }

    private function creditTypeFromFeatureSlug(string $featureSlug): ?string
    {
        return match ($featureSlug) {
            'listing_limit',
            'active_property_listings' => MembershipCreditBalance::TYPE_LISTING,

            'featured_listing_limit',
            'featured_listing_credits' => MembershipCreditBalance::TYPE_FEATURED_LISTING,

            'boost_limit',
            'listing_boost_credits',
            'project_boost_credits' => MembershipCreditBalance::TYPE_BOOST,

            'lead_view_limit',
            'buyer_contact_credits' => MembershipCreditBalance::TYPE_LEAD_VIEW,

            'video_upload_limit',
            'property_videos',
            'project_videos',
            'project_walkthrough_videos' => MembershipCreditBalance::TYPE_VIDEO_UPLOAD,

            'virtual_tour_limit',
            'virtual_tour_credits' => MembershipCreditBalance::TYPE_VIRTUAL_TOUR,

            'ai_description_limit',
            'ai_description_credits' => MembershipCreditBalance::TYPE_AI_DESCRIPTION,

            default => null,
        };
    }

    private function generateManualOrderNumber(): string
    {
        do {
            $number = 'HPMMAN'
                . now()->format('YmdHis')
                . strtoupper(Str::random(6));
        } while (MembershipOrder::query()->where('order_number', $number)->exists());

        return $number;
    }

    private function dispatchInvoiceJobIfExists(MembershipOrder $order): void
    {
        $jobClass = \App\Jobs\Membership\GenerateMembershipInvoiceJob::class;

        if (! class_exists($jobClass)) {
            return;
        }

        dispatch(new $jobClass($order->id))->onQueue('membership');
    }

    private function clearCaches(?User $user): void
    {
        if ($user) {
            $this->accessService->forgetUserCache($user);
        }

        Cache::store('redis')->forget('membership:admin:stats');
    }

    private function audit(
        string $action,
        ?User $user,
        ?object $auditable,
        ?User $performedBy,
        ?array $oldValues,
        ?array $newValues
    ): void {
        if (! Schema::hasTable('membership_audit_logs')) {
            return;
        }

        MembershipAuditLog::query()->create([
            'user_id' => $user?->id,
            'performed_by' => $performedBy?->id,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id ?? null,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
