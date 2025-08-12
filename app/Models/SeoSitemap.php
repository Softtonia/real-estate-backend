<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoSitemap extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'last_modified' => 'datetime',
    ];
}
