<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Connection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'requester_id','receiver_id','state','note','meta',
        'accepted_at','rejected_at','left_at','created_by','updated_by'
    ];

    protected $casts = [
        'meta' => 'array',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    // Eager-loaded relations
    public function requester()
    {
        return $this->belongsTo(\App\Models\User::class, 'requester_id');
    }

    public function receiver()
    {
        return $this->belongsTo(\App\Models\User::class, 'receiver_id');
    }

    // Scope: accepted connections involving $userId
    public function scopeAcceptedForUser(Builder $q, int $userId): Builder
    {
        return $q->where('state', 'accepted')
                 ->where(function ($s) use ($userId) {
                     $s->where('requester_id', $userId)
                       ->orWhere('receiver_id', $userId);
                 });
    }

    // Return the other party id for this connection relative to $userId
    public function otherUserId(int $userId): int
    {
        return $this->requester_id === $userId ? $this->receiver_id : $this->requester_id;
    }

    // Utility to fetch unique other user ids for a given user (efficient, two plucks)
    public static function otherUserIdsFor(int $userId)
    {
        $fromRequester = static::where('requester_id', $userId)->where('state', 'accepted')->pluck('receiver_id');
        $fromReceiver  = static::where('receiver_id', $userId)->where('state', 'accepted')->pluck('requester_id');

        return $fromRequester->merge($fromReceiver)->unique()->values();
    }
}
