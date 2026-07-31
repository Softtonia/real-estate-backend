<?php

namespace App\Models\Concerns;

use App\Models\PropertyListingRevision;
use App\Models\PropertyVerificationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasPropertyVerificationWorkflow
{
    public function propertyRevisions(): HasMany
    {
        return $this->hasMany(
            PropertyListingRevision::class,
            'dynamic_post_id'
        )->orderByDesc('version');
    }

    public function latestPropertyRevision(): HasOne
    {
        return $this->hasOne(
            PropertyListingRevision::class,
            'dynamic_post_id'
        )->latestOfMany('version');
    }

    public function verificationTimeline(): HasMany
    {
        return $this->hasMany(
            PropertyVerificationEvent::class,
            'dynamic_post_id'
        )->orderBy('created_at');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('live_status', 'approve');
    }
}
