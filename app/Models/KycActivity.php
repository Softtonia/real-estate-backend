<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycActivity extends Model
{
    public const ACTION_DRAFT_CREATED = 'draft_created';
    public const ACTION_SUBMITTED = 'submitted';
    public const ACTION_VERIFICATION_STARTED = 'verification_started';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_RESUBMITTED = 'resubmitted';
    public const ACTION_DOCUMENT_UPLOADED = 'document_uploaded';
    public const ACTION_DOCUMENT_APPROVED = 'document_approved';
    public const ACTION_DOCUMENT_REJECTED = 'document_rejected';
    public const ACTION_EXEMPTION_CREATED = 'exemption_created';
    public const ACTION_EXEMPTION_REVOKED = 'exemption_revoked';
    public const ACTION_ASSIGNED = 'assigned';
    public const ACTION_UNASSIGNED = 'unassigned';
    public const ACTION_REASSIGNED = 'reassigned';

    public $timestamps = false;

    protected $fillable = [
        'kyc_request_id',
        'user_id',
        'performed_by',
        'action',
        'old_status',
        'new_status',
        'remarks',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public static function actions(): array
    {
        return [
            self::ACTION_DRAFT_CREATED,
            self::ACTION_SUBMITTED,
            self::ACTION_VERIFICATION_STARTED,
            self::ACTION_APPROVED,
            self::ACTION_REJECTED,
            self::ACTION_RESUBMITTED,
            self::ACTION_DOCUMENT_UPLOADED,
            self::ACTION_DOCUMENT_APPROVED,
            self::ACTION_DOCUMENT_REJECTED,
            self::ACTION_EXEMPTION_CREATED,
            self::ACTION_EXEMPTION_REVOKED,
            self::ACTION_ASSIGNED,
            self::ACTION_UNASSIGNED,
            self::ACTION_REASSIGNED,
        ];
    }

    public function kycRequest(): BelongsTo
    {
        return $this->belongsTo(KycRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForRequest(Builder $query, int $kycRequestId): Builder
    {
        return $query->where('kyc_request_id', $kycRequestId);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}