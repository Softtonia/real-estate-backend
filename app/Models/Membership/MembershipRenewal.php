<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipRenewal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'membership_id',
        'old_plan_id',
        'new_plan_id',
        'order_id',
        'renewal_date',
        'old_expiry_date',
        'new_expiry_date',
        'amount',
        'transaction_id',
        'metadata',
    ];

    protected $casts = [
        'renewal_date' => 'datetime',
        'old_expiry_date' => 'datetime',
        'new_expiry_date' => 'datetime',
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'membership_id');
    }

    public function oldPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'old_plan_id');
    }

    public function newPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'new_plan_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MembershipOrder::class, 'order_id');
    }
}