<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedApiIp extends Model
{
    protected $fillable = [
        'ip_address',
        'reason',
        'permanent',
        'blocked_until',
    ];

    protected $casts = [
        'permanent' => 'boolean',
        'blocked_until' => 'datetime',
    ];

    public function isActive(): bool
    {
        if ($this->permanent) {
            return true;
        }

        return $this->blocked_until !== null && $this->blocked_until->isFuture();
    }
}