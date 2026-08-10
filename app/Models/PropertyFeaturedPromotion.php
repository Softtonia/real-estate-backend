<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyFeaturedPromotion extends Model
{
    use HasFactory;

    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_MEMBERSHIP = 'membership';

    public const SOURCES = [
        self::SOURCE_ADMIN,
        self::SOURCE_MEMBERSHIP,
    ];

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'property_featured_promotions';

    protected $fillable = [
        'dynamic_post_id',
        'source',
        'status',
        'starts_at',
        'ends_at',
        'priority',
        'admin_notes',
        'created_by',
        'updated_by',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'dynamic_post_id' => 'integer',
        'priority' => 'integer',

        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',

        'created_by' => 'integer',
        'updated_by' => 'integer',
        'cancelled_by' => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(
            DynamicPost::class,
            'dynamic_post_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by'
        );
    }

    public function scopeAdminSource(
        Builder $query
    ): Builder {
        return $query->where(
            'source',
            self::SOURCE_ADMIN
        );
    }

    public function scopeMembershipSource(
        Builder $query
    ): Builder {
        return $query->where(
            'source',
            self::SOURCE_MEMBERSHIP
        );
    }

    public function scopeScheduled(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_SCHEDULED
        );
    }

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_ACTIVE
        );
    }

    public function scopeExpired(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_EXPIRED
        );
    }

    public function scopeCancelled(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_CANCELLED
        );
    }

    public function scopeCurrentlyFeatured(
        Builder $query
    ): Builder {
        return $query
            ->where(
                'status',
                self::STATUS_ACTIVE
            )
            ->where(
                'starts_at',
                '<=',
                now()
            )
            ->where(
                'ends_at',
                '>',
                now()
            );
    }

    public function scopeOpenPromotion(
        Builder $query
    ): Builder {
        return $query
            ->whereIn('status', [
                self::STATUS_SCHEDULED,
                self::STATUS_ACTIVE,
            ])
            ->where(
                'ends_at',
                '>',
                now()
            );
    }

    public function scopeForProperty(
        Builder $query,
        int $dynamicPostId
    ): Builder {
        return $query->where(
            'dynamic_post_id',
            $dynamicPostId
        );
    }

    public function scopeFeaturedOrder(
        Builder $query
    ): Builder {
        return $query
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }

    public function isCurrentlyFeatured(): bool
    {
        if (
            $this->status !== self::STATUS_ACTIVE
        ) {
            return false;
        }

        if (
            !$this->starts_at
            || !$this->ends_at
        ) {
            return false;
        }

        return $this->starts_at->lte(now())
            && $this->ends_at->gt(now());
    }

    public function isScheduled(): bool
    {
        return $this->status
            === self::STATUS_SCHEDULED;
    }

    public function isActive(): bool
    {
        return $this->status
            === self::STATUS_ACTIVE;
    }

    public function isExpired(): bool
    {
        return $this->status
            === self::STATUS_EXPIRED
            || (
                $this->ends_at
                && $this->ends_at->lte(now())
            );
    }

    public function isCancelled(): bool
    {
        return $this->status
            === self::STATUS_CANCELLED;
    }
}