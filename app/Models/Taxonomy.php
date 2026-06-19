<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Taxonomy extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'is_relationship',
        'is_parent',
        'name',
        'slug',
        'description',
        'is_default',
        'hierarchical',
        'status',
        'created_by',
        'sort_order',
    ];

    protected $casts = [
        'is_relationship' => 'boolean',
        'is_parent' => 'boolean',
        'is_default' => 'boolean',
        'hierarchical' => 'boolean',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'is_relationship' => false,
        'is_parent' => false,
        'is_default' => false,
        'hierarchical' => false,
        'status' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (Taxonomy $taxonomy) {
            if (empty($taxonomy->slug) && !empty($taxonomy->name)) {
                $taxonomy->slug = Str::slug($taxonomy->name);
            }

            if (!$taxonomy->is_relationship) {
                $taxonomy->is_parent = false;
            }
        });
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'taxonomy_relationships',
            'child_taxonomy_id',
            'parent_taxonomy_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps()
            ->orderBy('taxonomy_relationships.sort_order')
            ->orderBy('taxonomies.id');
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'taxonomy_relationships',
            'parent_taxonomy_id',
            'child_taxonomy_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps()
            ->orderBy('taxonomy_relationships.sort_order')
            ->orderBy('taxonomies.id');
    }

    public function activeParents(): BelongsToMany
    {
        return $this->parents()
            ->wherePivot('status', true)
            ->where('taxonomies.status', true);
    }

    public function activeChildren(): BelongsToMany
    {
        return $this->children()
            ->wherePivot('status', true)
            ->where('taxonomies.status', true);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(TaxonomyTerm::class, 'taxonomy_id');
    }

    public function activeTerms(): HasMany
    {
        return $this->terms()
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function customFieldGroups(): HasManyThrough
    {
        return $this->hasManyThrough(
            CustomFieldGroup::class,
            CustomFieldGroupLocationRule::class,
            'taxonomy_id',
            'id',
            'id',
            'custom_field_group_id'
        )->distinct();
    }

    public function activeCustomFieldGroups(): HasManyThrough
    {
        return $this->customFieldGroups()
            ->where('custom_field_groups.status', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isStandalone(): bool
    {
        return !$this->is_relationship;
    }

    public function isParentTaxonomy(): bool
    {
        return $this->is_relationship && $this->is_parent;
    }

    public function isChildTaxonomy(): bool
    {
        return $this->is_relationship && !$this->is_parent;
    }

    public function hierarchyType(): string
    {
        if (!$this->is_relationship) {
            return 'standalone';
        }

        return $this->is_parent ? 'parent' : 'child';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', false);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_default', false);
    }

    public function scopeRelationship(Builder $query): Builder
    {
        return $query->where('is_relationship', true);
    }

    public function scopeStandalone(Builder $query): Builder
    {
        return $query->where('is_relationship', false);
    }

    public function scopeParents(Builder $query): Builder
    {
        return $query->where('is_relationship', true)
            ->where('is_parent', true);
    }

    public function scopeChildren(Builder $query): Builder
    {
        return $query->where('is_relationship', true)
            ->where('is_parent', false);
    }

    public function scopeByParent(Builder $query, int $parentId): Builder
    {
        return $query->whereHas('parents', function ($q) use ($parentId) {
            $q->where('taxonomies.id', $parentId);
        });
    }

    public function scopeHierarchical(Builder $query): Builder
    {
        return $query->where('hierarchical', true);
    }

    public function scopeFlat(Builder $query): Builder
    {
        return $query->where('hierarchical', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopeTrash(Builder $query): Builder
    {
        return $query->onlyTrashed();
    }
    public function postTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            PostType::class,
            'post_type_taxonomies',
            'taxonomy_id',
            'post_type_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps();
    }

    public function activePostTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            PostType::class,
            'post_type_taxonomies',
            'taxonomy_id',
            'post_type_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->wherePivot('status', true)
            ->where('post_types.status', true)
            ->withTimestamps();
    }
}
