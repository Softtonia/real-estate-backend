<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipLeadUnlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'membership_id',
        'lead_reference_type',
        'lead_reference_id',
        'unlocked_at',
        'metadata',
    ];

    protected $casts = [
        'unlocked_at' => 'datetime',
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
}