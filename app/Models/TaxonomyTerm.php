<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaxonomyTerm extends Model
{
    protected $fillable = [
        'taxonomy_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($term) {
            if (empty($term->slug)) {
                $term->slug = Str::slug($term->name);
            }
        });

        static::updating(function ($term) {
            if (empty($term->slug)) {
                $term->slug = Str::slug($term->name);
            }
        });
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    public function parent()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(TaxonomyTerm::class, 'parent_id');
    }

    public function posts()
    {
        return $this->belongsToMany(
            DynamicPost::class,
            'post_taxonomy_terms',
            'taxonomy_term_id',
            'dynamic_post_id'
        )->withPivot('taxonomy_id')->withTimestamps();
    }

    public function postTaxonomyTerms()
    {
        return $this->hasMany(PostTaxonomyTerm::class, 'taxonomy_term_id');
    }

    public function meta()
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', 'taxonomy_term');
    }

    public function customFieldValues()
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', 'taxonomy_term');
    }

    public function conditions()
    {
        return $this->hasMany(CustomFieldCondition::class, 'taxonomy_term_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeByTaxonomy($query, $taxonomyId)
    {
        return $query->where('taxonomy_id', $taxonomyId);
    }
        public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}