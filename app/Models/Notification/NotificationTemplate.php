<?php

namespace App\Models\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    protected $fillable = [
        'template_key',
        'title',
        'body',
        'image_url',
        'data',
        'channel',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'data' => 'array',
        'status' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | New channel values
    |--------------------------------------------------------------------------
    */
    public const CHANNEL_PUSH_IN_APP = 'push_in_app';
    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_EMAIL = 'email';

    /*
    |--------------------------------------------------------------------------
    | Legacy channel values - keep for old DB/templates
    |--------------------------------------------------------------------------
    */
    public const CHANNEL_DATABASE = 'database';
    public const CHANNEL_PUSH_DATABASE = 'push_database';

    public const CHANNELS = [
        self::CHANNEL_PUSH_IN_APP,
        self::CHANNEL_PUSH,
        self::CHANNEL_IN_APP,
        self::CHANNEL_EMAIL,

        // legacy allowed values
        self::CHANNEL_DATABASE,
        self::CHANNEL_PUSH_DATABASE,
    ];

    public const ADMIN_CHANNEL_OPTIONS = [
        self::CHANNEL_PUSH_IN_APP => 'Push + In-App Database',
        self::CHANNEL_PUSH => 'Push Notification Only',
        self::CHANNEL_IN_APP => 'In-App Notification Only',
        self::CHANNEL_EMAIL => 'Email Only',
    ];

    public static function normalizeChannel(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return match ($channel) {
            self::CHANNEL_PUSH_DATABASE => self::CHANNEL_PUSH_IN_APP,
            self::CHANNEL_DATABASE => self::CHANNEL_IN_APP,
            self::CHANNEL_PUSH => self::CHANNEL_PUSH,
            self::CHANNEL_IN_APP => self::CHANNEL_IN_APP,
            self::CHANNEL_EMAIL => self::CHANNEL_EMAIL,
            self::CHANNEL_PUSH_IN_APP => self::CHANNEL_PUSH_IN_APP,
            default => self::CHANNEL_PUSH_IN_APP,
        };
    }

    public static function shouldSendPush(?string $channel): bool
    {
        $channel = self::normalizeChannel($channel);

        return in_array($channel, [
            self::CHANNEL_PUSH_IN_APP,
            self::CHANNEL_PUSH,
        ], true);
    }

    public static function shouldCreateInbox(?string $channel): bool
    {
        $channel = self::normalizeChannel($channel);

        return in_array($channel, [
            self::CHANNEL_PUSH_IN_APP,
            self::CHANNEL_IN_APP,
        ], true);
    }

    public static function shouldSendEmail(?string $channel): bool
    {
        return self::normalizeChannel($channel) === self::CHANNEL_EMAIL;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', self::normalizeChannel($channel));
    }
}