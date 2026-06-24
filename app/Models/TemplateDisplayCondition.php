<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateDisplayCondition extends Model
{
    protected $fillable = [
        'template_id',
        'show_type',
        'source_type',
        'post_type_slug',
        'taxonomy_slug',
        'taxonomy_term_ids',
        'relation',
    ];

    protected $casts = [
        'taxonomy_term_ids' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}