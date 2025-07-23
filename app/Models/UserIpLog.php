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
        'created_at',
        'updated_at',
    ];
}
