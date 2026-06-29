<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestNonce extends Model
{
    protected $fillable = [
        'api_client_id',
        'nonce',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}