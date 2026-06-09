<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldCondition extends Model
{
    protected $fillable = [
        'custom_field_id',
        'taxonomy_id',
        'taxonomy_term_id',
        'operator',
    ];

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    public function taxonomyTerm()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'taxonomy_term_id');
    }

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'taxonomy_term_id');
    }

    public function scopeInclude($query)
    {
        return $query->where('operator', 'include');
    }

    public function scopeExclude($query)
    {
        return $query->where('operator', 'exclude');
    }
}