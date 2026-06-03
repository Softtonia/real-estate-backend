<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_name',
        'slug',
        'created_by',
        'status',
        'priority',
    ];

    public function conditions()
    {
        return $this->hasMany(TemplateDisplayCondition::class);
    }

    public function layout()
    {
        return $this->hasOne(TemplateLayout::class);
    }
}