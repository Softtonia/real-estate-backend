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

    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_DATABASE = 'database';
    public const CHANNEL_PUSH_DATABASE = 'push_database';

    public const CHANNELS = [
        self::CHANNEL_PUSH,
        self::CHANNEL_DATABASE,
        self::CHANNEL_PUSH_DATABASE,
    ];

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
        return $query->where('channel', $channel);
    }
}