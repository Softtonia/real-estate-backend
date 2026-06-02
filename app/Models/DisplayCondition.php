<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DisplayCondition extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'show_type',
        'post_type',
        'condition_type',
        'value',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
