<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoRobot extends Model
{
    use HasFactory;

     protected $guarded = [];

    protected $casts = [
        'disallow' => 'array',
        'allow' => 'array',
    ];
}
