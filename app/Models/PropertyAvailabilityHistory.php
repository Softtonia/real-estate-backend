<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyAvailabilityHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'dynamic_post_id',
        'revision_id',
        'event',
        'from_status',
        'to_status',
        'changed_by',
        'actor_context',
        'notes',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(
            DynamicPost::class,
            'dynamic_post_id'
        );
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            PropertyListingRevision::class,
            'revision_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'changed_by'
        );
    }
}
