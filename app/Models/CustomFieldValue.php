<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldValue extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'custom_field_id',
        'custom_field_option_id',
        'value_text',
        'value_string',
        'value_number',
        'value_date',
        'value_datetime',
        'value_json',
    ];

    protected $casts = [
        'value_number' => 'decimal:2',
        'value_date' => 'date',
        'value_datetime' => 'datetime',
        'value_json' => 'array',
    ];

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function option()
    {
        return $this->belongsTo(CustomFieldOption::class, 'custom_field_option_id');
    }

    public function customFieldOption()
    {
        return $this->belongsTo(CustomFieldOption::class, 'custom_field_option_id');
    }

    public function post()
    {
        return $this->belongsTo(DynamicPost::class, 'entity_id')
                    ->where('entity_type', 'post');
    }

    public function taxonomyTerm()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'entity_id')
                    ->where('entity_type', 'taxonomy_term');
    }

    public function scopeForPost($query, $postId)
    {
        return $query->where('entity_type', 'post')
                     ->where('entity_id', $postId);
    }

    public function scopeForTaxonomyTerm($query, $termId)
    {
        return $query->where('entity_type', 'taxonomy_term')
                     ->where('entity_id', $termId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('entity_type', 'user')
                     ->where('entity_id', $userId);
    }

    // Raw value access
    public function getValueAttribute()
    {
        return $this->value_json ?? $this->value_datetime ?? $this->value_date
             ?? $this->value_number ?? $this->value_string ?? $this->value_text;
    }

    // Formatted value by type
    public function getFormattedValueAttribute()
    {
        $field = $this->customField;
        if (!$field) return $this->value;

        switch ($field->field_type) {
            case 'boolean':
                $raw = $this->value_json ?? $this->value_string ?? $this->value;
                return is_bool($raw) ? $raw : in_array((string)$raw, ['1','true','yes'], true);
            case 'number':
                $val = $this->value_json ?? $this->value_number;
                return is_numeric($val) ? (str_contains((string)$val,'.') ? (float)$val : (int)$val) : $val;
            case 'checkbox':
                if (is_array($this->value_json)) return $this->value_json;
                $optionIds = array_filter(explode(',', $this->value_string ?? ''));
                return array_map(fn($id) => ['custom_field_option_id'=>(int)$id], $optionIds);
            case 'repeater':
            case 'media':
            case 'file':
                return $this->value_json ?? [];
            case 'date': return $this->value_date ?? $this->value_string;
            case 'datetime': return $this->value_datetime ?? $this->value_string;
            case 'select':
            case 'radio': return $this->value_string;
            default: return $this->value;
        }
    }

    public function toApiArray(): array
    {
        $field = $this->customField;
        return [
            'custom_field_id' => $field?->id ?? $this->custom_field_id,
            'field_label' => $field?->field_label ?? null,
            'field_name_slug' => $field?->field_name_slug ?? null,
            'field_type' => $field?->field_type ?? null,
            'field_placeholder' => $field?->field_placeholder ?? null,
            'value' => $this->formatted_value,
        ];
    }
}