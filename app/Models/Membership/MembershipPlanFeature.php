<?php

namespace App\Models\Membership;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPlanFeature extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'feature_id',
        'feature_value',
        'is_unlimited',
        'metadata',
    ];

    protected $casts = [
        'is_unlimited' => 'boolean',
        'metadata' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(MembershipFeature::class, 'feature_id');
    }
}