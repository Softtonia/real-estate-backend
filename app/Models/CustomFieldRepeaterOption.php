<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldRepeaterOption extends Model
{
    protected $fillable = [
        'custom_field_repeater_id',
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

    public function repeater()
    {
        return $this->belongsTo(CustomFieldRepeater::class, 'custom_field_repeater_id');
    }
}