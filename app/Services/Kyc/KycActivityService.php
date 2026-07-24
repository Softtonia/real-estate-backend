<?php

namespace App\Services\Kyc;

use App\Models\KycActivity;
use App\Models\KycRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class KycActivityService
{
    public function record(
        ?KycRequest $kycRequest,
        ?User $user,
        ?User $performedBy,
        string $action,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $remarks = null,
        array $metadata = [],
        ?Request $request = null
    ): ?KycActivity {
        if (!Schema::hasTable('kyc_activities')) {
            return null;
        }

        return KycActivity::query()->create([
            'kyc_request_id' => $kycRequest?->id,
            'user_id' => $user?->id ?? $kycRequest?->user_id,
            'performed_by' => $performedBy?->id,
            'action' => $action,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'remarks' => $remarks,
            'metadata' => !empty($metadata) ? $metadata : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            'created_at' => now(),
        ]);
    }

    public function draftCreated(
        KycRequest $kycRequest,
        ?User $performedBy,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_DRAFT_CREATED,
            oldStatus: null,
            newStatus: $kycRequest->status,
            remarks: 'KYC draft created.',
            request: $request
        );
    }

    public function submitted(
        KycRequest $kycRequest,
        ?User $performedBy,
        ?string $remarks = null,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_SUBMITTED,
            oldStatus: KycRequest::STATUS_DRAFT,
            newStatus: $kycRequest->status,
            remarks: $remarks ?: 'KYC submitted successfully.',
            request: $request
        );
    }

    public function resubmitted(
        KycRequest $kycRequest,
        ?User $performedBy,
        ?string $remarks = null,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_RESUBMITTED,
            oldStatus: KycRequest::STATUS_REJECTED,
            newStatus: $kycRequest->status,
            remarks: $remarks ?: 'KYC resubmitted successfully.',
            request: $request
        );
    }

    public function verificationStarted(
        KycRequest $kycRequest,
        ?User $performedBy,
        ?string $remarks = null,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_VERIFICATION_STARTED,
            oldStatus: KycRequest::STATUS_SUBMITTED,
            newStatus: KycRequest::STATUS_UNDER_REVIEW,
            remarks: $remarks ?: 'KYC verification started.',
            request: $request
        );
    }

    public function approved(
        KycRequest $kycRequest,
        ?User $performedBy,
        ?string $remarks = null,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_APPROVED,
            oldStatus: KycRequest::STATUS_UNDER_REVIEW,
            newStatus: KycRequest::STATUS_APPROVED,
            remarks: $remarks ?: 'KYC approved.',
            request: $request
        );
    }

    public function rejected(
        KycRequest $kycRequest,
        ?User $performedBy,
        string $reason,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_REJECTED,
            oldStatus: KycRequest::STATUS_UNDER_REVIEW,
            newStatus: KycRequest::STATUS_REJECTED,
            remarks: $reason,
            request: $request
        );
    }

    public function documentUploaded(
        KycRequest $kycRequest,
        ?User $performedBy,
        string $documentType,
        int $documentId,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_DOCUMENT_UPLOADED,
            oldStatus: null,
            newStatus: $kycRequest->status,
            remarks: 'KYC document uploaded: ' . $documentType,
            metadata: [
                'document_id' => $documentId,
                'document_type' => $documentType,
            ],
            request: $request
        );
    }

    public function documentApproved(
        KycRequest $kycRequest,
        ?User $performedBy,
        string $documentType,
        int $documentId,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_DOCUMENT_APPROVED,
            remarks: 'KYC document approved: ' . $documentType,
            metadata: [
                'document_id' => $documentId,
                'document_type' => $documentType,
            ],
            request: $request
        );
    }

    public function documentRejected(
        KycRequest $kycRequest,
        ?User $performedBy,
        string $documentType,
        int $documentId,
        string $reason,
        ?Request $request = null
    ): ?KycActivity {
        return $this->record(
            kycRequest: $kycRequest,
            user: $kycRequest->user,
            performedBy: $performedBy,
            action: KycActivity::ACTION_DOCUMENT_REJECTED,
            remarks: $reason,
            metadata: [
                'document_id' => $documentId,
                'document_type' => $documentType,
            ],
            request: $request
        );
    }
}