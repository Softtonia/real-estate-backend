<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldGroupLocationRule extends Model {
    protected $fillable = [
        'custom_field_group_id',
        'show_if',
        'match_type',
        'post_type_id',
        'taxonomy_id',
        'taxonomy_term_ids',
        'sort_order',
        'status'
    ];

    protected $casts = [
        'taxonomy_term_ids' => 'array',
        'status' => 'boolean',
    ];

    public function postType() {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function taxonomy() {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    public function group() {
        return $this->belongsTo(CustomFieldGroup::class, 'custom_field_group_id');
    }
}