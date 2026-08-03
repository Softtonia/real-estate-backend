<?php

namespace App\Models\Notification;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationTopic extends Model
{
    protected $table = 'notification_topics';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function subscribers(): HasMany
    {
        return $this->hasMany(NotificationTopicSubscriber::class, 'topic_id');
    }

    public function activeSubscribers(): HasMany
    {
        return $this->hasMany(NotificationTopicSubscriber::class, 'topic_id')
            ->where('status', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }
}