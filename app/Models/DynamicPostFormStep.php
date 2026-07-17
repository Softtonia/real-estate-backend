<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DynamicPostFormStep extends Model
{
    protected $fillable = [
        'post_type_id',
        'step_key',
        'step_label',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'post_type_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function fields()
    {
        return $this->hasMany(DynamicPostFormStepField::class, 'dynamic_post_form_step_id');
    }

    public function activeFields()
    {
        return $this->hasMany(DynamicPostFormStepField::class, 'dynamic_post_form_step_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }
}