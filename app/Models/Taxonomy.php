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
        'parent_id',
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
        'parent_id' => 'integer',
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
        'parent_id' => null,
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

            /*
             * If relationship is disabled, taxonomy should stay standalone.
             */
            if (!$taxonomy->is_relationship) {
                $taxonomy->is_parent = false;
                $taxonomy->parent_id = null;
            }

            /*
             * Parent taxonomy should never have parent_id.
             */
            if ($taxonomy->is_relationship && $taxonomy->is_parent) {
                $taxonomy->parent_id = null;
            }
        });

        static::deleting(function (Taxonomy $taxonomy) {
            /*
             * On soft delete, do not permanently remove children.
             * We only detach children from this parent to avoid broken hierarchy.
             */
            if (!$taxonomy->isForceDeleting()) {
                $taxonomy->children()->update([
                    'parent_id' => null,
                    'is_relationship' => false,
                    'is_parent' => false,
                ]);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Taxonomy Parent / Child Relationships
    |--------------------------------------------------------------------------
    */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function parentTaxonomy(): BelongsTo
    {
        return $this->parent();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function childTaxonomies(): HasMany
    {
        return $this->children();
    }

    public function activeChildren(): HasMany
    {
        return $this->children()
            ->where('status', true);
    }

    public function activeChildTaxonomies(): HasMany
    {
        return $this->activeChildren();
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()
            ->with('childrenRecursive');
    }

    public function activeChildrenRecursive(): HasMany
    {
        return $this->activeChildren()
            ->with('activeChildrenRecursive');
    }

    /*
    |--------------------------------------------------------------------------
    | Taxonomy Terms
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Custom Field Groups
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Post Types
    |--------------------------------------------------------------------------
    */

    public function postTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            PostType::class,
            'post_type_taxonomies',
            'taxonomy_id',
            'post_type_id'
        )
            ->withPivot([
                'sort_order',
                'status',
            ])
            ->withTimestamps()
            ->orderBy('post_type_taxonomies.sort_order')
            ->orderBy('post_types.id');
    }

    public function activePostTypes(): BelongsToMany
    {
        return $this->postTypes()
            ->where('post_type_taxonomies.status', true)
            ->where('post_types.status', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Creator
    |--------------------------------------------------------------------------
    */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isStandalone(): bool
    {
        return !$this->is_relationship;
    }

    public function isParentTaxonomy(): bool
    {
        return $this->is_relationship && $this->is_parent && is_null($this->parent_id);
    }

    public function isChildTaxonomy(): bool
    {
        return $this->is_relationship && !$this->is_parent && !is_null($this->parent_id);
    }

    public function canHaveChildren(): bool
    {
        return $this->is_relationship && $this->is_parent;
    }

    public function hierarchyType(): string
    {
        if (!$this->is_relationship) {
            return 'standalone';
        }

        return $this->is_parent ? 'parent' : 'child';
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

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
            ->where('is_parent', true)
            ->whereNull('parent_id');
    }

    public function scopeChildren(Builder $query): Builder
    {
        return $query->where('is_relationship', true)
            ->where('is_parent', false)
            ->whereNotNull('parent_id');
    }

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeByParent(Builder $query, int $parentId): Builder
    {
        return $query->where('parent_id', $parentId);
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
}