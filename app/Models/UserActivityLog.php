<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserActivityLog extends Model
{
    use HasFactory;

    protected $table = 'user_activity_logs';

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'description',
        'reference_id',
        'entity_type',
        'entity_id',
        'metadata',
        'ip_address',
        'user_agent',
        'device',
        'browser',
        'os',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'date_time',
        'date',
        'time',
        'device_browser',
        'action_badge_color',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function getDateTimeAttribute(): ?string
    {
        return $this->created_at ? $this->created_at->format('M d, Y h:i A') : null;
    }

    public function getDateAttribute(): ?string
    {
        return $this->created_at ? $this->created_at->format('M d, Y') : null;
    }

    public function getTimeAttribute(): ?string
    {
        return $this->created_at ? $this->created_at->format('h:i A') : null;
    }

    public function getDeviceBrowserAttribute(): string
    {
        $browser = $this->browser ?: 'Chrome';
        $os = $this->os ?: 'Windows';
        return "{$browser} ({$os})";
    }

    public function getActionBadgeColorAttribute(): string
    {
        $action = strtolower($this->action ?? '');
        return match ($action) {
            'created', 'added', 'submitted' => 'blue',
            'updated', 'edited', 'modified' => 'green',
            'deleted', 'removed', 'rejected' => 'red',
            'approved', 'verified', 'activated' => 'emerald',
            'assigned', 'reassigned' => 'purple',
            'viewed', 'accessed' => 'gray',
            'logged in', 'login' => 'teal',
            default => 'slate',
        };
    }
}
