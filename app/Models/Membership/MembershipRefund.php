<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_order_id',
        'addon_order_id',
        'payment_id',
        'user_id',
        'refund_number',
        'payment_gateway',
        'gateway_refund_id',
        'currency',
        'refund_amount',
        'refund_status',
        'refund_reason',
        'processed_by',
        'processed_at',
        'gateway_response',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'gateway_response' => 'array',
    ];
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_MEMBERSHIP_ORDER = 'membership';
    public const TYPE_ADDON_ORDER = 'addon';
    public function membershipOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipOrder::class, 'membership_order_id');
    }

    public function addonOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipAddonOrder::class, 'addon_order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(MembershipPayment::class, 'payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
