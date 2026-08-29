<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserIpLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ip_address',
        'country',
        'city',
        'region',
        'country_code',
        'region_code',
        'lat',
        'lon',
        'timezone',
        'isp',
        'org',
        'as',
        'query',
        'user_agent',
        'device',
        'browser',
        'os',
        'login_method',
        'status',
        'blocked_at',
        'blocked_reason',
        'created_at',
        'updated_at',
    ];

    protected $appends = [
        'location',
        'device_browser',
        'login_date_time',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function getLocationAttribute(): string
    {
        $parts = array_filter([$this->city, $this->region, $this->country]);
        return !empty($parts) ? implode(', ', $parts) : ($this->country ?? 'Unknown Location');
    }

    public function getDeviceBrowserAttribute(): string
    {
        $device = $this->device ?: 'Desktop';
        $browser = $this->browser ?: 'Browser';
        return "{$device} / {$browser}";
    }

    public function getLoginDateTimeAttribute(): ?string
    {
        return $this->created_at ? $this->created_at->format('M d, Y h:i A') : null;
    }
}
