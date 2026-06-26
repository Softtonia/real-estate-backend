<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateDisplayCondition extends Model
{
    protected $fillable = [
        'template_id',
        'show_type',
        'source_type',
        'post_type_id',
        'post_type_slug',
        'taxonomy_id',
        'taxonomy_slug',
        'taxonomy_term_ids',
        'relation',
        'condition_value',
    ];

    protected $casts = [
        'taxonomy_term_ids' => 'array',
        'condition_value' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }
}
