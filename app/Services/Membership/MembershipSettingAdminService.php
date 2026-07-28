<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipSetting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MembershipSettingAdminService
{
    public function paginatedSettings(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return MembershipSetting::query()
            ->select([
                'id',
                'key',
                'value',
                'value_type',
                'is_public',
                'description',
                'created_at',
                'updated_at',
            ])
            ->when(isset($filters['is_public']), function ($query) use ($filters) {
                $query->where('is_public', filter_var($filters['is_public'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(!empty($filters['value_type']), function ($query) use ($filters) {
                $query->where('value_type', $filters['value_type']);
            })
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('key', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('key')
            ->paginate($perPage);
    }

    public function createSetting(array $data, ?User $admin = null): MembershipSetting
    {
        return DB::transaction(function () use ($data, $admin) {
            $setting = MembershipSetting::query()->create($this->payload($data));

            $this->audit(
                action: 'setting_created',
                auditable: $setting,
                performedBy: $admin,
                oldValues: null,
                newValues: $setting->toArray()
            );

            $this->clearSettingCache($setting->key);

            return $setting->fresh();
        });
    }

    public function updateSetting(MembershipSetting $setting, array $data, ?User $admin = null): MembershipSetting
    {
        return DB::transaction(function () use ($setting, $data, $admin) {
            $oldValues = $setting->toArray();
            $oldKey = $setting->key;

            $setting->update($this->payload($data));

            $freshSetting = $setting->fresh();

            $this->audit(
                action: 'setting_updated',
                auditable: $freshSetting,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: $freshSetting->toArray()
            );

            $this->clearSettingCache($oldKey);
            $this->clearSettingCache($freshSetting->key);

            return $freshSetting;
        });
    }

    public function deleteSetting(MembershipSetting $setting, ?User $admin = null): void
    {
        DB::transaction(function () use ($setting, $admin) {
            $oldValues = $setting->toArray();
            $key = $setting->key;

            $setting->delete();

            $this->audit(
                action: 'setting_deleted',
                auditable: $setting,
                performedBy: $admin,
                oldValues: $oldValues,
                newValues: null
            );

            $this->clearSettingCache($key);
        });
    }

    public function formattedSetting(MembershipSetting $setting): array
    {
        return [
            'id' => (int) $setting->id,
            'key' => $setting->key,
            'value' => $setting->formattedValue(),
            'raw_value' => $setting->value,
            'value_type' => $setting->value_type,
            'is_public' => (bool) $setting->is_public,
            'description' => $setting->description,
            'created_at' => optional($setting->created_at)->toDateTimeString(),
            'updated_at' => optional($setting->updated_at)->toDateTimeString(),
        ];
    }

    private function payload(array $data): array
    {
        $valueType = (string) ($data['value_type'] ?? 'string');

        return [
            'key' => strtolower(trim((string) $data['key'])),
            'value' => $this->normalizeValue($data['value'] ?? null, $valueType),
            'value_type' => $valueType,
            'is_public' => (bool) ($data['is_public'] ?? false),
            'description' => $data['description'] ?? null,
        ];
    }

    private function normalizeValue(mixed $value, string $valueType): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($valueType) {
            'integer' => (string) (int) $value,
            'float' => (string) round((float) $value, 4),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'array', 'json' => $this->jsonValue($value),
            default => (string) $value,
        };
    }

    private function jsonValue(mixed $value): string
    {
        if (is_string($value)) {
            json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        if (!$encoded) {
            throw ValidationException::withMessages([
                'value' => ['Invalid JSON/array setting value.'],
            ]);
        }

        return $encoded;
    }

    private function clearSettingCache(string $key): void
    {
        Cache::store('redis')->forget("membership:setting:{$key}");
        Cache::store('redis')->forget('membership:settings:public');
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