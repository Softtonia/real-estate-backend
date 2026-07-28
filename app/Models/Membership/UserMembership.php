<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserMembership extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_UPGRADED = 'upgraded';
    public const STATUS_DOWNGRADED = 'downgraded';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_GRACE_PERIOD = 'grace_period';

    protected $table = 'user_memberships';

    protected $fillable = [
        'user_id',
        'plan_id',
        'order_id',
        'parent_membership_id',
        'start_date',
        'expiry_date',
        'status',
        'auto_renew',
        'cancelled_at',
        'expired_at',
        'grace_until',
        'source',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'expiry_date' => 'datetime',
        'auto_renew' => 'boolean',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'grace_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MembershipOrder::class, 'order_id');
    }

    public function parentMembership(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_membership_id');
    }

    public function childMemberships(): HasMany
    {
        return $this->hasMany(self::class, 'parent_membership_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creditBalances(): HasMany
    {
        return $this->hasMany(MembershipCreditBalance::class, 'membership_id');
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(MembershipCreditTransaction::class, 'membership_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(MembershipRenewal::class, 'membership_id');
    }

    public function planChanges(): HasMany
    {
        return $this->hasMany(MembershipPlanChange::class, 'membership_id');
    }

    public function team(): HasOne
    {
        return $this->hasOne(MembershipTeam::class, 'membership_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>', now());
            });
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED)
            ->orWhere(function ($q) {
                $q->whereNotNull('expiry_date')
                    ->where('expiry_date', '<=', now());
            });
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && (!$this->expiry_date || $this->expiry_date->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expiry_date && $this->expiry_date->isPast());
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}