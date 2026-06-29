<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiAuthFailure extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_client_id',
        'reason',
        'token_prefix',
        'ip_address',
        'user_agent',
        'method',
        'path',
        'origin',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}