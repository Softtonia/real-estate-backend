<?php

namespace App\Services\Kyc;

use App\Http\Requests\Kyc\KycSubmitRequest;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\KycRoleRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class KycSubmissionService
{
    public function __construct(
        private readonly KycDocumentService $documentService,
        private readonly KycActivityService $activityService,
        private readonly KycAccessService $accessService
    ) {}

    public function submit(User $user, KycSubmitRequest $request): KycRequest
    {
        $latestRequest = $this->latestRequest($user);

        if ($latestRequest && $latestRequest->isApproved()) {
            throw ValidationException::withMessages([
                'kyc' => 'KYC is already approved.',
            ]);
        }

        if (
            $latestRequest
            && in_array($latestRequest->status, [
                KycRequest::STATUS_SUBMITTED,
                KycRequest::STATUS_UNDER_REVIEW,
                KycRequest::STATUS_RESUBMITTED,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'kyc' => 'Your KYC is already submitted and waiting for review.',
            ]);
        }

        $role = $this->resolveUserRole($user);
        $roleRule = $this->resolveRoleRule($user, $role);

        $requiresKyc = $this->requiresKyc($user, $role, $roleRule);
        $requiredDocuments = $this->requiredDocuments($role, $roleRule);

        $documentFiles = $this->normalizedDocumentFiles($request);

        $targetRequest = $this->prepareDraftRequest(
            user: $user,
            request: $request,
            latestRequest: $latestRequest,
            role: $role
        );

        $missingBeforeUpload = $this->missingRequiredDocumentsBeforeUpload(
            kycRequest: $targetRequest,
            requiredDocuments: $requiresKyc ? $requiredDocuments : [],
            incomingDocumentTypes: array_keys($documentFiles)
        );

        if (!empty($missingBeforeUpload)) {
            throw ValidationException::withMessages([
                'documents' => 'Missing required KYC documents: ' . implode(', ', $missingBeforeUpload),
                'missing_documents' => $missingBeforeUpload,
            ]);
        }

        foreach ($documentFiles as $documentType => $file) {
            $this->documentService->storeDocument(
                kycRequest: $targetRequest,
                user: $user,
                file: $file,
                documentType: $documentType,
                documentNumber: $this->documentNumberForType($documentType, $request),
                uploadedBy: $user,
                metadata: [
                    'source' => 'kyc_submit',
                ],
                request: $request
            );
        }

        $missingAfterUpload = $this->documentService->missingRequiredDocuments(
            $targetRequest,
            $requiresKyc ? $requiredDocuments : []
        );

        if (!empty($missingAfterUpload)) {
            throw ValidationException::withMessages([
                'documents' => 'Missing required KYC documents: ' . implode(', ', $missingAfterUpload),
                'missing_documents' => $missingAfterUpload,
            ]);
        }

        $isResubmission = $latestRequest && $latestRequest->isRejected();

        DB::transaction(function () use ($targetRequest, $request, $user, $isResubmission) {
            $oldStatus = $targetRequest->status;

            $targetRequest->update([
                'status' => $isResubmission
                    ? KycRequest::STATUS_RESUBMITTED
                    : KycRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_ip' => $request->ip(),
                'rejection_reason' => null,
                'reviewer_notes' => null,
                'review_started_at' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'reviewed_ip' => null,
            ]);

            $this->syncLegacyKycMirror($user, $request);

            if ($isResubmission) {
                $this->activityService->resubmitted(
                    kycRequest: $targetRequest->fresh(['user']),
                    performedBy: $user,
                    remarks: $request->input('remarks'),
                    request: $request
                );
            } else {
                $this->activityService->submitted(
                    kycRequest: $targetRequest->fresh(['user']),
                    performedBy: $user,
                    remarks: $request->input('remarks'),
                    request: $request
                );
            }
        });

        $this->clearKycCaches($user);

        return $targetRequest
            ->fresh([
                'user:id,first_name,last_name,email,phone,role_id,kyc',
                'role:id,name',
                'documents',
                'activities.performer:id,first_name,last_name,email',
            ]);
    }

    public function latestRequest(User $user): ?KycRequest
    {
        if (!Schema::hasTable('kyc_requests')) {
            return null;
        }

        return KycRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function prepareDraftRequest(
        User $user,
        KycSubmitRequest $request,
        ?KycRequest $latestRequest,
        ?Role $role
    ): KycRequest {
        if ($latestRequest && $latestRequest->isDraft()) {
            $latestRequest->update($this->requestPayload(
                user: $user,
                request: $request,
                role: $role,
                status: KycRequest::STATUS_DRAFT
            ));

            return $latestRequest->fresh();
        }

        if ($latestRequest && $latestRequest->isRejected()) {
            $parentRequestId = $latestRequest->parent_kyc_request_id ?: $latestRequest->id;

            $nextVersion = ((int) KycRequest::query()
                ->where(function ($query) use ($parentRequestId, $latestRequest) {
                    $query
                        ->where('id', $parentRequestId)
                        ->orWhere('parent_kyc_request_id', $parentRequestId)
                        ->orWhere('id', $latestRequest->id);
                })
                ->max('version')) + 1;

            $newRequest = KycRequest::query()->create(array_merge(
                $this->requestPayload(
                    user: $user,
                    request: $request,
                    role: $role,
                    status: KycRequest::STATUS_DRAFT
                ),
                [
                    'parent_kyc_request_id' => $parentRequestId,
                    'version' => $nextVersion,
                    'resubmission_count' => (int) $latestRequest->resubmission_count + 1,
                ]
            ));

            $this->copyApprovedDocumentsFromPreviousRequest($latestRequest, $newRequest);

            return $newRequest;
        }

        $kycRequest = KycRequest::query()->create($this->requestPayload(
            user: $user,
            request: $request,
            role: $role,
            status: KycRequest::STATUS_DRAFT
        ));

        $this->activityService->draftCreated(
            kycRequest: $kycRequest->fresh(['user']),
            performedBy: $user,
            request: $request
        );

        return $kycRequest;
    }

    private function requestPayload(
        User $user,
        KycSubmitRequest $request,
        ?Role $role,
        string $status
    ): array {
        return [
            'user_id' => $user->id,
            'role_id' => $role?->id ?? $user->role_id,
            'status' => $status,
            'aadhaar_number' => $request->input('aadhaar_number'),
            'gst_number' => $request->input('gst_number'),
            'rera_number' => $request->input('rera_number'),
            'business_name' => $request->input('business_name'),
        ];
    }

    private function normalizedDocumentFiles(KycSubmitRequest $request): array
    {
        $files = [];

        foreach ($request->kycDocumentFiles() as $key => $file) {
            $documentType = $key;

            if (str_starts_with($key, KycDocument::TYPE_OTHER . '_')) {
                $documentType = KycDocument::TYPE_OTHER;
            }

            $files[$documentType] = $file;
        }

        return $files;
    }

    private function documentNumberForType(string $documentType, KycSubmitRequest $request): ?string
    {
        return match ($documentType) {
            KycDocument::TYPE_AADHAAR_FRONT,
            KycDocument::TYPE_AADHAAR_BACK => $request->input('aadhaar_number'),

            KycDocument::TYPE_GST_CERTIFICATE => $request->input('gst_number'),

            KycDocument::TYPE_RERA_CERTIFICATE => $request->input('rera_number'),

            default => null,
        };
    }

    private function missingRequiredDocumentsBeforeUpload(
        KycRequest $kycRequest,
        array $requiredDocuments,
        array $incomingDocumentTypes
    ): array {
        if (empty($requiredDocuments)) {
            return [];
        }

        $existingDocumentTypes = KycDocument::query()
            ->where('kyc_request_id', $kycRequest->id)
            ->pluck('document_type')
            ->unique()
            ->values()
            ->toArray();

        $availableDocumentTypes = array_values(array_unique(array_merge(
            $existingDocumentTypes,
            $incomingDocumentTypes
        )));

        return collect($requiredDocuments)
            ->reject(fn(string $type) => in_array($type, $availableDocumentTypes, true))
            ->values()
            ->toArray();
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

    private function resolveRoleRule(User $user, ?Role $role): ?KycRoleRule
    {
        if (!$role || !Schema::hasTable('kyc_role_rules')) {
            return null;
        }

        return KycRoleRule::query()
            ->where('role_id', $role->id)
            ->where('is_active', true)
            ->first();
    }

    private function requiresKyc(User $user, ?Role $role, ?KycRoleRule $roleRule): bool
    {
        if ($roleRule) {
            return $roleRule->requiresKyc();
        }

        return !$this->isOwnerRole($role?->name);
    }

    private function requiredDocuments(?Role $role, ?KycRoleRule $roleRule): array
    {
        if ($roleRule && is_array($roleRule->required_documents)) {
            return $roleRule->required_documents;
        }

        return KycRoleRule::defaultRequiredDocumentsForRoleName($role?->name);
    }

    private function isOwnerRole(?string $roleName): bool
    {
        $roleName = strtolower(trim((string) $roleName));
        $roleName = str_replace([' ', '_', '-'], '', $roleName);

        return in_array($roleName, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }

    private function syncLegacyKycMirror(User $user, KycSubmitRequest $request): void
    {
        if (Schema::hasColumn('users', 'kyc')) {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'kyc' => 1,
                    'reject_reason' => Schema::hasColumn('users', 'reject_reason') ? null : DB::raw('reject_reason'),
                    'updated_at' => Schema::hasColumn('users', 'updated_at') ? now() : DB::raw('updated_at'),
                ]);
        }

        $businessPayload = [];

        if ($request->has('rera_number')) {
            $businessPayload['rera_number'] = $request->input('rera_number');
        }
        if ($request->has('business_name')) {
            $businessPayload['business_name'] = $request->input('business_name');
        }
        if ($request->has('bussiness_name')) {
            $businessPayload['business_name'] = $request->input('bussiness_name');
        }

        if (!empty($businessPayload)) {
            \App\Models\UserBusinessDetail::updateOrCreate(
                ['user_id' => $user->id],
                $businessPayload
            );
        }
    }

    private function clearKycCaches(User $user): void
    {
        $this->accessService->forgetUserCache($user);

        Cache::store('redis')->forget('kyc:user:' . $user->id . ':status');
        Cache::store('redis')->forget('kyc:admin:stats');
        Cache::store('redis')->forget('kyc:pending-count');

        Cache::store('redis')->forget('user_details_admin_' . $user->id);
        Cache::store('redis')->forget('user_details_website_' . $user->id);

        if (!empty($user->api_token)) {
            Cache::store('redis')->forget('user_by_token_' . md5($user->api_token));
        }
    }

    private function copyApprovedDocumentsFromPreviousRequest(
        ?KycRequest $latestRequest,
        KycRequest $newKycRequest
    ): void {
        if (!$latestRequest) {
            return;
        }

        $approvedDocs = KycDocument::query()
            ->where('kyc_request_id', $latestRequest->id)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->get();

        foreach ($approvedDocs as $approvedDoc) {
            $alreadyExists = KycDocument::query()
                ->where('kyc_request_id', $newKycRequest->id)
                ->where('document_type', $approvedDoc->document_type)
                ->exists();

            if (!$alreadyExists) {
                $cloned = $approvedDoc->replicate();
                $cloned->kyc_request_id = $newKycRequest->id;
                $cloned->version = $newKycRequest->version;
                $cloned->status = KycDocument::STATUS_APPROVED;
                $cloned->save();
            }
        }
    }
}