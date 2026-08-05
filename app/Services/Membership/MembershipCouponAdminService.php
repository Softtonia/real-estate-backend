<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipCoupon;
use App\Models\User;
use Carbon\Carbon;
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
                'description',
                'discount_type',
                'discount_value',
                'minimum_order_amount',
                'maximum_discount_amount',
                'start_at',
                'end_at',
                'usage_limit',
                'usage_limit_per_user',
                'used_count',
                'allowed_plan_ids',
                'allowed_category_ids',
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
            ->when(
                array_key_exists('status', $filters)
                && $filters['status'] !== null
                && $filters['status'] !== '',
                function ($query) use ($filters) {
                    $query->where('status', $this->toBoolean($filters['status']));
                }
            )
            ->when(! empty($filters['discount_type']), function ($query) use ($filters) {
                $query->where('discount_type', $this->normalizeDiscountType($filters['discount_type']));
            })
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function createCoupon(array $data, ?User $admin = null): MembershipCoupon
    {
        return DB::transaction(function () use ($data, $admin) {
            $payload = $this->payload($data, false);

            $coupon = new MembershipCoupon();
            $coupon->forceFill($payload);
            $coupon->save();

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

            $payload = $this->payload($data, true);

            $coupon->forceFill($payload);

            if (! $coupon->isDirty()) {
                return $coupon->fresh();
            }

            $coupon->save();

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

            if (
                $coupon->usages()->exists()
                || $coupon->orders()->exists()
                || $coupon->addonOrders()->exists()
            ) {
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
        $data = $this->normalizeInputAliases($data);

        $payload = [];

        foreach ([
            'title',
            'description',
        ] as $field) {
            if (! $isUpdate || array_key_exists($field, $data)) {
                $payload[$field] = $this->nullableValue($data[$field] ?? null);
            }
        }

        if (! $isUpdate || array_key_exists('start_at', $data)) {
            $payload['start_at'] = $this->normalizeDate($data['start_at'] ?? null, false);
        }

        if (! $isUpdate || array_key_exists('end_at', $data)) {
            $payload['end_at'] = $this->normalizeDate($data['end_at'] ?? null, true);
        }

        if (! $isUpdate || array_key_exists('code', $data)) {
            $code = strtoupper(Str::of((string) ($data['code'] ?? ''))->trim()->toString());

            if ($code === '') {
                throw ValidationException::withMessages([
                    'code' => ['Coupon code is required.'],
                ]);
            }

            $payload['code'] = $code;
        }

        if (! $isUpdate || array_key_exists('discount_type', $data)) {
            $payload['discount_type'] = $this->normalizeDiscountType($data['discount_type'] ?? null);
        }

        if (! $isUpdate || array_key_exists('discount_value', $data)) {
            $payload['discount_value'] = $this->money($data['discount_value'] ?? 0);
        }

        if (! $isUpdate || array_key_exists('minimum_order_amount', $data)) {
            $payload['minimum_order_amount'] = $this->money($data['minimum_order_amount'] ?? 0);
        }

        if (! $isUpdate || array_key_exists('maximum_discount_amount', $data)) {
            $payload['maximum_discount_amount'] = $this->nullablePositiveMoney(
                $data['maximum_discount_amount'] ?? null
            );
        }

        foreach ([
            'usage_limit',
            'usage_limit_per_user',
        ] as $intField) {
            if (! $isUpdate || array_key_exists($intField, $data)) {
                if ($intField === 'usage_limit_per_user') {
                    $payload[$intField] = ! empty($data[$intField])
                        ? (int) $data[$intField]
                        : 1;
                } else {
                    $payload[$intField] = ! empty($data[$intField])
                        ? (int) $data[$intField]
                        : null;
                }
            }
        }

        foreach ([
            'allowed_plan_ids',
            'allowed_category_ids',
        ] as $arrayField) {
            if (! $isUpdate || array_key_exists($arrayField, $data)) {
                $payload[$arrayField] = ! empty($data[$arrayField])
                    ? array_values(array_unique(array_map('intval', (array) $data[$arrayField])))
                    : null;
            }
        }

        if (! $isUpdate || array_key_exists('new_user_only', $data)) {
            $payload['new_user_only'] = $this->toBoolean($data['new_user_only'] ?? false);
        }

        if (! $isUpdate || array_key_exists('status', $data)) {
            $payload['status'] = $isUpdate
                ? $this->toBoolean($data['status'] ?? false)
                : $this->toBoolean($data['status'] ?? true);
        }

        return $this->filterTableData('membership_coupons', $payload);
    }

    private function normalizeInputAliases(array $data): array
    {
        $map = [
            'min_order_amount' => 'minimum_order_amount',
            'minimum_amount' => 'minimum_order_amount',

            'max_discount_amount' => 'maximum_discount_amount',
            'maximum_amount' => 'maximum_discount_amount',

            'starts_at' => 'start_at',
            'start_date' => 'start_at',
            'valid_from' => 'start_at',

            'expires_at' => 'end_at',
            'expiry_date' => 'end_at',
            'expiry_at' => 'end_at',
            'expire_at' => 'end_at',
            'end_date' => 'end_at',
            'valid_until' => 'end_at',

            'total_usage_limit' => 'usage_limit',
            'limit_per_user' => 'usage_limit_per_user',
        ];

        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data) && ! array_key_exists($to, $data)) {
                $data[$to] = $data[$from];
            }
        }

        return $data;
    }

    private function normalizeDiscountType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace([' ', '-'], '_', $value);

        return match ($value) {
            'percentage', 'percent' => 'percentage',
            'fixed', 'fixed_amount', 'flat', 'flat_amount', 'amount' => 'fixed_amount',
            default => $value,
        };
    }

    private function normalizeDate(mixed $value, bool $endOfDay): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        $formats = [
            'Y-m-d',
            'd-m-Y',
            'd/m/Y',
            'Y/m/d',
            'Y-m-d H:i:s',
            'd-m-Y H:i:s',
            'd/m/Y H:i:s',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                return $endOfDay
                    ? $date->endOfDay()->format('Y-m-d H:i:s')
                    : $date->startOfDay()->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                //
            }
        }

        $date = Carbon::parse($value);

        return $endOfDay
            ? $date->endOfDay()->format('Y-m-d H:i:s')
            : $date->startOfDay()->format('Y-m-d H:i:s');
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, [
            '1',
            'true',
            'yes',
            'y',
            'active',
            'enabled',
            'on',
        ], true);
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function nullablePositiveMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = round((float) $value, 2);

        return $amount > 0 ? $amount : null;
    }

    private function nullableValue(mixed $value): mixed
    {
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function filterTableData(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->toArray();
    }

    private function clearCaches(): void
    {
        Cache::store('redis')->forget('membership:admin:stats');
        Cache::store('redis')->forget('membership:plans:active');
    }

    private function audit(
        string $action,
        ?object $auditable,
        ?User $performedBy,
        ?array $oldValues,
        ?array $newValues
    ): void {
        if (! Schema::hasTable('membership_audit_logs')) {
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