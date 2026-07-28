<?php

namespace App\Models\Membership;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipCoupon extends Model
{
    use HasFactory;

    public const DISCOUNT_FIXED = 'fixed';
    public const DISCOUNT_PERCENTAGE = 'percentage';

    protected $fillable = [
        'code',
        'title',
        'description',
        'discount_type',
        'discount_value',
        'minimum_order_amount',
        'maximum_discount_amount',
        'start_at',
        'end_at',
        'usage_limit',
        'usage_limit_per_user',
        'used_count',
        'allowed_plan_ids',
        'allowed_category_ids',
        'new_user_only',
        'status',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'minimum_order_amount' => 'decimal:2',
        'maximum_discount_amount' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'usage_limit' => 'integer',
        'usage_limit_per_user' => 'integer',
        'used_count' => 'integer',
        'allowed_plan_ids' => 'array',
        'allowed_category_ids' => 'array',
        'new_user_only' => 'boolean',
        'status' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(MembershipOrder::class, 'coupon_id');
    }

    public function addonOrders(): HasMany
    {
        return $this->hasMany(MembershipAddonOrder::class, 'coupon_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MembershipCouponUsage::class, 'coupon_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true)
            ->where(function ($q) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', now());
            });
    }

    public function isActive(): bool
    {
        if (!$this->status) {
            return false;
        }

        if ($this->start_at && $this->start_at->isFuture()) {
            return false;
        }

        if ($this->end_at && $this->end_at->isPast()) {
            return false;
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }
}