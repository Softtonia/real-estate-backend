<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;

class Taxonomy extends Model
{
    use SoftDeletes;

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

    // Taxonomy Terms
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

    // Custom Field Groups via Location Rules
    public function customFieldGroups()
    {
        return $this->hasManyThrough(
            \App\Models\CustomFieldGroup::class,
            \App\Models\CustomFieldGroupLocationRule::class,
            'taxonomy_id',            // foreign key on location rules
            'id',                     // foreign key on custom field groups
            'id',                     // local key on taxonomy
            'custom_field_group_id'   // local key on location rules
        );
    }

    public function activeCustomFieldGroups()
    {
        return $this->customFieldGroups()->where('status', true);
    }

    // Post Types linked to this taxonomy (many-to-many)
    public function postTypes()
    {
        return $this->belongsToMany(PostType::class, 'post_type_taxonomies')
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps();
    }

    // Creator
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
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
}