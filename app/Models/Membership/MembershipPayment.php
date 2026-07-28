<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPayment extends Model
{
    use HasFactory;

    public const STATUS_CREATED = 'created';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'membership_order_id',
        'addon_order_id',
        'user_id',
        'payment_gateway',
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'currency',
        'amount',
        'payment_status',
        'payment_method',
        'payment_date',
        'verified_at',
        'failure_reason',
        'gateway_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'verified_at' => 'datetime',
        'gateway_response' => 'array',
    ];

    public function membershipOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipOrder::class, 'membership_order_id');
    }

    public function addonOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipAddonOrder::class, 'addon_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(MembershipRefund::class, 'payment_id');
    }

    public function scopeCaptured($query)
    {
        return $query->where('payment_status', self::STATUS_CAPTURED);
    }
}