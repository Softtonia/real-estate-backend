<?php

namespace App\Models\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTopicSubscriber extends Model
{
    protected $table = 'notification_topic_subscribers';

    protected $fillable = [
        'topic_id',
        'user_id',
        'device_id',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(NotificationTopic::class, 'topic_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NotificationDevice::class, 'device_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function revoke(): void
    {
        $this->forceFill([
            'status' => false,
        ])->save();
    }
}