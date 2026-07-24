<?php

namespace App\Services\Kyc;

use App\Models\KycRequest;
use App\Models\KycRoleRule;
use App\Models\KycUserExemption;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class KycAccessService
{
    public function userKycStatus(User $user): array
    {
        return Cache::store('redis')->remember(
            $this->cacheKey($user),
            now()->addMinutes(10),
            function () use ($user) {
                $role = $this->resolveUserRole($user);
                $roleName = $this->normalizeRoleName($role?->name);

                $roleRule = $this->resolveRoleRule($user, $role, $roleName);
                $activeExemption = $this->activeUserExemption($user);
                $latestKycRequest = $this->latestKycRequest($user);
                $approvedKycRequest = $this->approvedKycRequest($user);

                $isOwner = $this->isOwnerRole($roleName);

                $requiresKyc = $roleRule
                    ? $roleRule->requiresKyc()
                    : !$isOwner;

                $roleCanPublishWithoutKyc = $roleRule
                    ? $roleRule->canPublishWithoutKyc()
                    : $isOwner;

                $hasUserExemption = $activeExemption !== null;
                $hasApprovedKyc = $approvedKycRequest !== null || (int) ($user->kyc ?? 0) === 2;

                $canPublishWithoutKyc = $isOwner
                    || $roleCanPublishWithoutKyc
                    || $hasUserExemption;

                $canPublish = !$requiresKyc
                    || $canPublishWithoutKyc
                    || $hasApprovedKyc;

                return [
                    'user_id' => (int) $user->id,
                    'role_id' => $user->role_id,
                    'role_name' => $role?->name,

                    'is_owner' => $isOwner,
                    'requires_kyc' => $requiresKyc,
                    'can_publish_without_kyc' => $canPublishWithoutKyc,
                    'has_user_exemption' => $hasUserExemption,
                    'has_approved_kyc' => $hasApprovedKyc,
                    'can_publish_listing' => $canPublish,

                    'user_kyc_value' => (int) ($user->kyc ?? 0),
                    'user_kyc_label' => $this->legacyKycLabel((int) ($user->kyc ?? 0)),

                    'latest_kyc_request' => $latestKycRequest ? [
                        'id' => (int) $latestKycRequest->id,
                        'status' => $latestKycRequest->status,
                        'submitted_at' => optional($latestKycRequest->submitted_at)->toDateTimeString(),
                        'reviewed_at' => optional($latestKycRequest->reviewed_at)->toDateTimeString(),
                        'rejection_reason' => $latestKycRequest->rejection_reason,
                    ] : null,

                    'approved_kyc_request_id' => $approvedKycRequest?->id,

                    'exemption' => $activeExemption ? [
                        'id' => (int) $activeExemption->id,
                        'reason' => $activeExemption->reason,
                        'expires_at' => optional($activeExemption->expires_at)->toDateTimeString(),
                    ] : null,

                    'required_documents' => $roleRule?->required_documents ?? $this->defaultRequiredDocuments($roleName),

                    'message' => $this->publishMessage(
                        canPublish: $canPublish,
                        requiresKyc: $requiresKyc,
                        canPublishWithoutKyc: $canPublishWithoutKyc,
                        hasApprovedKyc: $hasApprovedKyc
                    ),
                ];
            }
        );
    }

    public function canPublishListing(User $user): bool
    {
        return (bool) $this->userKycStatus($user)['can_publish_listing'];
    }

    public function cannotPublishReason(User $user): ?string
    {
        $status = $this->userKycStatus($user);

        if ($status['can_publish_listing']) {
            return null;
        }

        return $status['message'];
    }

    public function forgetUserCache(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        Cache::store('redis')->forget('kyc:user:' . $userId . ':access');
    }

    private function cacheKey(User $user): string
    {
        return 'kyc:user:' . $user->id . ':access';
    }

    private function resolveUserRole(User $user): ?Role
    {
        if (empty($user->role_id) || !Schema::hasTable('roles')) {
            return null;
        }

        return Role::query()
            ->select(['id', 'name'])
            ->where('id', $user->role_id)
            ->first();
    }

    private function resolveRoleRule(User $user, ?Role $role, string $roleName): ?KycRoleRule
    {
        if ($role && Schema::hasTable('kyc_role_rules')) {
            $rule = KycRoleRule::query()
                ->where('role_id', $role->id)
                ->where('is_active', true)
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        return null;
    }

    private function activeUserExemption(User $user): ?KycUserExemption
    {
        if (!Schema::hasTable('kyc_user_exemptions')) {
            return null;
        }

        return KycUserExemption::query()
            ->active()
            ->where('user_id', $user->id)
            ->first();
    }

    private function latestKycRequest(User $user): ?KycRequest
    {
        if (!Schema::hasTable('kyc_requests')) {
            return null;
        }

        return KycRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function approvedKycRequest(User $user): ?KycRequest
    {
        if (!Schema::hasTable('kyc_requests')) {
            return null;
        }

        return KycRequest::query()
            ->where('user_id', $user->id)
            ->where('status', KycRequest::STATUS_APPROVED)
            ->latest('id')
            ->first();
    }

    private function normalizeRoleName(?string $roleName): string
    {
        $roleName = strtolower(trim((string) $roleName));

        return str_replace([' ', '_', '-'], '', $roleName);
    }

    private function isOwnerRole(string $roleName): bool
    {
        return in_array($roleName, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }

    private function defaultRequiredDocuments(string $roleName): array
    {
        return KycRoleRule::defaultRequiredDocumentsForRoleName($roleName);
    }

    private function legacyKycLabel(int $status): string
    {
        return match ($status) {
            0 => 'Draft / Pending',
            1 => 'Submitted / Under Review',
            2 => 'Approved',
            3 => 'Rejected',
            default => 'Pending',
        };
    }

    private function publishMessage(
        bool $canPublish,
        bool $requiresKyc,
        bool $canPublishWithoutKyc,
        bool $hasApprovedKyc
    ): string {
        if ($canPublish && $canPublishWithoutKyc) {
            return 'User can publish listing without approved KYC.';
        }

        if ($canPublish && $hasApprovedKyc) {
            return 'User can publish listing because KYC is approved.';
        }

        if (!$requiresKyc) {
            return 'KYC is not required for this user role.';
        }

        return 'KYC approval is required before publishing listing.';
    }
}