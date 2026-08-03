<?php

namespace App\Models\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationBatch extends Model
{
    protected $table = 'notification_batches';

    protected $fillable = [
        'batch_uuid',
        'template_id',
        'title',
        'body',
        'image_url',
        'target_type',
        'target_value',
        'payload',
        'total_count',
        'success_count',
        'failed_count',
        'status',
        'scheduled_at',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public const TARGET_ALL = 'all';
    public const TARGET_ROLE = 'role';
    public const TARGET_USER = 'user';
    public const TARGET_USERS = 'users';
    public const TARGET_TOPIC = 'topic';
    public const TARGET_TOKEN = 'token';

    public const TARGETS = [
        self::TARGET_ALL,
        self::TARGET_ROLE,
        self::TARGET_USER,
        self::TARGET_USERS,
        self::TARGET_TOPIC,
        self::TARGET_TOKEN,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_SCHEDULED,
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'batch_id');
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'batch_id');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
        ]);
    }

    public function scopeReadyToProcess(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('scheduled_at')
                ->orWhere('scheduled_at', '<=', now());
        })->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
        ]);
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
        ])->save();
    }

    public function incrementSuccess(): void
    {
        $this->increment('success_count');
    }

    public function incrementFailed(): void
    {
        $this->increment('failed_count');
    }
}