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
        return $query->where('status', 1);
    }

    public function isActive(): bool
    {
        return $this->toBooleanInt(
            $this->getRawOriginal('status') ?? $this->attributes['status'] ?? $this->status
        ) === 1;
    }

    public function isSignatureRequired(): bool
    {
        return $this->toBooleanInt(
            $this->getRawOriginal('requires_signature') ?? $this->attributes['requires_signature'] ?? $this->requires_signature
        ) === 1;
    }

    public function setStatusAttribute($value): void
    {
        $this->attributes['status'] = $this->toBooleanInt($value);
    }

    public function setRequiresSignatureAttribute($value): void
    {
        $this->attributes['requires_signature'] = $this->toBooleanInt($value);
    }

    public function hasPermission(string $permission): bool
    {
        return ApiPermission::matches(
            $this->permissions ?? [],
            $permission
        );
    }

    private function toBooleanInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value === 1 ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true)
            ? 1
            : 0;
    }
}