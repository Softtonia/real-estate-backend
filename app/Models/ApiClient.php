<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    use SoftDeletes;

    protected $fillable = [
        // new secure fields
        'name',
        'slug',
        'type',
        'status',
        'allowed_origins',
        'permissions',
        'rate_limit_per_minute',
        'requires_signature',
        'description',

        // legacy fields
        'client_name',
        'client_id',
        'app_type',
        'allowed_domain',
        'used_by_origin',
        'last_used_at',
    ];

    protected $hidden = [
        'client_secret',
        'nextjs_internal_key',
    ];

    protected $casts = [
        'allowed_origins' => 'array',
        'permissions' => 'array',
        'rate_limit_per_minute' => 'integer',
        'requires_signature' => 'boolean',
        'last_used_at' => 'datetime',
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
        return (string) $this->status === '1';
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}