<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class KycDocument extends Model
{
    public const TYPE_AADHAAR_FRONT = 'aadhaar_front';
    public const TYPE_AADHAAR_BACK = 'aadhaar_back';
    public const TYPE_GST_CERTIFICATE = 'gst_certificate';
    public const TYPE_RERA_CERTIFICATE = 'rera_certificate';
    public const TYPE_BUSINESS_PROOF = 'business_proof';
    public const TYPE_OTHER = 'other';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'kyc_request_id',
        'user_id',
        'document_type',
        'document_number',
        'file_disk',
        'file_path',
        'file_original_name',
        'mime_type',
        'file_size',
        'status',
        'rejection_reason',
        'uploaded_by',
        'reviewed_by',
        'uploaded_at',
        'reviewed_at',
        'version',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'version' => 'integer',
        'uploaded_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function documentTypes(): array
    {
        return [
            self::TYPE_AADHAAR_FRONT,
            self::TYPE_AADHAAR_BACK,
            self::TYPE_GST_CERTIFICATE,
            self::TYPE_RERA_CERTIFICATE,
            self::TYPE_BUSINESS_PROOF,
            self::TYPE_OTHER,
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function fileExists(): bool
    {
        return !empty($this->file_path)
            && Storage::disk($this->file_disk)->exists($this->file_path);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}