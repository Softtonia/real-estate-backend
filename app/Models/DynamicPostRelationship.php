<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DynamicPostRelationship extends Model
{
    use HasFactory;

    protected $table = 'dynamic_post_relationships';

    protected $fillable = [
        'dynamic_post_id',
        'related_post_type_id',
        'related_post_id',
    ];

    protected $casts = [
        'dynamic_post_id' => 'integer',
        'related_post_type_id' => 'integer',
        'related_post_id' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function relatedPostType()
    {
        return $this->belongsTo(PostType::class, 'related_post_type_id');
    }

    public function relatedPost()
    {
        return $this->belongsTo(DynamicPost::class, 'related_post_id');
    }
}
