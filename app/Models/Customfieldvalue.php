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

    public function getValueAttribute()
    {
        if (!is_null($this->value_json)) {
            return $this->value_json;
        }

        if (!is_null($this->value_datetime)) {
            return $this->value_datetime;
        }

        if (!is_null($this->value_date)) {
            return $this->value_date;
        }

        if (!is_null($this->value_number)) {
            return $this->value_number;
        }

        if (!is_null($this->value_string)) {
            return $this->value_string;
        }

        return $this->value_text;
    }
}