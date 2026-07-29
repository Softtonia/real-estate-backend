<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAddon;
use App\Models\Membership\MembershipAuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipAddonAdminService
{
    public function paginatedAddons(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return MembershipAddon::query()
            ->select([
                'id',
                'name',
                'slug',
                'description',
                'addon_type',
                'currency',
                'price',
                'sale_price',
                'credit_type',
                'credit_quantity',
                'duration_days',
                'status',
                'sort_order',
                'created_at',
                'updated_at',
            ])
            ->withCount([
                'orders',
                'usages',
            ])
            ->when(isset($filters['status']), function ($query) use ($filters) {
                $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(!empty($filters['addon_type']), function ($query) use ($filters) {
                $query->where('addon_type', $filters['addon_type']);
            })
            ->when(!empty($filters['credit_type']), function ($query) use ($filters) {
                $query->where('credit_type', $filters['credit_type']);
            })
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('addon_type', 'like', "%{$search}%")
                        ->orWhere('credit_type', 'like', "%{$search}%");
                });
            })
            ->orderBy('sort_order')
            ->latest('id')
            ->paginate($perPage);
    }

    public function createAddon(array $data, ?User $admin = null): MembershipAddon
    {
        return DB::transaction(function () use ($data, $admin) {
            $addon = MembershipAddon::query()->create($this->payload($data));

            $this->audit(
                action: 'addon_created',
                auditable: $addon,
                performedBy: $admin,
                oldValues: null,
                newValues: $addon->toArray()
            );

            $this->clearCaches();

            return $addon->fresh();
        });
    }

    public function updateAddon(MembershipAddon $addon, array $data, ?User $admin = null): MembershipAddon
    {
        return DB::transaction(function () use ($addon, $data, $admin) {
            $oldValues = $addon->toArray();

            $addon->update($this->payload($data, true));

            $freshAddon = $addon->fresh();

            $this->audit(
                action: 'addon_updated',
                auditable: $freshAddon,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: $freshAddon->toArray()
            );

            $this->clearCaches();

            return $freshAddon;
        });
    }

    public function deleteAddon(MembershipAddon $addon, ?User $admin = null): void
    {
        DB::transaction(function () use ($addon, $admin) {
            $oldValues = $addon->toArray();

            if ($addon->orders()->exists() || $addon->usages()->exists()) {
                $addon->update(['status' => false]);

                $this->audit(
                    action: 'addon_deactivated',
                    auditable: $addon,
                    performedBy: $admin,
                    oldValues: $oldValues,
                    newValues: $addon->fresh()->toArray()
                );

                $this->clearCaches();

                return;
            }

            $addon->delete();

            $this->audit(
                action: 'addon_deleted',
                auditable: $addon,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: null
            );

            $this->clearCaches();
        });
    }

    public function addonDetail(MembershipAddon $addon): MembershipAddon
    {
        return $addon->loadMissing([
            'orders' => function ($query) {
                $query->latest('id')->limit(50);
            },
            'orders.user:id,first_name,last_name,email,phone,role_id',
            'usages' => function ($query) {
                $query->latest('id')->limit(50);
            },
            'usages.user:id,first_name,last_name,email,phone,role_id',
        ]);
    }

    private function payload(array $data, bool $isUpdate = false): array
    {
        $payload = [];

        /*
    |--------------------------------------------------------------------------
    | Basic text fields
    |--------------------------------------------------------------------------
    */
        foreach (
            [
                'name',
                'description',
                'addon_type',
                'credit_type',
            ] as $field
        ) {
            if (!$isUpdate || array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Slug
    |--------------------------------------------------------------------------
    */
        if (!$isUpdate || array_key_exists('slug', $data) || array_key_exists('name', $data)) {
            $slugSource = $data['slug'] ?? $data['name'] ?? null;

            if (!$slugSource) {
                throw ValidationException::withMessages([
                    'slug' => ['Slug or name is required.'],
                ]);
            }

            $payload['slug'] = Str::slug($slugSource);
        }

        /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
        if (!$isUpdate || array_key_exists('currency', $data)) {
            $payload['currency'] = strtoupper($data['currency'] ?? 'INR');
        }

        /*
    |--------------------------------------------------------------------------
    | Price fields
    |--------------------------------------------------------------------------
    */
        if (!$isUpdate || array_key_exists('price', $data)) {
            $payload['price'] = isset($data['price'])
                ? round((float) $data['price'], 2)
                : 0;
        }

        if (!$isUpdate || array_key_exists('sale_price', $data)) {
            $payload['sale_price'] = isset($data['sale_price']) && $data['sale_price'] !== ''
                ? round((float) $data['sale_price'], 2)
                : null;
        }

        /*
    |--------------------------------------------------------------------------
    | Credit quantity
    |--------------------------------------------------------------------------
    */
        if (!$isUpdate || array_key_exists('credit_quantity', $data)) {
            $payload['credit_quantity'] = isset($data['credit_quantity']) && $data['credit_quantity'] !== ''
                ? (int) $data['credit_quantity']
                : null;
        }

        /*
    |--------------------------------------------------------------------------
    | Support both duration_days and validity_days
    |--------------------------------------------------------------------------
    */
        if (
            !$isUpdate
            || array_key_exists('duration_days', $data)
            || array_key_exists('validity_days', $data)
        ) {
            $durationDays = $data['duration_days']
                ?? $data['validity_days']
                ?? null;

            $payload['duration_days'] = $durationDays !== null && $durationDays !== ''
                ? (int) $durationDays
                : null;
        }

        /*
    |--------------------------------------------------------------------------
    | Sort order
    |--------------------------------------------------------------------------
    */
        if (!$isUpdate || array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = isset($data['sort_order']) && $data['sort_order'] !== ''
                ? (int) $data['sort_order']
                : 0;
        }

        /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */
        if (!$isUpdate || array_key_exists('status', $data)) {
            $payload['status'] = array_key_exists('status', $data)
                ? (bool) $data['status']
                : true;
        }

        /*
    |--------------------------------------------------------------------------
    | Metadata
    |--------------------------------------------------------------------------
    */
        if (!$isUpdate || array_key_exists('metadata', $data)) {
            $payload['metadata'] = $data['metadata'] ?? [];
        }

        return $payload;
    }

    private function clearCaches(): void
    {
        Cache::store('redis')->forget('membership:addons:active');
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
