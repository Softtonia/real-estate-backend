<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Taxonomy extends Model
{

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_default',
        'hierarchical',
        'status',
        'created_by',
        'sort_order',
        'menu_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'hierarchical' => 'boolean',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'menu_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($taxonomy) {
            if (empty($taxonomy->slug)) {
                $taxonomy->slug = Str::slug($taxonomy->name);
            }
        });

        static::updating(function ($taxonomy) {
            if (empty($taxonomy->slug)) {
                $taxonomy->slug = Str::slug($taxonomy->name);
            }
        });
    }

    public function terms()
    {
        return $this->hasMany(TaxonomyTerm::class, 'taxonomy_id');
    }

    public function activeTerms()
    {
        return $this->hasMany(TaxonomyTerm::class, 'taxonomy_id')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function customFields()
    {
        return $this->hasMany(CustomField::class, 'taxonomy_id')
            ->where('entity_type', 'taxonomy');
    }

    public function activeCustomFields()
    {
        return $this->hasMany(CustomField::class, 'taxonomy_id')
            ->where('entity_type', 'taxonomy')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function postTaxonomyTerms()
    {
        return $this->hasMany(PostTaxonomyTerm::class, 'taxonomy_id');
    }

    public function conditions()
    {
        return $this->hasMany(CustomFieldCondition::class, 'taxonomy_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_default', false);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function postTypes()
    {
        return $this->belongsToMany(PostType::class, 'post_type_taxonomies')
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps();
    }
}
