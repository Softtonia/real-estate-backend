<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipCoupon;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipCouponAdminService
{
    public function paginatedCoupons(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return MembershipCoupon::query()
            ->select([
                'id',
                'code',
                'title',
                'discount_type',
                'discount_value',
                'minimum_order_amount',
                'maximum_discount_amount',
                'start_at',
                'end_at',
                'usage_limit',
                'usage_limit_per_user',
                'used_count',
                'new_user_only',
                'status',
                'created_at',
                'updated_at',
            ])
            ->withCount([
                'usages',
                'orders',
                'addonOrders',
            ])
            ->when(isset($filters['status']), function ($query) use ($filters) {
                $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(!empty($filters['discount_type']), function ($query) use ($filters) {
                $query->where('discount_type', $filters['discount_type']);
            })
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function createCoupon(array $data, ?User $admin = null): MembershipCoupon
    {
        return DB::transaction(function () use ($data, $admin) {
            $coupon = MembershipCoupon::query()->create($this->payload($data));

            $this->audit(
                action: 'coupon_created',
                auditable: $coupon,
                performedBy: $admin,
                oldValues: null,
                newValues: $coupon->toArray()
            );

            $this->clearCaches();

            return $coupon->fresh();
        });
    }

    public function updateCoupon(MembershipCoupon $coupon, array $data, ?User $admin = null): MembershipCoupon
    {
        return DB::transaction(function () use ($coupon, $data, $admin) {
            $oldValues = $coupon->toArray();

            $coupon->update($this->payload($data, true));

            $freshCoupon = $coupon->fresh();

            $this->audit(
                action: 'coupon_updated',
                auditable: $freshCoupon,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: $freshCoupon->toArray()
            );

            $this->clearCaches();

            return $freshCoupon;
        });
    }

    public function deleteCoupon(MembershipCoupon $coupon, ?User $admin = null): void
    {
        DB::transaction(function () use ($coupon, $admin) {
            $oldValues = $coupon->toArray();

            if ($coupon->usages()->exists() || $coupon->orders()->exists() || $coupon->addonOrders()->exists()) {
                $coupon->update(['status' => false]);

                $this->audit(
                    action: 'coupon_deactivated',
                    auditable: $coupon,
                    performedBy: $admin,
                    oldValues: $oldValues,
                    newValues: $coupon->fresh()->toArray()
                );

                $this->clearCaches();

                return;
            }

            $coupon->delete();

            $this->audit(
                action: 'coupon_deleted',
                auditable: $coupon,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: null
            );

            $this->clearCaches();
        });
    }

    public function couponDetail(MembershipCoupon $coupon): MembershipCoupon
    {
        return $coupon->loadMissing([
            'usages' => function ($query) {
                $query->latest('used_at')->limit(50);
            },
            'usages.user:id,first_name,last_name,email,phone,role_id',
            'orders:id,user_id,plan_id,coupon_id,order_number,total_amount,payment_status,order_status,created_at',
            'orders.user:id,first_name,last_name,email,phone',
            'orders.plan:id,name,slug',
        ]);
    }

    private function payload(array $data, bool $isUpdate = false): array
    {
        $payload = [];

        foreach ([
            'title',
            'description',
            'discount_type',
            'start_at',
            'end_at',
        ] as $field) {
            if (!$isUpdate || array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        if (!$isUpdate || array_key_exists('code', $data)) {
            $payload['code'] = strtoupper(Str::of((string) $data['code'])->trim()->toString());

            if ($payload['code'] === '') {
                throw ValidationException::withMessages([
                    'code' => ['Coupon code is required.'],
                ]);
            }
        }

        foreach ([
            'discount_value',
            'minimum_order_amount',
            'maximum_discount_amount',
        ] as $moneyField) {
            if (!$isUpdate || array_key_exists($moneyField, $data)) {
                $payload[$moneyField] = isset($data[$moneyField])
                    ? round((float) $data[$moneyField], 2)
                    : 0;
            }
        }

        foreach ([
            'usage_limit',
            'usage_limit_per_user',
        ] as $intField) {
            if (!$isUpdate || array_key_exists($intField, $data)) {
                $payload[$intField] = isset($data[$intField])
                    ? (int) $data[$intField]
                    : ($intField === 'usage_limit_per_user' ? 1 : null);
            }
        }

        foreach ([
            'allowed_plan_ids',
            'allowed_category_ids',
        ] as $arrayField) {
            if (!$isUpdate || array_key_exists($arrayField, $data)) {
                $payload[$arrayField] = !empty($data[$arrayField])
                    ? array_values(array_unique(array_map('intval', $data[$arrayField])))
                    : null;
            }
        }

        foreach ([
            'new_user_only',
            'status',
        ] as $boolField) {
            if (!$isUpdate || array_key_exists($boolField, $data)) {
                $payload[$boolField] = (bool) ($data[$boolField] ?? false);
            }
        }

        if (!$isUpdate && !array_key_exists('status', $payload)) {
            $payload['status'] = true;
        }

        if (!$isUpdate && !array_key_exists('new_user_only', $payload)) {
            $payload['new_user_only'] = false;
        }

        return $payload;
    }

    private function clearCaches(): void
    {
        Cache::store('redis')->forget('membership:admin:stats');
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