<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyListingRevision extends Model
{
    protected $table = 'property_listing_revisions';

    protected $guarded = [];
    protected $casts = [
        'version' => 'integer',
        'baseline_payload' => 'array',
        'submitted_payload' => 'array',
        'submitted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'verification_started_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function assignedVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PropertyVerificationEvent::class, 'revision_id')
            ->orderBy('created_at');
    }
}
