<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostTypeTaxonomy extends Model
{
    protected $fillable = [
        'post_type_id',
        'taxonomy_id',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'status' => 'boolean',
    ];

    public function postType()
    {
        return $this->belongsTo(PostType::class);
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class);
    }
}