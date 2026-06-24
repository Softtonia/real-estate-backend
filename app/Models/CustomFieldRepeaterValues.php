<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldRepeaterValues extends Model
{
    use HasFactory;

    protected $table = 'custom_field_repeater_values';

    protected $primaryKey = 'custom_repeater_value_id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'entity_id' => 'integer',
        'custom_field_id' => 'integer',
        'custom_field_repeater_id' => 'integer',
        'custom_field_repeater_options_id' => 'integer',
        'row_index' => 'integer',
        'repeater_index' => 'integer',
        'sort_order' => 'integer',
    ];

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function repeater()
    {
        return $this->belongsTo(CustomFieldRepeater::class, 'custom_field_repeater_id');
    }

    public function repeaterOption()
    {
        return $this->belongsTo(
            CustomFieldRepeaterOption::class,
            'custom_field_repeater_options_id'
        );
    }

    public function dynamicPost()
    {
        return $this->belongsTo(DynamicPost::class, 'entity_id')
            ->where('entity_type', 'post');
    }

    public function scopeForEntity($query, string $entityType, int $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    public function scopeForPost($query, int $postId)
    {
        return $query->where('entity_type', 'post')
            ->where('entity_id', $postId);
    }

    public function scopeForCustomField($query, int $customFieldId)
    {
        return $query->where('custom_field_id', $customFieldId);
    }
}