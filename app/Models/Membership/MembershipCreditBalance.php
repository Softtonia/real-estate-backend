<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipCreditBalance extends Model
{
    use HasFactory;

    public const TYPE_LISTING = 'listing';
    public const TYPE_FEATURED_LISTING = 'featured_listing';
    public const TYPE_BOOST = 'boost';
    public const TYPE_LEAD_VIEW = 'lead_view';
    public const TYPE_VIDEO_UPLOAD = 'video_upload';
    public const TYPE_VIRTUAL_TOUR = 'virtual_tour';
    public const TYPE_AI_DESCRIPTION = 'ai_description';

    protected $fillable = [
        'user_id',
        'membership_id',
        'credit_type',
        'is_unlimited',
        'total_credits',
        'used_credits',
        'remaining_credits',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'is_unlimited' => 'boolean',
        'total_credits' => 'integer',
        'used_credits' => 'integer',
        'remaining_credits' => 'integer',
        'status' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'membership_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MembershipCreditTransaction::class, 'balance_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function hasAvailableCredits(int $quantity = 1): bool
    {
        if ($this->is_unlimited) {
            return true;
        }

        return (int) $this->remaining_credits >= $quantity;
    }
}