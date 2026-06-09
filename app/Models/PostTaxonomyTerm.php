<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostTaxonomyTerm extends Model
{
    protected $fillable = [
        'dynamic_post_id',
        'taxonomy_id',
        'taxonomy_term_id',
    ];

    public function post()
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function dynamicPost()
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'taxonomy_term_id');
    }

    public function taxonomyTerm()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'taxonomy_term_id');
    }
}