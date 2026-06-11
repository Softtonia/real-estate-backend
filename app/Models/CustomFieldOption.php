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
        'sort_order' => 'integer',
        'status' => 'boolean',
    ];

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}