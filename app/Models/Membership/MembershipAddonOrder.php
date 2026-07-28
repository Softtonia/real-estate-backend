<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipAddonOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'addon_id',
        'membership_id',
        'coupon_id',
        'order_number',
        'gateway_name',
        'razorpay_order_id',
        'currency',
        'subtotal',
        'discount_amount',
        'taxable_amount',
        'gst_percentage',
        'gst_amount',
        'total_amount',
        'payment_status',
        'order_status',
        'payment_method',
        'paid_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gst_percentage' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(MembershipAddon::class, 'addon_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'membership_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(MembershipCoupon::class, 'coupon_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class, 'addon_order_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MembershipAddonUsage::class, 'addon_order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(MembershipRefund::class, 'addon_order_id');
    }

    public function amountInPaise(): int
    {
        return (int) round(((float) $this->total_amount) * 100);
    }
}
