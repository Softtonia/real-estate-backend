<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldRepeaterValues extends Model
{
    use HasFactory;

    protected $table = 'custom_field_repeater_values';

    // Agar tumhari table ka primary key custom_repeater_value_id hai
    protected $primaryKey = 'custom_repeater_value_id';

    protected $guarded = [];

    protected $casts = [
        'entity_id' => 'integer',
        'custom_field_id' => 'integer',
        'custom_field_repeater_id' => 'integer',
        'row_index' => 'integer',
        'repeater_index' => 'integer',
    ];

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function repeater()
    {
        return $this->belongsTo(CustomFieldRepeater::class, 'custom_field_repeater_id');
    }

    public function dynamicPost()
    {
        return $this->belongsTo(DynamicPost::class, 'entity_id')
            ->where('entity_type', 'post');
    }

    public function customFieldRepeaterOption()
    {
        return $this->belongsTo(
            CustomFieldRepeaterOption::class,
            'custom_field_repeater_options_id'
        );
    }
}