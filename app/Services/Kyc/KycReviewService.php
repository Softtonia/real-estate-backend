<?php

namespace App\Services\Kyc;

use App\Http\Requests\Kyc\KycReviewRequest;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class KycReviewService
{
    public function __construct(
        private readonly KycDocumentService $documentService,
        private readonly KycActivityService $activityService,
        private readonly KycAccessService $accessService
    ) {
    }

    public function handleReview(
        KycRequest $kycRequest,
        User $reviewer,
        KycReviewRequest $request
    ): KycRequest {
        return match ($request->input('action')) {
            'start_review' => $this->startReview(
                $kycRequest,
                $reviewer,
                $request
            ),

            'approve' => $this->approve(
                $kycRequest,
                $reviewer,
                $request
            ),

            'reject' => $this->reject(
                $kycRequest,
                $reviewer,
                $request
            ),

            default => throw ValidationException::withMessages([
                'action' => 'Invalid KYC review action.',
            ]),
        };
    }

    public function startReview(
        KycRequest $kycRequest,
        User $reviewer,
        KycReviewRequest $request
    ): KycRequest {
        $kycRequest = $this->freshRequest($kycRequest);

        if (
            !in_array(
                $kycRequest->status,
                [
                    KycRequest::STATUS_SUBMITTED,
                    KycRequest::STATUS_RESUBMITTED,
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted or resubmitted KYC can be moved to under review.',
            ]);
        }

        DB::transaction(
            function () use ($kycRequest, $reviewer, $request) {
                $oldStatus = $kycRequest->status;

                $kycRequest->update([
                    'status' => KycRequest::STATUS_UNDER_REVIEW,
                    'review_started_at' => now(),
                    'reviewed_by' => $reviewer->id,
                    'reviewer_notes' => $request->input(
                        'reviewer_notes'
                    ),
                ]);

                $this->activityService->record(
                    kycRequest: $kycRequest->fresh(['user']),
                    user: $kycRequest->user,
                    performedBy: $reviewer,
                    action: \App\Models\KycActivity::ACTION_VERIFICATION_STARTED,
                    oldStatus: $oldStatus,
                    newStatus: KycRequest::STATUS_UNDER_REVIEW,
                    remarks: $request->input('reviewer_notes')
                    ?: 'KYC verification started.',
                    request: $request
                );
            }
        );

        $this->clearKycCaches(
            $kycRequest->user
        );

        return $this->freshRequest(
            $kycRequest
        );
    }

    public function approve(
        KycRequest $kycRequest,
        User $reviewer,
        KycReviewRequest $request
    ): KycRequest {
        $kycRequest = $this->freshRequest(
            $kycRequest
        );

        if (!$kycRequest->canBeReviewed()) {
            throw ValidationException::withMessages([
                'status' => 'This KYC request cannot be approved from current status.',
            ]);
        }

        $this->applyDocumentReviews(
            $kycRequest,
            $reviewer,
            $request
        );

        $latestDocuments = $this
            ->documentService
            ->latestDocumentsForRequest(
                $kycRequest
            );

        if ($latestDocuments->isEmpty()) {
            throw ValidationException::withMessages([
                'documents' => 'At least one KYC document is required before approval.',
            ]);
        }

        $rejectedDocuments = $latestDocuments
            ->where(
                'status',
                KycDocument::STATUS_REJECTED
            )
            ->values();

        if ($rejectedDocuments->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' => 'KYC cannot be approved because one or more documents are rejected.',
            ]);
        }

        foreach ($latestDocuments as $document) {
            if (
                $document->status
                !== KycDocument::STATUS_APPROVED
            ) {
                $this->documentService->reviewDocument(
                    document: $document,
                    status: KycDocument::STATUS_APPROVED,
                    rejectionReason: null,
                    reviewer: $reviewer,
                    request: $request
                );
            }
        }

        DB::transaction(
            function () use ($kycRequest, $reviewer, $request) {
                $oldStatus = $kycRequest->status;

                $kycRequest->update([
                    'status' => KycRequest::STATUS_APPROVED,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'reviewed_ip' => $request->ip(),
                    'rejection_reason' => null,
                    'reviewer_notes' => $request->input(
                        'reviewer_notes'
                    ),
                ]);

                $this->syncLegacyKycMirror(
                    user: $kycRequest->user,
                    status: 2,
                    rejectionReason: null
                );

                $this->activityService->record(
                    kycRequest: $kycRequest->fresh(['user']),
                    user: $kycRequest->user,
                    performedBy: $reviewer,
                    action: \App\Models\KycActivity::ACTION_APPROVED,
                    oldStatus: $oldStatus,
                    newStatus: KycRequest::STATUS_APPROVED,
                    remarks: $request->input('reviewer_notes')
                    ?: 'KYC approved.',
                    request: $request
                );
            }
        );

        $this->clearKycCaches(
            $kycRequest->user
        );

        return $this->freshRequest(
            $kycRequest
        );
    }

    public function reject(
        KycRequest $kycRequest,
        User $reviewer,
        KycReviewRequest $request
    ): KycRequest {
        $kycRequest = $this->freshRequest(
            $kycRequest
        );

        if (!$kycRequest->canBeReviewed()) {
            throw ValidationException::withMessages([
                'status' => 'This KYC request cannot be rejected from current status.',
            ]);
        }

        $reason = trim(
            (string) $request->input(
                'rejection_reason'
            )
        );

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Rejection reason is required.',
            ]);
        }

        $providedDocuments = (array) $request->input('documents', []);

        if (!empty($providedDocuments)) {
            // Only update the documents explicitly selected/provided in the request
            $this->applyDocumentReviews(
                $kycRequest,
                $reviewer,
                $request
            );
        } else {
            // If no specific documents array was provided, reject all documents for this request
            $documents = KycDocument::query()
                ->where('kyc_request_id', $kycRequest->id)
                ->get();

            foreach ($documents as $document) {
                if ($document->status !== KycDocument::STATUS_REJECTED) {
                    $this->documentService->reviewDocument(
                        document: $document,
                        status: KycDocument::STATUS_REJECTED,
                        rejectionReason: $reason,
                        reviewer: $reviewer,
                        request: $request
                    );
                }
            }
        }

        DB::transaction(
            function () use ($kycRequest, $reviewer, $request, $reason) {
                $oldStatus = $kycRequest->status;

                $kycRequest->update([
                    'status' => KycRequest::STATUS_REJECTED,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'reviewed_ip' => $request->ip(),
                    'rejection_reason' => $reason,
                    'reviewer_notes' => $request->input(
                        'reviewer_notes'
                    ),
                ]);

                $this->syncLegacyKycMirror(
                    user: $kycRequest->user,
                    status: 3,
                    rejectionReason: $reason
                );

                $this->activityService->record(
                    kycRequest: $kycRequest->fresh(['user']),
                    user: $kycRequest->user,
                    performedBy: $reviewer,
                    action: \App\Models\KycActivity::ACTION_REJECTED,
                    oldStatus: $oldStatus,
                    newStatus: KycRequest::STATUS_REJECTED,
                    remarks: $reason,
                    request: $request
                );
            }
        );

        $this->clearKycCaches(
            $kycRequest->user
        );

        return $this->freshRequest(
            $kycRequest
        );
    }

    private function applyDocumentReviews(
        KycRequest $kycRequest,
        User $reviewer,
        KycReviewRequest $request
    ): void {
        $documents = (array) $request->input(
            'documents',
            []
        );

        if (empty($documents)) {
            return;
        }

        $globalRejectionReason = trim(
            (string) $request->input(
                'rejection_reason',
                ''
            )
        );

        foreach ($documents as $documentPayload) {
            $document = KycDocument::query()
                ->where(
                    'kyc_request_id',
                    $kycRequest->id
                )
                ->where(
                    'id',
                    $documentPayload['id']
                )
                ->first();

            if (!$document) {
                throw ValidationException::withMessages([
                    'documents' => 'Selected document does not belong to this KYC request.',
                ]);
            }

            $status = $documentPayload['status'];

            $documentReason = trim(
                (string) (
                    $documentPayload['rejection_reason']
                    ?? ''
                )
            );

            if (
                $status === KycDocument::STATUS_REJECTED
                && $documentReason === ''
            ) {
                $documentReason =
                    $globalRejectionReason;
            }

            $this->documentService->reviewDocument(
                document: $document,
                status: $status,
                rejectionReason: $documentReason !== ''
                ? $documentReason
                : null,
                reviewer: $reviewer,
                request: $request
            );
        }
    }

    private function syncLegacyKycMirror(
        User $user,
        int $status,
        ?string $rejectionReason = null
    ): void {
        $payload = [];

        if (
            Schema::hasColumn(
                'users',
                'kyc'
            )
        ) {
            $payload['kyc'] = $status;
        }

        if (
            Schema::hasColumn(
                'users',
                'reject_reason'
            )
        ) {
            $payload['reject_reason'] =
                $rejectionReason;
        }

        if (
            Schema::hasColumn(
                'users',
                'updated_at'
            )
        ) {
            $payload['updated_at'] =
                now();
        }

        if (!empty($payload)) {
            DB::table('users')
                ->where(
                    'id',
                    $user->id
                )
                ->update(
                    $payload
                );
        }
    }

    private function freshRequest(
        KycRequest $kycRequest
    ): KycRequest {
        return KycRequest::query()
            ->with([
                'user:id,first_name,last_name,email,phone,role_id,kyc,reject_reason',
                'role:id,name',
                'reviewer:id,first_name,last_name,email',
                'assignedVerifier:id,first_name,last_name,email',
                'assigner:id,first_name,last_name,email',
                'documents.reviewer:id,first_name,last_name,email',
                'documents.uploader:id,first_name,last_name,email',
                'activities.performer:id,first_name,last_name,email',
            ])
            ->findOrFail(
                $kycRequest->id
            );
    }

    private function clearKycCaches(
        ?User $user
    ): void {
        if (!$user) {
            return;
        }

        $this->accessService
            ->forgetUserCache(
                $user
            );

        Cache::store('redis')->forget(
            'kyc:user:' . $user->id . ':status'
        );

        Cache::store('redis')->forget(
            'kyc:admin:stats'
        );

        Cache::store('redis')->forget(
            'kyc:pending-count'
        );

        Cache::store('redis')->forget(
            'user_details_admin_' . $user->id
        );

        Cache::store('redis')->forget(
            'user_details_website_' . $user->id
        );

        if (!empty($user->api_token)) {
            Cache::store('redis')->forget(
                'user_by_token_'
                . md5($user->api_token)
            );
        }
    }
}