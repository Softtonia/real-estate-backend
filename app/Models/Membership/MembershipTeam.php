<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'membership_id',
        'name',
        'slug',
        'max_members',
        'status',
        'metadata',
    ];

    protected $casts = [
        'max_members' => 'integer',
        'metadata' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'membership_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(MembershipTeamMember::class, 'team_id');
    }
}