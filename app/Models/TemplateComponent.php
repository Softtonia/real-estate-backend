<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TemplateComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'component_name',
        'component_key',
        'component_type',
        'icon',
        'config_json',
        'status',
    ];

    protected $casts = [
        'config_json' => 'array',
        'status' => 'boolean',
    ];
}