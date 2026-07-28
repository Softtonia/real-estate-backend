<?php

namespace App\Models\Membership;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipCreditTransaction extends Model
{
    use HasFactory;

    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_REFUND = 'refund';
    public const TYPE_EXPIRE = 'expire';

    protected $fillable = [
        'user_id',
        'membership_id',
        'balance_id',
        'credit_type',
        'transaction_type',
        'quantity',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'reason',
        'performed_by',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
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

    public function balance(): BelongsTo
    {
        return $this->belongsTo(MembershipCreditBalance::class, 'balance_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}