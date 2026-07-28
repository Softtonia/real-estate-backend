<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipCreditBalance;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MembershipAccessService
{
    private const CACHE_TTL_SECONDS = 600;

    public function userMembershipStatus(User $user): array
    {
        return Cache::store('redis')->remember(
            $this->userStatusCacheKey((int) $user->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildUserMembershipStatus($user)
        );
    }

    public function activeMembership(User $user): ?UserMembership
    {
        return UserMembership::query()
            ->with([
                'plan:id,category_id,name,slug,currency,price,sale_price,duration,duration_type,is_popular,status',
                'plan.category:id,name,slug',
                'plan.planFeatures.feature:id,name,slug,feature_type,status,sort_order',
                'creditBalances',
            ])
            ->where('user_id', $user->id)
            ->active()
            ->latest('expiry_date')
            ->latest('id')
            ->first();
    }

    public function eligiblePlans(User $user): Collection
    {
        $role = $this->resolveUserRole($user);
        $roleId = $role?->id;

        $cacheKey = $roleId
            ? $this->rolePlansCacheKey((int) $roleId)
            : 'membership:plans:eligible:no-role';

        return Cache::store('redis')->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            function () use ($roleId) {
                return MembershipPlan::query()
                    ->select([
                        'id',
                        'category_id',
                        'name',
                        'slug',
                        'short_description',
                        'currency',
                        'price',
                        'sale_price',
                        'duration',
                        'duration_type',
                        'trial_days',
                        'is_popular',
                        'status',
                        'sort_order',
                    ])
                    ->with([
                        'category:id,name,slug',
                        'planFeatures' => function ($query) {
                            $query->select([
                                'id',
                                'plan_id',
                                'feature_id',
                                'feature_value',
                                'is_unlimited',
                            ])->with([
                                'feature:id,name,slug,feature_type,status,sort_order',
                            ]);
                        },
                        'roleRules:id,plan_id,role_id,is_active',
                    ])
                    ->active()
                    ->where(function ($query) use ($roleId) {
                        $query->where('slug', 'free');

                        if ($roleId) {
                            $query->orWhereHas('roleRules', function ($ruleQuery) use ($roleId) {
                                $ruleQuery->where('role_id', $roleId)
                                    ->where('is_active', true);
                            });
                        }
                    })
                    ->ordered()
                    ->get();
            }
        );
    }

    public function hasActiveMembership(User $user): bool
    {
        return (bool) ($this->userMembershipStatus($user)['has_active_membership'] ?? false);
    }

    public function hasFeature(User $user, string $featureSlug): bool
    {
        $status = $this->userMembershipStatus($user);
        $feature = $status['features'][$featureSlug] ?? null;

        if (!$feature) {
            return false;
        }

        if (($feature['is_unlimited'] ?? false) === true) {
            return true;
        }

        $value = $feature['value'] ?? null;

        return $this->truthyFeatureValue($value);
    }

    public function featureValue(User $user, string $featureSlug, mixed $default = null): mixed
    {
        $status = $this->userMembershipStatus($user);

        return $status['features'][$featureSlug]['value'] ?? $default;
    }

    public function hasCredit(User $user, string $creditType, int $quantity = 1): bool
    {
        $status = $this->userMembershipStatus($user);
        $credit = $status['credits'][$creditType] ?? null;

        if (!$credit) {
            return false;
        }

        if (($credit['is_unlimited'] ?? false) === true) {
            return true;
        }

        return (int) ($credit['remaining_credits'] ?? 0) >= $quantity;
    }

    public function creditBalance(User $user, string $creditType): ?array
    {
        $status = $this->userMembershipStatus($user);

        return $status['credits'][$creditType] ?? null;
    }

    public function canPublishListing(User $user): array
    {
        $status = $this->userMembershipStatus($user);

        if (!$status['has_active_membership']) {
            return [
                'allowed' => false,
                'message' => 'Active membership is required to publish listing.',
                'status' => $status,
            ];
        }

        if ($this->hasCredit($user, MembershipCreditBalance::TYPE_LISTING)) {
            return [
                'allowed' => true,
                'message' => 'Listing credit available.',
                'status' => $status,
            ];
        }

        return [
            'allowed' => false,
            'message' => 'Listing credit is not available.',
            'status' => $status,
        ];
    }

    public function canUseFeature(User $user, string $featureSlug): array
    {
        $status = $this->userMembershipStatus($user);

        if (!$status['has_active_membership']) {
            return [
                'allowed' => false,
                'message' => 'Active membership is required.',
                'status' => $status,
            ];
        }

        if (!$this->hasFeature($user, $featureSlug)) {
            return [
                'allowed' => false,
                'message' => 'This feature is not available in your membership plan.',
                'status' => $status,
            ];
        }

        return [
            'allowed' => true,
            'message' => 'Feature access allowed.',
            'status' => $status,
        ];
    }

    public function canUseCredit(User $user, string $creditType, int $quantity = 1): array
    {
        $status = $this->userMembershipStatus($user);

        if (!$status['has_active_membership']) {
            return [
                'allowed' => false,
                'message' => 'Active membership is required.',
                'status' => $status,
            ];
        }

        if (!$this->hasCredit($user, $creditType, $quantity)) {
            return [
                'allowed' => false,
                'message' => 'Insufficient membership credits.',
                'status' => $status,
            ];
        }

        return [
            'allowed' => true,
            'message' => 'Credit available.',
            'status' => $status,
        ];
    }

    public function forgetUserCache(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        Cache::store('redis')->forget($this->userStatusCacheKey($userId));
        Cache::store('redis')->forget($this->userActiveCacheKey($userId));
        Cache::store('redis')->forget($this->userCreditsCacheKey($userId));
    }

    public function forgetRolePlansCache(Role|int|null $role): void
    {
        if (!$role) {
            Cache::store('redis')->forget('membership:plans:eligible:no-role');
            return;
        }

        $roleId = $role instanceof Role ? (int) $role->id : (int) $role;

        Cache::store('redis')->forget($this->rolePlansCacheKey($roleId));
    }

    public function forgetGlobalPlanCaches(): void
    {
        Cache::store('redis')->forget('membership:plans:active');
        Cache::store('redis')->forget('membership:settings');
        Cache::store('redis')->forget('membership:admin:stats');
    }

    private function buildUserMembershipStatus(User $user): array
    {
        $role = $this->resolveUserRole($user);
        $membership = $this->activeMembership($user);

        return [
            'user' => [
                'id' => (int) $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email ?? null,
                'phone' => $user->phone ?? null,
                'role_id' => $user->role_id ?? null,
                'resolved_role_id' => $role?->id,
                'role_name' => $this->roleName($role),
            ],

            'has_active_membership' => (bool) $membership,
            'membership' => $membership ? $this->membershipPayload($membership) : null,
            'plan' => $membership?->plan ? $this->planPayload($membership->plan) : null,
            'features' => $membership ? $this->featuresPayload($membership) : [],
            'credits' => $membership ? $this->creditsPayload($membership) : [],

            'eligible_plans_count' => $this->eligiblePlans($user)->count(),
            'message' => $membership
                ? 'Active membership found.'
                : 'No active membership found.',
        ];
    }

    private function membershipPayload(UserMembership $membership): array
    {
        return [
            'id' => (int) $membership->id,
            'user_id' => (int) $membership->user_id,
            'plan_id' => (int) $membership->plan_id,
            'order_id' => $membership->order_id ? (int) $membership->order_id : null,
            'status' => $membership->status,
            'source' => $membership->source,
            'start_date' => optional($membership->start_date)->toDateTimeString(),
            'expiry_date' => optional($membership->expiry_date)->toDateTimeString(),
            'auto_renew' => (bool) $membership->auto_renew,
            'is_active' => $membership->isActive(),
            'days_remaining' => $membership->expiry_date
                ? max(0, now()->diffInDays($membership->expiry_date, false))
                : null,
        ];
    }

    private function planPayload(MembershipPlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'category_id' => (int) $plan->category_id,
            'category' => $plan->category ? [
                'id' => (int) $plan->category->id,
                'name' => $plan->category->name,
                'slug' => $plan->category->slug,
            ] : null,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'currency' => $plan->currency,
            'price' => (float) $plan->price,
            'sale_price' => $plan->sale_price !== null ? (float) $plan->sale_price : null,
            'payable_amount' => $plan->payableAmount(),
            'duration' => (int) $plan->duration,
            'duration_type' => $plan->duration_type,
            'is_popular' => (bool) $plan->is_popular,
        ];
    }

    private function featuresPayload(UserMembership $membership): array
    {
        $features = [];

        foreach ($membership->plan?->planFeatures ?? [] as $planFeature) {
            $feature = $planFeature->feature;

            if (!$feature || !$feature->status) {
                continue;
            }

            $features[$feature->slug] = [
                'id' => (int) $feature->id,
                'name' => $feature->name,
                'slug' => $feature->slug,
                'type' => $feature->feature_type,
                'value' => $this->castFeatureValue($feature->feature_type, $planFeature->feature_value),
                'raw_value' => $planFeature->feature_value,
                'is_unlimited' => (bool) $planFeature->is_unlimited,
            ];
        }

        return $features;
    }

    private function creditsPayload(UserMembership $membership): array
    {
        $credits = [];

        foreach ($membership->creditBalances ?? [] as $balance) {
            if (!$balance->status) {
                continue;
            }

            if ($balance->expires_at && $balance->expires_at->isPast()) {
                continue;
            }

            $credits[$balance->credit_type] = [
                'id' => (int) $balance->id,
                'credit_type' => $balance->credit_type,
                'is_unlimited' => (bool) $balance->is_unlimited,
                'total_credits' => $balance->total_credits !== null ? (int) $balance->total_credits : null,
                'used_credits' => (int) $balance->used_credits,
                'remaining_credits' => $balance->remaining_credits !== null ? (int) $balance->remaining_credits : null,
                'expires_at' => optional($balance->expires_at)->toDateTimeString(),
            ];
        }

        return $credits;
    }

    private function resolveUserRole(User $user): ?Role
    {
        if ($user->relationLoaded('role') && $user->role instanceof Role) {
            return $user->role;
        }

        if (!Schema::hasTable('roles')) {
            return null;
        }

        $roleId = $user->role_id ?? null;

        if (!$roleId || !is_numeric($roleId)) {
            return null;
        }

        return Role::query()->find((int) $roleId);
    }

    private function roleName(?Role $role): ?string
    {
        if (!$role) {
            return null;
        }

        foreach (['name', 'role_name', 'title'] as $column) {
            if (Schema::hasColumn('roles', $column) && isset($role->{$column})) {
                return (string) $role->{$column};
            }
        }

        return null;
    }

    private function castFeatureValue(string $featureType, string $value): mixed
    {
        if ($value === 'unlimited') {
            return 'unlimited';
        }

        return match ($featureType) {
            'boolean' => $this->truthyFeatureValue($value),
            'number', 'limit' => is_numeric($value) ? (int) $value : $value,
            default => $value,
        };
    }

    private function truthyFeatureValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = Str::of((string) $value)->lower()->trim()->toString();

        return in_array($normalized, [
            '1',
            'true',
            'yes',
            'enabled',
            'active',
            'available',
            'unlimited',
        ], true);
    }

    private function userStatusCacheKey(int $userId): string
    {
        return "membership:user:{$userId}:status";
    }

    private function userActiveCacheKey(int $userId): string
    {
        return "membership:user:{$userId}:active";
    }

    private function userCreditsCacheKey(int $userId): string
    {
        return "membership:user:{$userId}:credits";
    }

    private function rolePlansCacheKey(int $roleId): string
    {
        return "membership:role:{$roleId}:plans";
    }
}