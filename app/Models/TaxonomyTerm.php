<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaxonomyTerm extends Model
{
    protected $fillable = [
        'taxonomy_id',
        'parent_id',
        'relation_with_taxonomy_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'taxonomy_id' => 'integer',
        'parent_id' => 'integer',
        'relation_with_taxonomy_id' => 'integer',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'created_by' => 'integer',
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
        return $this->hasMany(TaxonomyTerm::class, 'parent_id')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');
    }

    public function relationWithTaxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'relation_with_taxonomy_id');
    }

    public function relationValues()
    {
        return $this->belongsToMany(
            TaxonomyTerm::class,
            'taxonomy_term_relations',
            'taxonomy_term_id',
            'relation_value_term_id'
        )
            ->withPivot([
                'relation_with_taxonomy_id',
                'sort_order',
                'status',
            ])
            ->withTimestamps()
            ->orderBy('taxonomy_term_relations.sort_order', 'asc')
            ->orderBy('taxonomy_terms.id', 'asc');
    }

    public function relatedFromTerms()
    {
        return $this->belongsToMany(
            TaxonomyTerm::class,
            'taxonomy_term_relations',
            'relation_value_term_id',
            'taxonomy_term_id'
        )
            ->withPivot([
                'relation_with_taxonomy_id',
                'sort_order',
                'status',
            ])
            ->withTimestamps();
    }

    public function posts()
    {
        return $this->belongsToMany(
            DynamicPost::class,
            'post_taxonomy_terms',
            'taxonomy_term_id',
            'dynamic_post_id'
        )
            ->withPivot('taxonomy_id')
            ->withTimestamps();
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
        return $this->meta();
    }

    public function conditions()
    {
        return $this->hasMany(CustomFieldCondition::class, 'taxonomy_term_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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
}