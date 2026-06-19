<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostTypeTaxonomy extends Model
{
    protected $table = 'post_type_taxonomies';

    protected $fillable = [
        'post_type_id',
        'taxonomy_id',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'post_type_id' => 'integer',
        'taxonomy_id' => 'integer',
        'sort_order' => 'integer',
        'status' => 'boolean',
    ];

    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }
}