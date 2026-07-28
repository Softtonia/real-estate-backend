<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipCouponUsage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'user_id',
        'membership_order_id',
        'addon_order_id',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(MembershipCoupon::class, 'coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function membershipOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipOrder::class, 'membership_order_id');
    }

    public function addonOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipAddonOrder::class, 'addon_order_id');
    }
}