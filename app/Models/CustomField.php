<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomField extends Model
{
    protected $fillable = [
        'group_id',
        'entity_type',
        'post_type_id',
        'taxonomy_id',
        'field_label',
        'field_name_slug',
        'field_placeholder',
        'field_type',
        'required',
        'checkbox_type',
        'default_value',
        'validation_rules',
        'conditional_rules',
        'template_id',
        'media_limit',
        'media_size',
        'media_format',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'validation_rules' => 'array',
        'conditional_rules' => 'array',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'media_limit' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($field) {
            if (empty($field->field_name_slug)) {
                $field->field_name_slug = Str::slug($field->field_label, '_');
            }
        });

        static::updating(function ($field) {
            if (empty($field->field_name_slug)) {
                $field->field_name_slug = Str::slug($field->field_label, '_');
            }
        });
    }

    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    public function options()
    {
        return $this->hasMany(CustomFieldOption::class, 'custom_field_id')
            ->orderBy('sort_order');
    }

    public function activeOptions()
    {
        return $this->hasMany(CustomFieldOption::class, 'custom_field_id')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function repeaters()
    {
        return $this->hasMany(CustomFieldRepeater::class, 'custom_field_id')
            ->orderBy('sort_order');
    }

    public function repeaterFields()
    {
        return $this->hasMany(CustomFieldRepeater::class, 'custom_field_id')
            ->orderBy('sort_order');
    }

    public function activeRepeaters()
    {
        return $this->hasMany(CustomFieldRepeater::class, 'custom_field_id')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function values()
    {
        return $this->hasMany(CustomFieldValue::class, 'custom_field_id');
    }

    public function conditions()
    {
        return $this->hasMany(CustomFieldCondition::class, 'custom_field_id');
    }

    public function groupname()
    {
        return $this->belongsTo(Groupname::class, 'group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeForPostType($query, $postTypeId)
    {
        return $query->where('entity_type', 'post')
            ->where('post_type_id', $postTypeId);
    }

    public function scopeForTaxonomy($query, $taxonomyId)
    {
        return $query->where('entity_type', 'taxonomy')
            ->where('taxonomy_id', $taxonomyId);
    }

    public function isRequired(): bool
    {
        return $this->required === 'yes';
    }

    public function hasOptions(): bool
    {
        return in_array($this->field_type, ['select', 'radio', 'checkbox']);
    }

    public function isRepeater(): bool
    {
        return $this->field_type === 'repeater';
    }

    public function isMediaType(): bool
    {
        return in_array($this->field_type, ['media', 'file']);
    }
        public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}