<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DynamicPostFormStepField extends Model
{
    protected $fillable = [
        'post_type_id',
        'dynamic_post_form_step_id',
        'custom_field_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'post_type_id' => 'integer',
        'dynamic_post_form_step_id' => 'integer',
        'custom_field_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function step()
    {
        return $this->belongsTo(DynamicPostFormStep::class, 'dynamic_post_form_step_id');
    }

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}