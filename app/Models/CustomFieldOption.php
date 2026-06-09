<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldOption extends Model
{
    protected $fillable = [
        'custom_field_id',
        'type',
        'name',
        'value',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function values()
    {
        return $this->hasMany(CustomFieldValue::class, 'custom_field_option_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}