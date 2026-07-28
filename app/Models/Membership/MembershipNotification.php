<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'membership_id',
        'title',
        'message',
        'notification_type',
        'channel',
        'read_at',
        'scheduled_at',
        'sent_at',
        'metadata',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'membership_id');
    }

    public function markAsRead(): bool
    {
        return $this->update(['read_at' => now()]);
    }
}