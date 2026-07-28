<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipAddonUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'addon_id',
        'addon_order_id',
        'membership_id',
        'usage_type',
        'reference_type',
        'reference_id',
        'quantity',
        'used_at',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(MembershipAddon::class, 'addon_id');
    }

    public function addonOrder(): BelongsTo
    {
        return $this->belongsTo(MembershipAddonOrder::class, 'addon_order_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'membership_id');
    }
}