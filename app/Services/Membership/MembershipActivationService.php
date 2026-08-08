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

            if (! $this->isPaidOrder($order)) {
                throw ValidationException::withMessages([
                    'order_id' => ['Only paid membership orders can be activated.'],
                ]);
            }

            $user = $order->user;
            $plan = $order->plan;

            if (! $user || ! $plan) {
                throw ValidationException::withMessages([
                    'order_id' => ['Order user or plan is missing.'],
                ]);
            }

            $this->markOrderCompletedIfNeeded($order);

            /*
            |--------------------------------------------------------------------------
            | Idempotency: if membership already exists for this order, repair it
            |--------------------------------------------------------------------------
            */
            $existingMembership = UserMembership::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existingMembership) {
                $update = [];

                if ($existingMembership->status !== UserMembership::STATUS_ACTIVE) {
                    $update['status'] = UserMembership::STATUS_ACTIVE;
                    $update['cancelled_at'] = null;
                    $update['expired_at'] = null;
                }

                if (! $existingMembership->start_date) {
                    $update['start_date'] = now();
                }

                if (! $existingMembership->expiry_date) {
                    $update['expiry_date'] = $this->calculateExpiryDate($plan, Carbon::parse($existingMembership->start_date ?: now()));
                }

                if ($update) {
                    $existingMembership->update($update);
                }

                $this->createCreditBalances($existingMembership, $plan, $user, $order);
                $this->clearCaches($user);

                return $existingMembership->fresh($this->membershipRelations());
            }

            /*
            |--------------------------------------------------------------------------
            | New purchase: expire older active memberships and create new active one
            |--------------------------------------------------------------------------
            */
            $this->expireExistingActiveMemberships($user);

            $startDate = now();
            $expiryDate = $this->calculateExpiryDate($plan, $startDate);

            $membership = UserMembership::query()->create(
                $this->membershipCreatePayload(
                    user: $user,
                    plan: $plan,
                    order: $order,
                    startDate: $startDate,
                    expiryDate: $expiryDate,
                    source: 'purchase',
                    createdBy: $order->created_by ?: $user->id,
                    metadata: [
                        'activated_from_order' => true,
                        'order_number' => $order->order_number,
                        'payment_status' => $order->payment_status,
                        'plan_snapshot' => $this->metadataValue($order, 'plan_snapshot'),
                    ]
                )
            );

            $this->createCreditBalances($membership, $plan, $user, $order);

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

            return $membership->fresh($this->membershipRelations());
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
            $taxCalculation = app(MembershipTaxService::class)->calculate($subtotal);

            $taxableAmount = round((float) ($taxCalculation['taxable_amount'] ?? $subtotal), 2);
            $gstPercentage = round((float) ($taxCalculation['gst_percentage'] ?? 0), 2);
            $gstAmount = round((float) ($taxCalculation['tax_amount'] ?? 0), 2);
            $totalAmount = round((float) ($taxCalculation['total_amount'] ?? $subtotal), 2);

            $orderPayload = [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'coupon_id' => null,
                'order_number' => $this->generateManualOrderNumber(),

                'gateway_name' => 'manual',
                'razorpay_order_id' => null,

                'currency' => $plan->currency ?: 'INR',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'taxable_amount' => $taxableAmount,
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
            ];

            $order = MembershipOrder::query()->create($orderPayload);

            $membership = UserMembership::query()->create(
                $this->membershipCreatePayload(
                    user: $user,
                    plan: $plan,
                    order: $order,
                    startDate: $startDate,
                    expiryDate: $expiryDate,
                    source: 'manual',
                    createdBy: $admin?->id,
                    metadata: [
                        'manual_reason' => $options['reason'] ?? null,
                        'manual_notes' => $options['notes'] ?? null,
                        'activated_by_admin' => $admin?->id,
                        'manual_order_id' => $order->id,
                        'manual_order_number' => $order->order_number,
                    ]
                )
            );

            $this->createCreditBalances($membership, $plan, $user, $order);

            $this->audit(
                action: 'membership_manual_activated',
                user: $user,
                auditable: $membership,
                performedBy: $admin,
                oldValues: null,
                newValues: $membership->fresh(['plan', 'order', 'creditBalances'])->toArray()
            );

            $this->clearCaches($user);

            return $membership->fresh($this->membershipRelations());
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
                return $membership->fresh($this->membershipRelations());
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

            return $membership->fresh($this->membershipRelations());
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
                return $membership->fresh($this->membershipRelations());
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

            return $membership->fresh($this->membershipRelations());
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
            'week', 'weeks' => $startDate->copy()->addWeeks($duration),
            'month', 'months' => $startDate->copy()->addMonths($duration),
            'year', 'years' => $startDate->copy()->addYears($duration),
            default => $startDate->copy()->addDays($duration),
        };
    }

    private function createCreditBalances(
        UserMembership $membership,
        MembershipPlan $plan,
        User $user,
        ?MembershipOrder $order = null
    ): void {
        $plan->loadMissing(['planFeatures.feature']);

        foreach ($plan->planFeatures as $planFeature) {
            $feature = $planFeature->feature;

            if (! $feature || ! $feature->status) {
                continue;
            }

            $featureType = strtolower((string) $feature->feature_type);

            /*
            |--------------------------------------------------------------------------
            | Credits should be created for limit-type features.
            | Old issue: if slug was not in hard-coded mapping, credits were skipped.
            | Now: mapped slug is preferred, otherwise feature slug becomes credit_type.
            |--------------------------------------------------------------------------
            */
            $mappedCreditType = $this->creditTypeFromFeatureSlug((string) $feature->slug);

            if ($featureType !== 'limit' && ! $mappedCreditType) {
                continue;
            }

            $creditType = $mappedCreditType ?: Str::slug((string) $feature->slug, '_');

            if (! $creditType) {
                continue;
            }

            $rawValue = $planFeature->feature_value;

            $isUnlimited = (bool) $planFeature->is_unlimited
                || strtolower((string) $rawValue) === 'unlimited';

            $totalCredits = $isUnlimited
                ? null
                : max(0, (int) $rawValue);

            /*
            |--------------------------------------------------------------------------
            | Keep unlimited credits visible also.
            | For limited credits, skip only if value is zero and not already assigned.
            |--------------------------------------------------------------------------
            */
            if (! $isUnlimited && $totalCredits <= 0) {
                continue;
            }

            $balance = MembershipCreditBalance::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'membership_id' => $membership->id,
                    'credit_type' => $creditType,
                ],
                [
                    'is_unlimited' => $isUnlimited,
                    'total_credits' => $totalCredits,
                    'used_credits' => 0,
                    'remaining_credits' => $isUnlimited ? null : $totalCredits,
                    'status' => true,
                    'expires_at' => $membership->expiry_date,
                ]
            );

            $referenceType = $order ? 'membership_order' : 'membership_activation';
            $referenceId = $order?->id ?: $membership->id;

            $alreadyLogged = MembershipCreditTransaction::query()
                ->where('user_id', $user->id)
                ->where('membership_id', $membership->id)
                ->where('balance_id', $balance->id)
                ->where('credit_type', $creditType)
                ->where('transaction_type', MembershipCreditTransaction::TYPE_CREDIT)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->exists();

            if (! $alreadyLogged) {
                MembershipCreditTransaction::query()->create([
                    'user_id' => $user->id,
                    'membership_id' => $membership->id,
                    'balance_id' => $balance->id,
                    'credit_type' => $creditType,
                    'transaction_type' => MembershipCreditTransaction::TYPE_CREDIT,
                    'quantity' => $totalCredits ?? 0,
                    'balance_before' => 0,
                    'balance_after' => $isUnlimited ? null : $totalCredits,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => 'Credits created from membership activation.',
                    'performed_by' => null,
                    'metadata' => [
                        'plan_id' => $plan->id,
                        'plan_slug' => $plan->slug,
                        'feature_id' => $feature->id,
                        'feature_slug' => $feature->slug,
                        'feature_type' => $featureType,
                        'raw_feature_value' => $rawValue,
                        'is_unlimited' => $isUnlimited,
                        'order_id' => $order?->id,
                        'order_number' => $order?->order_number,
                    ],
                ]);
            }
        }
    }

    private function membershipCreatePayload(
        User $user,
        MembershipPlan $plan,
        MembershipOrder $order,
        Carbon $startDate,
        Carbon $expiryDate,
        string $source,
        ?int $createdBy,
        array $metadata
    ): array {
        $payload = [
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
            'source' => $source,
        ];

        if (Schema::hasColumn('user_memberships', 'grace_until')) {
            $payload['grace_until'] = null;
        }

        if (Schema::hasColumn('user_memberships', 'created_by')) {
            $payload['created_by'] = $createdBy;
        }

        if (Schema::hasColumn('user_memberships', 'metadata')) {
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }

    private function isPaidOrder(MembershipOrder $order): bool
    {
        return in_array(strtolower((string) $order->payment_status), [
            strtolower((string) MembershipOrder::PAYMENT_PAID),
            'paid',
            'success',
            'successful',
            'completed',
            'captured',
        ], true);
    }

    private function markOrderCompletedIfNeeded(MembershipOrder $order): void
    {
        $updates = [];

        if (
            Schema::hasColumn('membership_orders', 'order_status')
            && ! in_array(strtolower((string) $order->order_status), [
                strtolower((string) MembershipOrder::STATUS_COMPLETED),
                'completed',
                'active',
            ], true)
        ) {
            $updates['order_status'] = MembershipOrder::STATUS_COMPLETED;
        }

        if (Schema::hasColumn('membership_orders', 'paid_at') && ! $order->paid_at) {
            $updates['paid_at'] = now();
        }

        if (Schema::hasColumn('membership_orders', 'completed_at') && ! $order->completed_at) {
            $updates['completed_at'] = now();
        }

        if ($updates) {
            $order->update($updates);
        }
    }

    private function metadataValue(MembershipOrder $order, string $key): mixed
    {
        $metadata = $order->metadata;

        if (is_array($metadata)) {
            return $metadata[$key] ?? null;
        }

        return null;
    }

    private function membershipRelations(): array
    {
        return [
            'user:id,first_name,last_name,email,phone,role_id',
            'creator:id,first_name,last_name,email,phone,role_id',
            'plan.category',
            'plan.planFeatures.feature',
            'order',
            'creditBalances',
        ];
    }

    private function creditTypeFromFeatureSlug(string $featureSlug): ?string
    {
        $featureSlug = Str::slug($featureSlug, '_');

        return match ($featureSlug) {
            'listing',
            'listing_limit',
            'listing_credits',
            'property_listing',
            'property_listings',
            'active_property_listings',
            'active_listing_limit',
            'active_listings' => MembershipCreditBalance::TYPE_LISTING,

            'featured_listing',
            'featured_listing_limit',
            'featured_listing_credits',
            'featured_property',
            'featured_property_limit' => MembershipCreditBalance::TYPE_FEATURED_LISTING,

            'boost',
            'boost_limit',
            'boost_credits',
            'listing_boost',
            'listing_boost_credits',
            'project_boost',
            'project_boost_credits' => MembershipCreditBalance::TYPE_BOOST,

            'lead_view',
            'lead_view_limit',
            'lead_view_credits',
            'buyer_contact',
            'buyer_contact_credits',
            'contact_unlock',
            'contact_unlock_credits' => MembershipCreditBalance::TYPE_LEAD_VIEW,

            'video_upload',
            'video_upload_limit',
            'video_upload_credits',
            'property_videos',
            'project_videos',
            'project_walkthrough_videos' => MembershipCreditBalance::TYPE_VIDEO_UPLOAD,

            'virtual_tour',
            'virtual_tour_limit',
            'virtual_tour_credits' => MembershipCreditBalance::TYPE_VIRTUAL_TOUR,

            'ai_description',
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

            Cache::store('redis')->forget("membership:user:{$user->id}:status");
            Cache::store('redis')->forget("membership:user:{$user->id}:credits");
            Cache::store('redis')->forget("membership:user:{$user->id}:access");
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