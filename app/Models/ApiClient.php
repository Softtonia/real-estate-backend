<?php

namespace App\Models;

use App\Support\ApiPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiClient extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'allowed_origins',
        'permissions',
        'rate_limit_per_minute',
        'requires_signature',
        'description',
        'last_used_at',
    ];

    protected $casts = [
        'status' => 'boolean',
        'allowed_origins' => 'array',
        'permissions' => 'array',
        'rate_limit_per_minute' => 'integer',
        'requires_signature' => 'boolean',
        'last_used_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function applicationPasswords(): HasMany
    {
        return $this->hasMany(ApplicationPassword::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '1');
    }

    public function isActive(): bool
    {
        $value = $this->getRawOriginal('status');

        if ($value === null && array_key_exists('status', $this->attributes)) {
            $value = $this->attributes['status'];
        }

        if ($value === null) {
            $value = $this->status;
        }

        return $this->toBoolean($value);
    }

    public function setStatusAttribute($value): void
    {
        $this->attributes['status'] = $this->toEnumBoolean($value);
    }

    public function setRequiresSignatureAttribute($value): void
    {
        $this->attributes['requires_signature'] = $this->toEnumBoolean($value);
    }

    public function hasPermission(string $permission): bool
    {
        return ApiPermission::matches(
            $this->permissions ?? [],
            $permission
        );
    }

    private function toEnumBoolean(mixed $value): string
    {
        return $this->toBoolean($value) ? '1' : '0';
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_float($value)) {
            return (int) $value === 1;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}