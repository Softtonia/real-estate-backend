<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycRoleRule extends Model
{
    protected $fillable = [
        'role_id',
        'requires_kyc',
        'can_publish_without_kyc',
        'required_documents',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'requires_kyc' => 'boolean',
        'can_publish_without_kyc' => 'boolean',
        'required_documents' => 'array',
        'is_active' => 'boolean',
    ];

    public static function defaultRequiredDocumentsForRoleName(?string $roleName): array
    {
        $role = strtolower(trim((string) $roleName));
        $role = str_replace([' ', '_', '-'], '', $role);

        if (in_array($role, ['owner', 'propertyowner', 'landowner'], true)) {
            return [
                KycDocument::TYPE_AADHAAR_FRONT,
                KycDocument::TYPE_AADHAAR_BACK,
            ];
        }

        if (in_array($role, ['agent', 'consultancy', 'company', 'developer', 'builder'], true)) {
            return [
                KycDocument::TYPE_AADHAAR_FRONT,
                KycDocument::TYPE_AADHAAR_BACK,
                KycDocument::TYPE_BUSINESS_PROOF,
            ];
        }

        return [
            KycDocument::TYPE_AADHAAR_FRONT,
            KycDocument::TYPE_AADHAAR_BACK,
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function requiresKyc(): bool
    {
        return $this->is_active && $this->requires_kyc;
    }

    public function canPublishWithoutKyc(): bool
    {
        return $this->is_active && $this->can_publish_without_kyc;
    }
}