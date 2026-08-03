<?php

namespace App\Models\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $table = 'notification_logs';

    protected $fillable = [
        'batch_id',
        'user_id',
        'device_id',
        'fcm_token',
        'platform',
        'title',
        'body',
        'payload',
        'firebase_message_id',
        'status',
        'error_code',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NotificationDevice::class, 'device_id');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function markSent(?string $firebaseMessageId = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'firebase_message_id' => $firebaseMessageId,
            'sent_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();
    }

    public function markFailed(?string $errorCode = null, ?string $errorMessage = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ])->save();
    }

    public function markSkipped(?string $reason = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SKIPPED,
            'error_message' => $reason,
        ])->save();
    }
}