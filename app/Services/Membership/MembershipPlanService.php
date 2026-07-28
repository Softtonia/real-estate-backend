<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipCategory;
use App\Models\Membership\MembershipFeature;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanFeature;
use App\Models\Membership\MembershipPlanRoleRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipPlanService
{
    private const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly MembershipAccessService $accessService
    ) {}

    public function activePlans(?User $user = null): Collection
    {
        if ($user) {
            return $this->accessService->eligiblePlans($user);
        }

        return Cache::store('redis')->remember(
            'membership:plans:active',
            self::CACHE_TTL_SECONDS,
            fn () => MembershipPlan::query()
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
                    'metadata',
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
                            'metadata',
                        ])->with([
                            'feature:id,name,slug,feature_type,status,sort_order',
                        ]);
                    },
                    'roleRules:id,plan_id,role_id,is_active',
                ])
                ->active()
                ->ordered()
                ->get()
        );
    }

    public function planDetail(MembershipPlan $plan, ?User $user = null): MembershipPlan
    {
        $plan->loadMissing([
            'category:id,name,slug,description',
            'planFeatures.feature:id,name,slug,description,feature_type,status,sort_order',
            'roleRules.role',
        ]);

        if ($user && !$this->isPlanAllowedForUser($plan, $user)) {
            throw ValidationException::withMessages([
                'plan_id' => ['This membership plan is not available for your role.'],
            ]);
        }

        return $plan;
    }

    public function adminPaginatedPlans(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

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
                'created_at',
                'updated_at',
            ])
            ->with([
                'category:id,name,slug',
            ])
            ->withCount([
                'planFeatures',
                'roleRules',
                'orders',
                'memberships',
            ])
            ->when(isset($filters['category_id']), function ($query) use ($filters) {
                $query->where('category_id', (int) $filters['category_id']);
            })
            ->when(isset($filters['status']), function ($query) use ($filters) {
                $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(isset($filters['is_popular']), function ($query) use ($filters) {
                $query->where('is_popular', filter_var($filters['is_popular'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%");
                });
            })
            ->ordered()
            ->paginate($perPage);
    }

    public function createPlan(array $data, ?User $admin = null): MembershipPlan
    {
        return DB::transaction(function () use ($data, $admin) {
            $category = MembershipCategory::query()->findOrFail((int) $data['category_id']);

            $slug = $this->prepareSlug(
                value: $data['slug'] ?? $data['name'],
                modelClass: MembershipPlan::class
            );

            $plan = MembershipPlan::query()->create([
                'category_id' => $category->id,
                'name' => trim((string) $data['name']),
                'slug' => $slug,
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'currency' => $data['currency'] ?? 'INR',
                'price' => $this->money($data['price'] ?? 0),
                'sale_price' => array_key_exists('sale_price', $data) && $data['sale_price'] !== null
                    ? $this->money($data['sale_price'])
                    : null,
                'duration' => (int) ($data['duration'] ?? 1),
                'duration_type' => $data['duration_type'] ?? MembershipPlan::DURATION_MONTHS,
                'trial_days' => (int) ($data['trial_days'] ?? 0),
                'is_popular' => (bool) ($data['is_popular'] ?? false),
                'status' => (bool) ($data['status'] ?? true),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'metadata' => $data['metadata'] ?? [],
            ]);

            if (!empty($data['features'])) {
                $this->syncFeatures($plan, $data['features'], $admin, false);
            }

            if (!empty($data['role_ids'])) {
                $this->syncRoleRules($plan, $data['role_ids'], $admin, false);
            }

            $this->audit(
                action: 'plan_created',
                auditable: $plan,
                performedBy: $admin,
                oldValues: null,
                newValues: $plan->fresh()->toArray()
            );

            $this->clearPlanCaches($plan);

            return $plan->fresh([
                'category',
                'planFeatures.feature',
                'roleRules.role',
            ]);
        });
    }

    public function updatePlan(MembershipPlan $plan, array $data, ?User $admin = null): MembershipPlan
    {
        return DB::transaction(function () use ($plan, $data, $admin) {
            $oldValues = $plan->loadMissing(['planFeatures', 'roleRules'])->toArray();

            if (isset($data['category_id'])) {
                MembershipCategory::query()->findOrFail((int) $data['category_id']);
            }

            $payload = [];

            foreach ([
                'category_id',
                'name',
                'short_description',
                'description',
                'currency',
                'duration_type',
                'metadata',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }

            if (array_key_exists('slug', $data)) {
                $payload['slug'] = $this->prepareSlug(
                    value: $data['slug'],
                    modelClass: MembershipPlan::class,
                    ignoreId: (int) $plan->id
                );
            }

            foreach (['price', 'sale_price'] as $moneyField) {
                if (array_key_exists($moneyField, $data)) {
                    $payload[$moneyField] = $data[$moneyField] !== null
                        ? $this->money($data[$moneyField])
                        : null;
                }
            }

            foreach (['duration', 'trial_days', 'sort_order'] as $intField) {
                if (array_key_exists($intField, $data)) {
                    $payload[$intField] = (int) $data[$intField];
                }
            }

            foreach (['is_popular', 'status'] as $boolField) {
                if (array_key_exists($boolField, $data)) {
                    $payload[$boolField] = (bool) $data[$boolField];
                }
            }

            if (!empty($payload)) {
                $plan->update($payload);
            }

            if (array_key_exists('features', $data)) {
                $this->syncFeatures($plan, $data['features'] ?? [], $admin, false);
            }

            if (array_key_exists('role_ids', $data)) {
                $this->syncRoleRules($plan, $data['role_ids'] ?? [], $admin, false);
            }

            $freshPlan = $plan->fresh([
                'category',
                'planFeatures.feature',
                'roleRules.role',
            ]);

            $this->audit(
                action: 'plan_updated',
                auditable: $freshPlan,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: $freshPlan->toArray()
            );

            $this->clearPlanCaches($freshPlan);

            return $freshPlan;
        });
    }

    public function deletePlan(MembershipPlan $plan, ?User $admin = null): void
    {
        DB::transaction(function () use ($plan, $admin) {
            $hasOrders = $plan->orders()->exists();
            $hasMemberships = $plan->memberships()->exists();

            if ($hasOrders || $hasMemberships) {
                $oldValues = $plan->toArray();

                $plan->update(['status' => false]);

                $this->audit(
                    action: 'plan_deactivated',
                    auditable: $plan,
                    performedBy: $admin,
                    oldValues: $oldValues,
                    newValues: $plan->fresh()->toArray()
                );

                $this->clearPlanCaches($plan);

                return;
            }

            $oldValues = $plan->toArray();

            $plan->delete();

            $this->audit(
                action: 'plan_deleted',
                auditable: $plan,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: null
            );

            $this->clearPlanCaches($plan);
        });
    }

    public function syncFeatures(
        MembershipPlan $plan,
        array $features,
        ?User $admin = null,
        bool $withTransaction = true
    ): MembershipPlan {
        $callback = function () use ($plan, $features, $admin) {
            $oldValues = $plan->planFeatures()->with('feature')->get()->toArray();

            $normalizedFeatureIds = [];

            foreach ($features as $item) {
                $featureId = (int) ($item['feature_id'] ?? 0);

                if ($featureId <= 0) {
                    continue;
                }

                $feature = MembershipFeature::query()->findOrFail($featureId);

                $value = $item['feature_value'] ?? $item['value'] ?? null;

                if ($value === null || $value === '') {
                    throw ValidationException::withMessages([
                        'features' => ["Feature value is required for {$feature->name}."],
                    ]);
                }

                $isUnlimited = (bool) ($item['is_unlimited'] ?? false);

                if (Str::lower((string) $value) === 'unlimited') {
                    $isUnlimited = true;
                }

                MembershipPlanFeature::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'feature_value' => (string) $value,
                        'is_unlimited' => $isUnlimited,
                        'metadata' => $item['metadata'] ?? [],
                    ]
                );

                $normalizedFeatureIds[] = $feature->id;
            }

            MembershipPlanFeature::query()
                ->where('plan_id', $plan->id)
                ->when(!empty($normalizedFeatureIds), function ($query) use ($normalizedFeatureIds) {
                    $query->whereNotIn('feature_id', $normalizedFeatureIds);
                })
                ->when(empty($normalizedFeatureIds), function ($query) {
                    $query->whereRaw('1 = 1');
                })
                ->delete();

            $freshPlan = $plan->fresh(['planFeatures.feature']);

            $this->audit(
                action: 'plan_features_synced',
                auditable: $freshPlan,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: $freshPlan->planFeatures->toArray()
            );

            $this->clearPlanCaches($freshPlan);

            return $freshPlan;
        };

        return $withTransaction ? DB::transaction($callback) : $callback();
    }

    public function syncRoleRules(
        MembershipPlan $plan,
        array $roleIds,
        ?User $admin = null,
        bool $withTransaction = true
    ): MembershipPlan {
        $callback = function () use ($plan, $roleIds, $admin) {
            $oldValues = $plan->roleRules()->with('role')->get()->toArray();

            $roleIds = collect($roleIds)
                ->map(fn ($roleId) => (int) $roleId)
                ->filter(fn ($roleId) => $roleId > 0)
                ->unique()
                ->values()
                ->all();

            if (!empty($roleIds)) {
                $validRoleIds = Role::query()
                    ->whereIn('id', $roleIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                foreach ($validRoleIds as $roleId) {
                    MembershipPlanRoleRule::query()->updateOrCreate(
                        [
                            'plan_id' => $plan->id,
                            'role_id' => $roleId,
                        ],
                        [
                            'is_active' => true,
                        ]
                    );
                }

                MembershipPlanRoleRule::query()
                    ->where('plan_id', $plan->id)
                    ->whereNotIn('role_id', $validRoleIds)
                    ->delete();
            } else {
                MembershipPlanRoleRule::query()
                    ->where('plan_id', $plan->id)
                    ->delete();
            }

            $freshPlan = $plan->fresh(['roleRules.role']);

            $this->audit(
                action: 'plan_role_rules_synced',
                auditable: $freshPlan,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: $freshPlan->roleRules->toArray()
            );

            $this->clearPlanCaches($freshPlan);

            return $freshPlan;
        };

        return $withTransaction ? DB::transaction($callback) : $callback();
    }

    public function isPlanAllowedForUser(MembershipPlan $plan, User $user): bool
    {
        if (!$plan->status) {
            return false;
        }

        if ($plan->slug === 'free') {
            return true;
        }

        $roleId = $this->resolveUserRoleId($user);

        if (!$roleId) {
            return false;
        }

        return MembershipPlanRoleRule::query()
            ->where('plan_id', $plan->id)
            ->where('role_id', $roleId)
            ->where('is_active', true)
            ->exists();
    }

    public function clearPlanCaches(?MembershipPlan $plan = null): void
    {
        Cache::store('redis')->forget('membership:plans:active');
        Cache::store('redis')->forget('membership:admin:stats');

        if ($plan) {
            Cache::store('redis')->forget("membership:plan:{$plan->id}:features");

            $roleIds = MembershipPlanRoleRule::query()
                ->where('plan_id', $plan->id)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                $this->accessService->forgetRolePlansCache((int) $roleId);
            }
        }
    }

    private function prepareSlug(string $value, string $modelClass, ?int $ignoreId = null): string
    {
        $slug = Str::slug($value);

        if (!$slug) {
            throw ValidationException::withMessages([
                'slug' => ['Invalid slug value.'],
            ]);
        }

        $query = $modelClass::query()->where('slug', $slug);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['The slug has already been taken.'],
            ]);
        }

        return $slug;
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function resolveUserRoleId(User $user): ?int
    {
        if (!Schema::hasTable('roles')) {
            return null;
        }

        if (!$user->role_id || !is_numeric($user->role_id)) {
            return null;
        }

        return (int) $user->role_id;
    }

    private function audit(
        string $action,
        ?object $auditable,
        ?User $performedBy,
        ?array $oldValues,
        ?array $newValues
    ): void {
        if (!Schema::hasTable('membership_audit_logs')) {
            return;
        }

        MembershipAuditLog::query()->create([
            'user_id' => null,
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