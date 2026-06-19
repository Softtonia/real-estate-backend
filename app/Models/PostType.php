<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PostType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_default',
        'is_relationship',
        'status',
        'supports',
        'created_by',
        'sort_order',
        'menu_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_relationship' => 'boolean',
        'status' => 'boolean',
        'supports' => 'array',
        'sort_order' => 'integer',
        'menu_order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($postType) {
            if (empty($postType->slug)) {
                $postType->slug = self::generateUniqueSlug($postType->name);
            }
        });

        static::updating(function ($postType) {
            if (empty($postType->slug)) {
                $postType->slug = self::generateUniqueSlug($postType->name, $postType->id);
            }
        });
    }

    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);

        if (!$baseSlug) {
            $baseSlug = 'post-type';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            self::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, function ($query) use ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                })
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    public static function menuOrderExists(int $menuOrder, ?int $ignoreId = null): bool
    {
        return self::withTrashed()
            ->where('menu_order', $menuOrder)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->exists();
    }

    public function dynamicPosts()
    {
        return $this->hasMany(DynamicPost::class, 'post_type_id');
    }

    public function posts()
    {
        return $this->hasMany(DynamicPost::class, 'post_type_id');
    }

    public function customFields()
    {
        return $this->hasMany(CustomField::class, 'post_type_id')
            ->where('entity_type', 'post');
    }

    public function activeCustomFields()
    {
        return $this->hasMany(CustomField::class, 'post_type_id')
            ->where('entity_type', 'post')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function taxonomies()
    {
        return $this->belongsToMany(
            Taxonomy::class,
            'post_type_taxonomies',
            'post_type_id',
            'taxonomy_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps();
    }

    public function activeTaxonomies()
    {
        return $this->belongsToMany(
            Taxonomy::class,
            'post_type_taxonomies',
            'post_type_id',
            'taxonomy_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->wherePivot('status', true)
            ->where('taxonomies.status', true)
            ->orderBy('post_type_taxonomies.sort_order', 'asc')
            ->orderBy('taxonomies.id', 'asc')
            ->withTimestamps();
    }

    public function relatedPostTypes()
    {
        return $this->belongsToMany(
            self::class,
            'post_type_relationships',
            'post_type_id',
            'related_post_type_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps()
            ->orderBy('post_type_relationships.sort_order', 'asc')
            ->orderBy('post_types.id', 'asc');
    }

    public function activeRelatedPostTypes()
    {
        return $this->belongsToMany(
            self::class,
            'post_type_relationships',
            'post_type_id',
            'related_post_type_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->wherePivot('status', true)
            ->where('post_types.status', true)
            ->withTimestamps()
            ->orderBy('post_type_relationships.sort_order', 'asc')
            ->orderBy('post_types.id', 'asc');
    }

    public function relatedFromPostTypes()
    {
        return $this->belongsToMany(
            self::class,
            'post_type_relationships',
            'related_post_type_id',
            'post_type_id'
        )
            ->withPivot(['sort_order', 'status'])
            ->withTimestamps()
            ->orderBy('post_type_relationships.sort_order', 'asc')
            ->orderBy('post_types.id', 'asc');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', false);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_default', false);
    }

    public function scopeOrdered($query)
    {
        return $query
            ->orderByRaw('CASE WHEN menu_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('menu_order', 'asc')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'desc');
    }
}