<?php

namespace App\Models\Concerns;

use App\Enums\PropertyAvailabilityStatus;
use App\Models\PropertyAvailabilityHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasPropertyAvailability
{
    public function initializeHasPropertyAvailability(): void
    {
        $this->casts = array_merge($this->casts, [
            'availability_review_requested_at' => 'datetime',
            'availability_public_until' => 'datetime',
            'availability_hidden_at' => 'datetime',
            'availability_changed_at' => 'datetime',
            'sold_at' => 'datetime',
        ]);
    }

    public function availabilityChangedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'availability_changed_by'
        );
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'sold_by'
        );
    }

    public function availabilityHistories(): HasMany
    {
        return $this->hasMany(
            PropertyAvailabilityHistory::class,
            'dynamic_post_id'
        );
    }

    public function scopeAvailabilityStatus(
        Builder $query,
        string $status
    ): Builder {
        return $query->where(
            'dynamic_posts.availability_status',
            $status
        );
    }

    /**
     * Public marketplace visibility.
     *
     * available/reserved => visible when published + approved
     * sold              => visible until configured deadline
     * rented/off_market => hidden immediately
     */
    public function scopePubliclyVisible(
        Builder $query
    ): Builder {
        return $query
            ->where('dynamic_posts.status', 'published')
            ->where('dynamic_posts.live_status', 'approve')
            ->where(function (Builder $availabilityQuery) {
                $availabilityQuery
                    ->whereIn(
                        'dynamic_posts.availability_status',
                        [
                            PropertyAvailabilityStatus::AVAILABLE,
                            PropertyAvailabilityStatus::RESERVED,
                        ]
                    )
                    ->orWhere(function (Builder $soldQuery) {
                        $soldQuery
                            ->where(
                                'dynamic_posts.availability_status',
                                PropertyAvailabilityStatus::SOLD
                            )
                            ->whereNull(
                                'dynamic_posts.availability_hidden_at'
                            )
                            ->whereNotNull(
                                'dynamic_posts.availability_public_until'
                            )
                            ->where(
                                'dynamic_posts.availability_public_until',
                                '>',
                                now()
                            );
                    });
            });
    }
}
