<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DynamicPost extends Model
{
    protected $fillable = [
        'post_type_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image_id',
        'gallery_image_ids',
        'status',
        'live_status',
        'author_id',
        'parent_id',
        'published_at',
        'sort_order',
    ];

    protected $casts = [
        'post_type_id' => 'integer',
        'featured_image_id' => 'integer',
        'gallery_image_ids' => 'array',
        'author_id' => 'integer',
        'parent_id' => 'integer',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
        'status' => 'string',
        'live_status' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(function ($post) {
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
            if (empty($post->status)) {
                $post->status = 'draft';
            }
        });

        static::updating(function ($post) {
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
        });
    }

    // Relations
    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
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
                    ->where('entity_type', 'post')
                    ->with(['customField.repeaters.options', 'repeaterValues']);
    }

    public function customFieldValues()
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
                    ->where('entity_type', 'post');
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

    public function taxonomies()
    {
        return $this->belongsToMany(
            Taxonomy::class,
            'post_taxonomy_terms',
            'dynamic_post_id',
            'taxonomy_id'
        )->withPivot('taxonomy_term_id')->withTimestamps();
    }

    // Scopes
    public function scopePublished($query) { return $query->where('status', 'published'); }
    public function scopeDraft($query) { return $query->where('status', 'draft'); }
    public function scopePrivate($query) { return $query->where('status', 'private'); }
    public function scopeArchived($query) { return $query->where('status', 'archived'); }
    public function scopeLiveStatus($query, string $status) { return $query->where('live_status', $status); }
    public function scopeApproved($query) { return $query->where('live_status', 'approve'); }
    public function scopeUnderReview($query) { return $query->where('live_status', 'under_review'); }
    public function scopeByPostType($query, $postTypeId) { return $query->where('post_type_id', $postTypeId); }
    public function scopeByPostTypeSlug($query, string $slug)
    {
        return $query->whereHas('postType', fn($q) => $q->where('slug', $slug));
    }

    // Helpers
    public function isPublished(): bool { return $this->status === 'published'; }
    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isApproved(): bool { return $this->live_status === 'approve'; }
    public function isUnderReview(): bool { return $this->live_status === 'under_review'; }

    // API formatting including repeaters
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'post_type_id' => $this->post_type_id,
            'custom_fields' => $this->customFieldValues->map(fn($m) => $m->toApiArray())->values(),
            'taxonomy_terms' => $this->taxonomyTerms->map(fn($t) => [
                'taxonomy_id' => $t->pivot->taxonomy_id,
                'taxonomy_term_id' => $t->id,
                'name' => $t->name,
            ])->values(),
        ];
    }
}