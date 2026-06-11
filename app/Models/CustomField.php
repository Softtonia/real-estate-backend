<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomField extends Model
{
    protected $fillable = [
        'custom_field_group_id',
        'field_label',
        'field_name_slug',
        'field_placeholder',
        'field_type',
        'required',
        'checkbox_type',
        'default_value',
        'validation_rules',
        'conditional_rules',
        'media_limit',
        'media_size',
        'media_format',
        'sort_order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'validation_rules' => 'array',
        'conditional_rules' => 'array',
        'sort_order' => 'integer',
        'media_limit' => 'integer',
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

    public function group()
    {
        return $this->belongsTo(CustomFieldGroup::class, 'custom_field_group_id');
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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
}