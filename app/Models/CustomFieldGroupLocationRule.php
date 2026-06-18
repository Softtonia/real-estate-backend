<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldGroupLocationRule extends Model
{
    protected $fillable = [
        'custom_field_group_id',
        'custom_field_id',
        'logic_operator',
        'rule_group',
        'show_if',
        'operator',
        'match_type',
        'post_type_id',
        'taxonomy_id',
        'taxonomy_term_ids',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'taxonomy_term_ids' => 'array',
        'status' => 'boolean',
        'rule_group' => 'integer',
        'sort_order' => 'integer',
    ];

    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    public function group()
    {
        return $this->belongsTo(CustomFieldGroup::class, 'custom_field_group_id');
    }

    public function field()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}