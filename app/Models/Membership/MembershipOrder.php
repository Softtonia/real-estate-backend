<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MembershipOrder extends Model
{
    use HasFactory;

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

    protected $fillable = [
        'user_id',
        'plan_id',
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
        'expires_at',
        'paid_at',
        'cancelled_at',
        'created_by',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gst_percentage' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(MembershipCoupon::class, 'coupon_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function membership(): HasOne
    {
        return $this->hasOne(UserMembership::class, 'order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class, 'membership_order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(MembershipInvoice::class, 'membership_order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(MembershipRefund::class, 'membership_order_id');
    }

    public function couponUsages(): HasMany
    {
        return $this->hasMany(MembershipCouponUsage::class, 'membership_order_id');
    }

    public function amountInPaise(): int
    {
        return (int) round(((float) $this->total_amount) * 100);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_PAID);
    }

    public function scopePending($query)
    {
        return $query->where('payment_status', self::PAYMENT_PENDING);
    }
}