<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomFieldRepeater extends Model
{
    protected $fillable = [
        'custom_field_id',
        'field_label',
        'field_name_slug',
        'field_placeholder',
        'field_type',
        'media_limit',
        'media_size',
        'media_format',
        'has_featured',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'media_limit' => 'integer',
        'has_featured' => 'boolean',
        'status' => 'boolean',
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

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
    public function values()
    {
        return $this->hasMany(CustomFieldRepeaterValue::class, 'custom_field_repeater_id');
    }
    public function options()
    {
        return $this->hasMany(CustomFieldRepeaterOption::class, 'custom_field_repeater_id')
            ->orderBy('sort_order');
    }

    public function activeOptions()
    {
        return $this->hasMany(CustomFieldRepeaterOption::class, 'custom_field_repeater_id')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function hasOptions(): bool
    {
        return in_array($this->field_type, ['select', 'radio', 'checkbox']);
    }

    public function isMediaType(): bool
    {
        return in_array($this->field_type, ['media', 'file']);
    }
}
