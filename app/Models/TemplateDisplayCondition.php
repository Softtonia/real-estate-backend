<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TemplateDisplayCondition extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'show_type',
        'post_type',
        'condition_type',
        'condition_value',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}