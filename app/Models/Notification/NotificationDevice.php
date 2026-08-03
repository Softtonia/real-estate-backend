<?php

namespace App\Models\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDevice extends Model
{
    protected $table = 'notification_devices';

    protected $fillable = [
        'user_id',
        'fcm_token',
        'platform',
        'app_type',
        'device_id',
        'device_name',
        'browser',
        'os',
        'ip_address',
        'user_agent',
        'status',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'status' => 'boolean',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_WEB = 'web';

    public const PLATFORMS = [
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
        self::PLATFORM_WEB,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true)
            ->whereNull('revoked_at');
    }

    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function markUsed(): void
    {
        $this->forceFill([
            'status' => true,
            'last_used_at' => now(),
            'revoked_at' => null,
        ])->save();
    }

    public function revoke(): void
    {
        $this->forceFill([
            'status' => false,
            'revoked_at' => now(),
        ])->save();
    }
}