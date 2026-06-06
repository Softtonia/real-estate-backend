<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TemplateDisplayCondition extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'condition_value' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}