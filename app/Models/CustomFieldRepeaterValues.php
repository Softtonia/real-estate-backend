<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldRepeaterValue extends Model
{
    use HasFactory;

    protected $table = 'custom_field_repeater_values';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'custom_field_id',
        'custom_field_repeater_id',
        'custom_field_repeater_option_id',
        'field_label',
        'field_name_slug',
        'field_type',
        'row_index',
        'sort_order',
        'unique_id',
        'field_meta_value',
        'value_string',
        'value_text',
        'value_number',
        'value_date',
        'value_datetime',
        'value_json',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'custom_field_id' => 'integer',
        'custom_field_repeater_id' => 'integer',
        'custom_field_repeater_option_id' => 'integer',
        'row_index' => 'integer',
        'sort_order' => 'integer',
        'value_number' => 'decimal:4',
        'value_date' => 'date',
        'value_datetime' => 'datetime',
        'value_json' => 'array',
    ];

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function repeater()
    {
        return $this->belongsTo(CustomFieldRepeater::class, 'custom_field_repeater_id');
    }

    public function option()
    {
        return $this->belongsTo(
            CustomFieldRepeaterOption::class,
            'custom_field_repeater_option_id'
        );
    }

    public function dynamicPost()
    {
        return $this->belongsTo(DynamicPost::class, 'entity_id')
            ->where('entity_type', 'post');
    }
}