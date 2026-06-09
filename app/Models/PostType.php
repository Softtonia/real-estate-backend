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
        'status',
        'supports',
        'created_by',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'status' => 'boolean',
        'supports' => 'array',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($postType) {
            if (empty($postType->slug)) {
                $postType->slug = Str::slug($postType->name);
            }
        });

        static::updating(function ($postType) {
            if (empty($postType->slug)) {
                $postType->slug = Str::slug($postType->name);
            }
        });
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
        return $query->orderBy('sort_order')->orderBy('id', 'desc');
    }

}