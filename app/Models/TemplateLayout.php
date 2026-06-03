<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TemplateLayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'layout_json',
    ];

    protected $casts = [
        'layout_json' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}