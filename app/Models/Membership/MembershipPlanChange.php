<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPlanChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'membership_id',
        'old_plan_id',
        'new_plan_id',
        'order_id',
        'change_type',
        'prorated_amount',
        'effective_at',
        'metadata',
    ];

    protected $casts = [
        'prorated_amount' => 'decimal:2',
        'effective_at' => 'datetime',
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