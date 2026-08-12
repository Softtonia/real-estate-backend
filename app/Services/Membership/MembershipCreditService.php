<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipCreditBalance;
use App\Models\Membership\MembershipCreditTransaction;
use App\Models\Membership\MembershipLeadUnlock;
use App\Models\Membership\UserMembership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MembershipCreditService
{

    public function __construct(
        private readonly MembershipAccessService $accessService
    ) {}


    public function activeBalance(User $user, string $creditType): ?MembershipCreditBalance
    {
        $membership = $this->activeMembership($user);

        if (!$membership) {
            return null;
        }

        return MembershipCreditBalance::query()
            ->where('user_id', $user->id)
            ->where('membership_id', $membership->id)
            ->where('credit_type', $creditType)
            ->active()
            ->first();
    }

    public function hasCredits(User $user, string $creditType, int $quantity = 1): bool
    {
        $balance = $this->activeBalance($user, $creditType);

        if (!$balance) {
            return false;
        }

        return $balance->hasAvailableCredits($quantity);
    }

    public function consumeCredit(
        User $user,
        string $creditType,
        int $quantity = 1,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?User $performedBy = null,
        array $metadata = []
    ): MembershipCreditTransaction {
        $this->validateQuantity($quantity);

        $lockKey = $this->lockKey($user, $creditType);

        return Cache::store('redis')->lock($lockKey, 20)->block(10, function () use (
            $user,
            $creditType,
            $quantity,
            $referenceType,
            $referenceId,
            $reason,
            $performedBy,
            $metadata
        ) {
            return DB::transaction(function () use (
                $user,
                $creditType,
                $quantity,
                $referenceType,
                $referenceId,
                $reason,
                $performedBy,
                $metadata
            ) {
                $membership = $this->activeMembershipForUpdate($user);

                if (!$membership) {
                    throw ValidationException::withMessages([
                        'membership' => ['Active membership is required.'],
                    ]);
                }

                $balance = $this->balanceForUpdate($membership, $creditType);

                if (!$balance) {
                    throw ValidationException::withMessages([
                        'credit_type' => ["Credit balance not found for {$creditType}."],
                    ]);
                }

                if (!$balance->is_unlimited && (int) $balance->remaining_credits < $quantity) {
                    throw ValidationException::withMessages([
                        'credits' => ['Insufficient membership credits.'],
                    ]);
                }

                $balanceBefore = $balance->is_unlimited
                    ? null
                    : (int) $balance->remaining_credits;

                if ($balance->is_unlimited) {
                    $balance->update([
                        'used_credits' => (int) $balance->used_credits + $quantity,
                    ]);

                    $balanceAfter = null;
                } else {
                    $balanceAfter = max(0, (int) $balance->remaining_credits - $quantity);

                    $balance->update([
                        'used_credits' => (int) $balance->used_credits + $quantity,
                        'remaining_credits' => $balanceAfter,
                    ]);
                }

                $transaction = MembershipCreditTransaction::query()->create([
                    'user_id' => $user->id,
                    'membership_id' => $membership->id,
                    'balance_id' => $balance->id,
                    'credit_type' => $creditType,
                    'transaction_type' => MembershipCreditTransaction::TYPE_DEBIT,
                    'quantity' => $quantity,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => $reason ?: 'Membership credit consumed.',
                    'performed_by' => $performedBy?->id,
                    'metadata' => $metadata,
                ]);

                $this->clearCaches($user);

                return $transaction->fresh(['balance', 'membership.plan']);
            });
        });
    }

    public function addCredits(
        User $user,
        string $creditType,
        int $quantity,
        ?UserMembership $membership = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?User $performedBy = null,
        array $metadata = []
    ): MembershipCreditTransaction {
        $this->validateQuantity($quantity);

        $lockKey = $this->lockKey($user, $creditType);

        return Cache::store('redis')->lock($lockKey, 20)->block(10, function () use (
            $user,
            $creditType,
            $quantity,
            $membership,
            $referenceType,
            $referenceId,
            $reason,
            $performedBy,
            $metadata
        ) {
            return DB::transaction(function () use (
                $user,
                $creditType,
                $quantity,
                $membership,
                $referenceType,
                $referenceId,
                $reason,
                $performedBy,
                $metadata
            ) {
                $membership = $membership
                    ? UserMembership::query()->where('id', $membership->id)->lockForUpdate()->first()
                    : $this->activeMembershipForUpdate($user);

                if (!$membership || !$membership->isActive()) {
                    throw ValidationException::withMessages([
                        'membership' => ['Active membership is required to add credits.'],
                    ]);
                }

                $balance = MembershipCreditBalance::query()
                    ->where('user_id', $user->id)
                    ->where('membership_id', $membership->id)
                    ->where('credit_type', $creditType)
                    ->lockForUpdate()
                    ->first();

                if (!$balance) {
                    $balance = MembershipCreditBalance::query()->create([
                        'user_id' => $user->id,
                        'membership_id' => $membership->id,
                        'credit_type' => $creditType,
                        'is_unlimited' => false,
                        'total_credits' => 0,
                        'used_credits' => 0,
                        'remaining_credits' => 0,
                        'status' => true,
                        'expires_at' => $membership->expiry_date,
                    ]);
                }

                if ($balance->is_unlimited) {
                    $balanceBefore = null;
                    $balanceAfter = null;
                } else {
                    $balanceBefore = (int) $balance->remaining_credits;
                    $balanceAfter = $balanceBefore + $quantity;

                    $balance->update([
                        'total_credits' => (int) $balance->total_credits + $quantity,
                        'remaining_credits' => $balanceAfter,
                        'status' => true,
                    ]);
                }

                $transaction = MembershipCreditTransaction::query()->create([
                    'user_id' => $user->id,
                    'membership_id' => $membership->id,
                    'balance_id' => $balance->id,
                    'credit_type' => $creditType,
                    'transaction_type' => MembershipCreditTransaction::TYPE_CREDIT,
                    'quantity' => $quantity,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => $reason ?: 'Membership credits added.',
                    'performed_by' => $performedBy?->id,
                    'metadata' => $metadata,
                ]);

                $this->clearCaches($user);

                return $transaction->fresh(['balance', 'membership.plan']);
            });
        });
    }

    public function refundCredit(
        User $user,
        string $creditType,
        int $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?User $performedBy = null,
        array $metadata = []
    ): MembershipCreditTransaction {
        $transaction = $this->addCredits(
            user: $user,
            creditType: $creditType,
            quantity: $quantity,
            membership: null,
            referenceType: $referenceType,
            referenceId: $referenceId,
            reason: $reason ?: 'Membership credit refunded.',
            performedBy: $performedBy,
            metadata: $metadata
        );

        $transaction->update([
            'transaction_type' => MembershipCreditTransaction::TYPE_REFUND,
        ]);

        return $transaction->fresh(['balance', 'membership.plan']);
    }

    public function adjustCredits(
        User $user,
        string $creditType,
        string $transactionType = 'credit',
        ?int $quantity = null,
        ?int $newRemainingCredits = null,
        ?string $reason = null,
        ?User $performedBy = null,
        array $metadata = []
    ): MembershipCreditTransaction {
        $transactionType = strtolower(trim($transactionType));

        if (! in_array($transactionType, ['credit', 'debit', 'adjust', 'refund', 'expire'], true)) {
            throw ValidationException::withMessages([
                'transaction_type' => ['Invalid transaction type.'],
            ]);
        }

        $lockKey = $this->lockKey($user, $creditType);

        return Cache::store('redis')->lock($lockKey, 20)->block(10, function () use (
            $user,
            $creditType,
            $transactionType,
            $quantity,
            $newRemainingCredits,
            $reason,
            $performedBy,
            $metadata
        ) {
            return DB::transaction(function () use (
                $user,
                $creditType,
                $transactionType,
                $quantity,
                $newRemainingCredits,
                $reason,
                $performedBy,
                $metadata
            ) {
                $membership = $this->activeMembershipForUpdate($user);

                if (! $membership) {
                    throw ValidationException::withMessages([
                        'membership' => ['Active membership is required.'],
                    ]);
                }

                $balance = MembershipCreditBalance::query()
                    ->where('user_id', $user->id)
                    ->where('membership_id', $membership->id)
                    ->where('credit_type', $creditType)
                    ->lockForUpdate()
                    ->first();

                if (! $balance) {
                    if (in_array($transactionType, ['debit', 'expire'], true)) {
                        throw ValidationException::withMessages([
                            'credit_type' => ["Credit balance not found for {$creditType}."],
                        ]);
                    }

                    $balance = MembershipCreditBalance::query()->create([
                        'user_id' => $user->id,
                        'membership_id' => $membership->id,
                        'credit_type' => $creditType,
                        'is_unlimited' => false,
                        'total_credits' => 0,
                        'used_credits' => 0,
                        'remaining_credits' => 0,
                        'status' => true,
                        'expires_at' => $membership->expiry_date,
                    ]);
                }

                if ($balance->is_unlimited) {
                    throw ValidationException::withMessages([
                        'credit_type' => ['Unlimited credit balance does not need manual adjustment.'],
                    ]);
                }

                $beforeRemaining = (int) ($balance->remaining_credits ?? 0);
                $beforeUsed = (int) ($balance->used_credits ?? 0);
                $beforeTotal = (int) ($balance->total_credits ?? 0);

                $quantity = $quantity !== null ? (int) $quantity : 0;

                $afterRemaining = $beforeRemaining;
                $afterUsed = $beforeUsed;
                $afterTotal = $beforeTotal;

                if ($transactionType === 'credit') {
                    if ($quantity <= 0) {
                        throw ValidationException::withMessages([
                            'quantity' => ['Quantity must be greater than zero.'],
                        ]);
                    }

                    $afterRemaining = $beforeRemaining + $quantity;
                    $afterTotal = $beforeTotal + $quantity;
                }

                if ($transactionType === 'debit') {
                    if ($quantity <= 0) {
                        throw ValidationException::withMessages([
                            'quantity' => ['Quantity must be greater than zero.'],
                        ]);
                    }

                    if ($beforeRemaining < $quantity) {
                        throw ValidationException::withMessages([
                            'quantity' => ['Insufficient remaining credits.'],
                        ]);
                    }

                    $afterRemaining = $beforeRemaining - $quantity;
                    $afterUsed = $beforeUsed + $quantity;
                }

                if ($transactionType === 'refund') {
                    if ($quantity <= 0) {
                        throw ValidationException::withMessages([
                            'quantity' => ['Quantity must be greater than zero.'],
                        ]);
                    }

                    $afterRemaining = $beforeRemaining + $quantity;
                    $afterUsed = max(0, $beforeUsed - $quantity);
                    $afterTotal = max($beforeTotal, $afterRemaining + $afterUsed);
                }

                if ($transactionType === 'expire') {
                    if ($quantity <= 0) {
                        throw ValidationException::withMessages([
                            'quantity' => ['Quantity must be greater than zero.'],
                        ]);
                    }

                    if ($beforeRemaining < $quantity) {
                        throw ValidationException::withMessages([
                            'quantity' => ['Expire quantity cannot be greater than remaining credits.'],
                        ]);
                    }

                    $afterRemaining = $beforeRemaining - $quantity;
                }

                if ($transactionType === 'adjust') {
                    if ($newRemainingCredits === null || $newRemainingCredits < 0) {
                        throw ValidationException::withMessages([
                            'remaining_credits' => ['Remaining credits cannot be negative.'],
                        ]);
                    }

                    $afterRemaining = $newRemainingCredits;
                    $quantity = abs($afterRemaining - $beforeRemaining);
                    $afterTotal = max($beforeTotal, $beforeUsed + $afterRemaining);
                }

                $balance->update([
                    'total_credits' => $afterTotal,
                    'used_credits' => $afterUsed,
                    'remaining_credits' => $afterRemaining,
                    'status' => true,
                    'expires_at' => $membership->expiry_date,
                ]);

                $transaction = MembershipCreditTransaction::query()->create([
                    'user_id' => $user->id,
                    'membership_id' => $membership->id,
                    'balance_id' => $balance->id,
                    'credit_type' => $creditType,
                    'transaction_type' => $transactionType,
                    'quantity' => $quantity,
                    'balance_before' => $beforeRemaining,
                    'balance_after' => $afterRemaining,
                    'reference_type' => 'admin_adjustment',
                    'reference_id' => $performedBy?->id,
                    'reason' => $reason ?: 'Membership credit adjusted.',
                    'performed_by' => $performedBy?->id,
                    'metadata' => array_merge($metadata, [
                        'admin_transaction_type' => $transactionType,
                        'before_total_credits' => $beforeTotal,
                        'after_total_credits' => $afterTotal,
                        'before_used_credits' => $beforeUsed,
                        'after_used_credits' => $afterUsed,
                    ]),
                ]);

                $this->clearCaches($user);

                return $transaction->fresh(['balance', 'membership.plan']);
            });
        });
    }

    public function unlockLeadOnce(
        User $user,
        string $leadReferenceType,
        int $leadReferenceId,
        ?User $performedBy = null,
        array $metadata = []
    ): array {
        $lockKey = "membership:lead-unlock:user:{$user->id}:{$leadReferenceType}:{$leadReferenceId}";

        return Cache::store('redis')->lock($lockKey, 20)->block(10, function () use (
            $user,
            $leadReferenceType,
            $leadReferenceId,
            $performedBy,
            $metadata
        ) {
            $existingUnlock = MembershipLeadUnlock::query()
                ->where('user_id', $user->id)
                ->where('lead_reference_type', $leadReferenceType)
                ->where('lead_reference_id', $leadReferenceId)
                ->first();

            if ($existingUnlock) {
                return [
                    'already_unlocked' => true,
                    'deducted' => false,
                    'unlock' => $existingUnlock,
                    'transaction' => null,
                    'message' => 'Lead already unlocked. No credit deducted.',
                ];
            }

            $transaction = $this->consumeCredit(
                user: $user,
                creditType: MembershipCreditBalance::TYPE_LEAD_VIEW,
                quantity: 1,
                referenceType: $leadReferenceType,
                referenceId: $leadReferenceId,
                reason: 'Lead unlocked using membership credit.',
                performedBy: $performedBy,
                metadata: $metadata
            );

            $unlock = MembershipLeadUnlock::query()->create([
                'user_id' => $user->id,
                'membership_id' => $transaction->membership_id,
                'lead_reference_type' => $leadReferenceType,
                'lead_reference_id' => $leadReferenceId,
                'unlocked_at' => now(),
                'metadata' => $metadata,
            ]);

            $this->clearCaches($user);

            return [
                'already_unlocked' => false,
                'deducted' => true,
                'unlock' => $unlock,
                'transaction' => $transaction,
                'message' => 'Lead unlocked successfully.',
            ];
        });
    }

    public function userCreditSummary(User $user): array
    {
        $membership = $this->activeMembership($user);

        if (!$membership) {
            return [
                'has_active_membership' => false,
                'membership' => null,
                'credits' => [],
            ];
        }

        $balances = MembershipCreditBalance::query()
            ->where('user_id', $user->id)
            ->where('membership_id', $membership->id)
            ->active()
            ->orderBy('credit_type')
            ->get();

        return [
            'has_active_membership' => true,
            'membership' => [
                'id' => (int) $membership->id,
                'plan_id' => (int) $membership->plan_id,
                'plan_name' => $membership->plan?->name,
                'status' => $membership->status,
                'start_date' => optional($membership->start_date)->toDateTimeString(),
                'expiry_date' => optional($membership->expiry_date)->toDateTimeString(),
            ],
            'credits' => $balances->mapWithKeys(function (MembershipCreditBalance $balance) {
                return [
                    $balance->credit_type => [
                        'id' => (int) $balance->id,
                        'credit_type' => $balance->credit_type,
                        'is_unlimited' => (bool) $balance->is_unlimited,
                        'total_credits' => $balance->total_credits !== null ? (int) $balance->total_credits : null,
                        'used_credits' => (int) $balance->used_credits,
                        'remaining_credits' => $balance->remaining_credits !== null ? (int) $balance->remaining_credits : null,
                        'expires_at' => optional($balance->expires_at)->toDateTimeString(),
                    ],
                ];
            })->toArray(),
        ];
    }

    private function activeMembership(User $user): ?UserMembership
    {
        return UserMembership::query()
            ->with(['plan'])
            ->where('user_id', $user->id)
            ->active()
            ->latest('expiry_date')
            ->latest('id')
            ->first();
    }

    private function activeMembershipForUpdate(User $user): ?UserMembership
    {
        return UserMembership::query()
            ->where('user_id', $user->id)
            ->where('status', UserMembership::STATUS_ACTIVE)
            ->where(function ($query) {
                $query->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>', now());
            })
            ->lockForUpdate()
            ->latest('expiry_date')
            ->latest('id')
            ->first();
    }

    private function balanceForUpdate(
        UserMembership $membership,
        string $creditType
    ): ?MembershipCreditBalance {
        return MembershipCreditBalance::query()
            ->where('user_id', $membership->user_id)
            ->where('membership_id', $membership->id)
            ->where('credit_type', $creditType)
            ->where('status', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->lockForUpdate()
            ->first();
    }

    private function validateQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be greater than zero.'],
            ]);
        }
    }

    private function lockKey(User $user, string $creditType): string
    {
        return "membership:credit:user:{$user->id}:{$creditType}";
    }

    private function clearCaches(User $user): void
    {
        $this->accessService->forgetUserCache($user);

        Cache::store('redis')->forget('membership:admin:stats');
    }
    public function consumeListingCreditOnce(
        User $user,
        string $referenceType,
        int $referenceId,
        ?User $performedBy = null,
        array $metadata = []
    ): MembershipCreditTransaction {
        if ($referenceId <= 0) {
            throw ValidationException::withMessages([
                'reference_id' => ['Valid listing reference id is required.'],
            ]);
        }

        $lockKey = "membership:listing-credit-once:user:{$user->id}:{$referenceType}:{$referenceId}";

        return Cache::store('redis')->lock($lockKey, 30)->block(10, function () use (
            $user,
            $referenceType,
            $referenceId,
            $performedBy,
            $metadata
        ) {
            $existing = MembershipCreditTransaction::query()
                ->where('user_id', $user->id)
                ->where('credit_type', MembershipCreditBalance::TYPE_LISTING)
                ->where('transaction_type', MembershipCreditTransaction::TYPE_DEBIT)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            if ($existing) {
                return $existing->loadMissing(['balance', 'membership.plan']);
            }

            return $this->consumeCredit(
                user: $user,
                creditType: MembershipCreditBalance::TYPE_LISTING,
                quantity: 1,
                referenceType: $referenceType,
                referenceId: $referenceId,
                reason: 'Listing published using membership credit.',
                performedBy: $performedBy,
                metadata: array_merge($metadata, [
                    'deduct_once' => true,
                ])
            );
        });
    }
    public function consumeFeatureCreditOnce(
        User $user,
        string $creditType,
        string $referenceType,
        int $referenceId,
        int $quantity = 1,
        ?User $performedBy = null,
        ?string $reason = null,
        array $metadata = []
    ): MembershipCreditTransaction {
        if ($referenceId <= 0) {
            throw ValidationException::withMessages([
                'reference_id' => ['Valid reference id is required.'],
            ]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be greater than zero.'],
            ]);
        }

        $lockKey = "membership:feature-credit-once:user:{$user->id}:{$creditType}:{$referenceType}:{$referenceId}";

        return Cache::store('redis')->lock($lockKey, 30)->block(10, function () use (
            $user,
            $creditType,
            $referenceType,
            $referenceId,
            $quantity,
            $performedBy,
            $reason,
            $metadata
        ) {
            $existing = MembershipCreditTransaction::query()
                ->where('user_id', $user->id)
                ->where('credit_type', $creditType)
                ->where('transaction_type', MembershipCreditTransaction::TYPE_DEBIT)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            if ($existing) {
                return $existing->loadMissing(['balance', 'membership.plan']);
            }

            return $this->consumeCredit(
                user: $user,
                creditType: $creditType,
                quantity: $quantity,
                referenceType: $referenceType,
                referenceId: $referenceId,
                reason: $reason ?: 'Membership feature credit consumed.',
                performedBy: $performedBy,
                metadata: array_merge($metadata, [
                    'deduct_once' => true,
                ])
            );
        });
    }
}
