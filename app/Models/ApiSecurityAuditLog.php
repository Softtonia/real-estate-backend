<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiSecurityAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_client_id',
        'application_password_id',
        'event',
        'actor_user_id',
        'actor_type',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function applicationPassword(): BelongsTo
    {
        return $this->belongsTo(ApplicationPassword::class);
    }
}