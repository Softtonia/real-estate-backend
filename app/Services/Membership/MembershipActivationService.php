<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipCreditBalance;
use App\Models\Membership\MembershipCreditTransaction;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanFeature;
use App\Models\Membership\UserMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
                'plan.category',
                'creditBalances',
            ]);
        }

        return DB::transaction(function () use ($order) {
            $order = MembershipOrder::query()
                ->with([
                    'user',
                    'plan.planFeatures.feature',
                    'membership',
                ])
                ->where('id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->membership) {
                return $order->membership->loadMissing([
                    'plan.category',
                    'creditBalances',
                ]);
            }

            $user = $order->user;
            $plan = $order->plan;

            if (!$user || !$plan) {
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
                'plan.category',
                'plan.planFeatures.feature',
                'creditBalances',
                'order',
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
            $plan->loadMissing(['planFeatures.feature']);

            if (!$plan->status) {
                throw ValidationException::withMessages([
                    'plan_id' => ['Inactive plan cannot be activated.'],
                ]);
            }

            $this->expireExistingActiveMemberships($user);

            $startDate = isset($options['start_date'])
                ? Carbon::parse($options['start_date'])
                : now();

            $expiryDate = isset($options['expiry_date'])
                ? Carbon::parse($options['expiry_date'])
                : $this->calculateExpiryDate($plan, $startDate);

            $membership = UserMembership::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'order_id' => null,
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
                    'activated_by_admin' => $admin?->id,
                ],
            ]);

            $this->createCreditBalances($membership, $plan, $user);

            $this->audit(
                action: 'membership_manual_activated',
                user: $user,
                auditable: $membership,
                performedBy: $admin,
                oldValues: null,
                newValues: $membership->fresh(['plan', 'creditBalances'])->toArray()
            );

            $this->clearCaches($user);

            return $membership->fresh([
                'plan.category',
                'plan.planFeatures.feature',
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
                return $membership;
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

            return $membership->fresh(['plan', 'creditBalances']);
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
                return $membership;
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

            return $membership->fresh(['plan', 'creditBalances']);
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
        return match ($plan->duration_type) {
            MembershipPlan::DURATION_DAYS => $startDate->copy()->addDays((int) $plan->duration),
            MembershipPlan::DURATION_YEARS => $startDate->copy()->addYears((int) $plan->duration),
            default => $startDate->copy()->addMonths((int) $plan->duration),
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

            if (!$feature || !$feature->status) {
                continue;
            }

            $creditType = $this->creditTypeFromFeatureSlug($feature->slug);

            if (!$creditType) {
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
            'listing_limit' => MembershipCreditBalance::TYPE_LISTING,
            'featured_listing_limit' => MembershipCreditBalance::TYPE_FEATURED_LISTING,
            'boost_limit' => MembershipCreditBalance::TYPE_BOOST,
            'lead_view_limit' => MembershipCreditBalance::TYPE_LEAD_VIEW,
            'video_upload_limit' => MembershipCreditBalance::TYPE_VIDEO_UPLOAD,
            'virtual_tour_limit' => MembershipCreditBalance::TYPE_VIRTUAL_TOUR,
            default => null,
        };
    }

    private function dispatchInvoiceJobIfExists(MembershipOrder $order): void
    {
        $jobClass = \App\Jobs\Membership\GenerateMembershipInvoiceJob::class;

        if (!class_exists($jobClass)) {
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
        if (!Schema::hasTable('membership_audit_logs')) {
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