<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DynamicPost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'post_type_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image_id',
        'status',
        'author_id',
        'parent_id',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($post) {
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
        });

        static::updating(function ($post) {
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
        });
    }

    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function parent()
    {
        return $this->belongsTo(DynamicPost::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(DynamicPost::class, 'parent_id');
    }

    public function meta()
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', 'post');
    }

    public function customFieldValues()
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', 'post');
    }

    public function taxonomyRelations()
    {
        return $this->hasMany(PostTaxonomyTerm::class, 'dynamic_post_id');
    }

    public function taxonomyTerms()
    {
        return $this->belongsToMany(
            TaxonomyTerm::class,
            'post_taxonomy_terms',
            'dynamic_post_id',
            'taxonomy_term_id'
        )->withPivot('taxonomy_id')->withTimestamps();
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByPostType($query, $postTypeId)
    {
        return $query->where('post_type_id', $postTypeId);
    }

    public function scopeByPostTypeSlug($query, string $slug)
    {
        return $query->whereHas('postType', function ($q) use ($slug) {
            $q->where('slug', $slug);
        });
    }
}