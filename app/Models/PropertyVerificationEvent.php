<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyVerificationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dynamic_post_id',
        'revision_id',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'message',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PropertyListingRevision::class, 'revision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
