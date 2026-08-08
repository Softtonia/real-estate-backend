<?php

namespace App\Services\PropertyVerification;

use App\Enums\PropertyWorkflowStatus;
use App\Models\DynamicPost;
use App\Models\PropertyListingRevision;
use App\Models\PropertyVerificationEvent;
use App\Models\User;
use App\Notifications\PropertyWorkflowNotification;
use App\Services\PropertyAvailability\PropertyAvailabilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PropertyWorkflowService
{
    private const ALLOWED_OWNER_ROLES = [
        'owner',
        'owners',
        'agent',
        'property-owners',
        'company',
        'companies',
        'consultant',
        'consultants',
        'consultancy',
    ];

    public function __construct(
        private readonly PropertySnapshotService $snapshots,
        private readonly PropertyAvailabilityService $availability
    ) {}

    public function assertCanSubmitProperty(User $user): void
    {
        $roleSlug = $this->roleSlug($user);

        $allowedRoles = collect(config(
            'property_verification.submission_roles',
            self::ALLOWED_OWNER_ROLES
        ))
            ->map(fn($role) => Str::slug((string) $role))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!in_array($roleSlug, $allowedRoles, true)) {
            throw ValidationException::withMessages([
                'role' => [
                    'Only Property Owner, Company and Consultant roles can submit or resubmit properties.',
                ],
            ]);
        }
    }

    public function registerInitialSubmission(
        DynamicPost $property,
        User $owner
    ): PropertyListingRevision {
        $this->assertCanSubmitProperty($owner);

        $revision = DB::transaction(function () use ($property, $owner) {
            $lockedProperty = DynamicPost::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $this->assertPropertyListing($lockedProperty);
            $this->assertOwner($lockedProperty, $owner);

            if (
                PropertyListingRevision::query()
                ->where('dynamic_post_id', $lockedProperty->id)
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'property' => [
                        'The verification workflow has already been created for this property.',
                    ],
                ]);
            }

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: 'draft',
                liveStatus: PropertyWorkflowStatus::UNDER_REVIEW,
                clearPublishedAt: true
            );

            $revision = PropertyListingRevision::create([
                'dynamic_post_id' => $lockedProperty->id,
                'version' => 1,
                'source' => 'initial_submission',
                'status' => PropertyWorkflowStatus::UNDER_REVIEW,
                'baseline_payload' => null,
                'submitted_payload' => $this->snapshots->capture($lockedProperty),
                'submitted_by' => $owner->id,
                'submitted_at' => now(),
            ]);

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $owner,
                event: 'property_submitted',
                fromStatus: 'draft',
                toStatus: PropertyWorkflowStatus::UNDER_REVIEW,
                message: 'Property submitted for verification.'
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $owner->id,
                property: $lockedProperty,
                event: 'property_submitted'
            );

            $this->notifyAdminsAfterCommit(
                property: $lockedProperty,
                event: 'property_submitted'
            );

            return $revision;
        });

        return $revision->fresh();
    }

    public function prepareUserUpdate(
        DynamicPost $property,
        User $owner
    ): array {
        $this->assertCanSubmitProperty($owner);
        $this->assertPropertyListing($property);
        $this->assertOwner($property, $owner);

        $latestRevision = PropertyListingRevision::query()
            ->where('dynamic_post_id', $property->id)
            ->latest('version')
            ->first();

        $hasOpenRevision = $latestRevision
            && in_array($latestRevision->status, PropertyWorkflowStatus::OPEN_STATUSES, true);

        /*
     * Agar listing already live thi aur user edit karta hai,
     * baseline old approved version ka snapshot hoga.
     *
     * Agar already live_update pending hai aur user dobara edit karta hai,
     * old baseline preserve hoga.
     */
        $wasLive = (
            ($property->status ?? null) === 'published'
            && ($property->live_status ?? null) === 'approve'
        ) || (
            $hasOpenRevision
            && ($latestRevision->source ?? null) === 'live_update'
        );

        $baselinePayload = null;

        if ($wasLive) {
            $baselinePayload = $latestRevision?->baseline_payload;

            if (empty($baselinePayload)) {
                $baselinePayload = $this->snapshots->capture($property);
            }
        }

        return [
            'was_live' => $wasLive,
            'has_open_revision' => $hasOpenRevision,
            'open_revision_id' => $hasOpenRevision ? (int) $latestRevision->id : null,
            'previous_status' => $property->status ?? null,
            'previous_live_status' => $property->live_status ?? null,
            'baseline_payload' => $baselinePayload,
        ];
    }
    public function registerUserUpdate(
        DynamicPost $property,
        User $owner,
        array $context
    ): PropertyListingRevision {
        $revision = DB::transaction(function () use (
            $property,
            $owner,
            $context
        ) {
            $lockedProperty = DynamicPost::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $this->assertPropertyListing($lockedProperty);
            $this->assertOwner($lockedProperty, $owner);

            $latestOpenRevision = PropertyListingRevision::query()
                ->where('dynamic_post_id', $lockedProperty->id)
                ->whereIn('status', PropertyWorkflowStatus::OPEN_STATUSES)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            $wasLive = (bool) ($context['was_live'] ?? false);

            $source = $wasLive
                ? 'live_update'
                : ($latestOpenRevision?->source ?: 'resubmission');

            $baselinePayload = $wasLive
                ? (
                    $latestOpenRevision?->baseline_payload
                    ?: ($context['baseline_payload'] ?? null)
                )
                : null;

            $submittedPayload = $this->snapshots->capture($lockedProperty);
            $latestRevisionForAssignment = $latestOpenRevision;

            if (!$latestRevisionForAssignment) {
                $latestRevisionForAssignment = PropertyListingRevision::query()
                    ->where('dynamic_post_id', $lockedProperty->id)
                    ->latest('version')
                    ->lockForUpdate()
                    ->first();
            }

            $previousAssignedTo = $latestRevisionForAssignment?->assigned_to;
            $previousAssignedBy = $latestRevisionForAssignment?->assigned_by;
            $previousAssignedAt = $latestRevisionForAssignment?->assigned_at;
            if ($latestOpenRevision) {
                $fromStatus = $latestOpenRevision->status;

                $latestOpenRevision->forceFill([
                    'source' => $source,
                    'status' => PropertyWorkflowStatus::RESUBMISSION,
                    'baseline_payload' => $baselinePayload,
                    'submitted_payload' => $submittedPayload,
                    'submitted_by' => $owner->id,
                    'submitted_at' => now(),
                    'assigned_to' => $previousAssignedTo,
                    'assigned_by' => $previousAssignedBy,
                    'assigned_at' => $previousAssignedAt,

                    'verification_started_at' => null,
                    'decided_by' => null,
                    'decided_at' => null,
                    'rejection_reason' => null,
                ])->save();

                $revision = $latestOpenRevision;

                // $this->clearDynamicPostAssignedVerifier($lockedProperty);
            } else {
                $fromStatus = $context['previous_live_status'] ?? null;
                $revision = PropertyListingRevision::create([
                    'dynamic_post_id' => $lockedProperty->id,
                    'version' => $this->nextVersion($lockedProperty),
                    'source' => $source,
                    'status' => PropertyWorkflowStatus::RESUBMISSION,
                    'baseline_payload' => $baselinePayload,
                    'submitted_payload' => $submittedPayload,
                    'submitted_by' => $owner->id,
                    'submitted_at' => now(),

                    /*
     * Previous verifier preserve.
     */
                    'assigned_to' => $previousAssignedTo,
                    'assigned_by' => $previousAssignedBy,
                    'assigned_at' => $previousAssignedAt,
                ]);
            }

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: 'draft',
                liveStatus: PropertyWorkflowStatus::RESUBMISSION,
                clearPublishedAt: true
            );

            $this->clearRejectionMetadata($lockedProperty);

            $event = $wasLive
                ? 'live_property_updated'
                : 'property_resubmitted';

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $owner,
                event: $event,
                fromStatus: $fromStatus,
                toStatus: PropertyWorkflowStatus::RESUBMISSION,
                message: $wasLive
                    ? 'Published property changes submitted for admin review.'
                    : 'Property changes submitted for admin review.'
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $owner->id,
                property: $lockedProperty,
                event: $event
            );

            $this->notifyAdminsAfterCommit(
                property: $lockedProperty,
                event: $event
            );

            return $revision;
        });

        return $revision->fresh([
            'property:id,title,slug,status,live_status,author_id,post_type_id',
            'submitter:id,first_name,last_name,email',
            'assignedVerifier:id,first_name,last_name,email',
            'assigner:id,first_name,last_name,email',
            'decider:id,first_name,last_name,email',
        ]);
    }

    public function assign(
        DynamicPost $property,
        User $actor,
        User $verifier,
        ?string $notes = null
    ): PropertyListingRevision {
        $this->assertVerifier($verifier);

        $revision = DB::transaction(function () use (
            $property,
            $actor,
            $verifier,
            $notes
        ) {
            $lockedProperty = DynamicPost::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $this->assertPropertyListing(
                $lockedProperty
            );

            $revision =
                $this->latestOrCreateAssignmentRevision(
                    property: $lockedProperty,
                    actor: $actor
                );

            $fromStatus = $revision->status;

            /*
         * Reassignment while verification is running
         * must not downgrade the workflow.
         */
            $nextStatus =
                $fromStatus ===
                PropertyWorkflowStatus::IN_VERIFICATION
                ? PropertyWorkflowStatus::IN_VERIFICATION
                : PropertyWorkflowStatus::ASSIGNED;

            /*
         * ONLY assignment endpoint changes these.
         */
            $revision->forceFill([
                'status' => $nextStatus,

                'assigned_to' =>
                (int) $verifier->id,

                'assigned_by' =>
                (int) $actor->id,

                'assigned_at' => now(),

                'verification_started_at' =>
                $nextStatus ===
                    PropertyWorkflowStatus::IN_VERIFICATION
                    ? (
                        $revision->verification_started_at
                        ?: now()
                    )
                    : null,
            ])->save();

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: $lockedProperty->status ?: 'draft',
                liveStatus: $nextStatus
            );

            /*
         * Existing dynamic_post_user table.
         */
            $this->syncDynamicPostAssignedVerifier(
                property: $lockedProperty,
                actor: $actor,
                verifier: $verifier
            );

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,

                event: $fromStatus ===
                    PropertyWorkflowStatus::IN_VERIFICATION
                    ? 'property_reassigned'
                    : 'property_assigned',

                fromStatus: $fromStatus,
                toStatus: $nextStatus,

                message: $notes ?: (
                    $fromStatus ===
                    PropertyWorkflowStatus::IN_VERIFICATION
                    ? 'Property verifier reassigned during verification.'
                    : 'Property assigned for verification.'
                ),

                metadata: [
                    'verifier_id' =>
                    (int) $verifier->id,

                    'verifier_name' =>
                    $this->userName($verifier),
                ]
            );

            $this->notifyUserAfterCommit(
                userId: (int) $verifier->id,
                property: $lockedProperty,

                event: $fromStatus ===
                    PropertyWorkflowStatus::IN_VERIFICATION
                    ? 'property_reassigned'
                    : 'property_assigned',

                metadata: [
                    'assigned_by' =>
                    (int) $actor->id,
                ]
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $lockedProperty->author_id,

                property: $lockedProperty,

                event: $fromStatus ===
                    PropertyWorkflowStatus::IN_VERIFICATION
                    ? 'property_reassigned'
                    : 'property_assigned',

                metadata: [
                    'verifier_id' =>
                    (int) $verifier->id,
                ]
            );

            return $revision;
        });

        return $revision->fresh([
            'property:id,title,slug,status,live_status,author_id,post_type_id',
            'assignedVerifier:id,first_name,last_name,email',
            'assigner:id,first_name,last_name,email',
            'decider:id,first_name,last_name,email',
        ]);
    }
    public function startVerification(
        DynamicPost $property,
        User $actor
    ): PropertyListingRevision {
        $this->assertCanActOnVerification($actor);

        $revision = DB::transaction(
            function () use ($property, $actor) {
                $lockedProperty =
                    DynamicPost::query()
                    ->lockForUpdate()
                    ->findOrFail($property->id);

                $this->assertPropertyListing(
                    $lockedProperty
                );

                $revision =
                    $this->latestRevisionOrFail(
                        $lockedProperty
                    );

                if (
                    $revision->status ===
                    PropertyWorkflowStatus::APPROVED
                ) {
                    return $revision;
                }

                $allowedStatuses = [
                    PropertyWorkflowStatus::UNDER_REVIEW,
                    PropertyWorkflowStatus::RESUBMISSION,
                    PropertyWorkflowStatus::ASSIGNED,
                    PropertyWorkflowStatus::IN_VERIFICATION,
                ];

                if (!in_array(
                    $revision->status,
                    $allowedStatuses,
                    true
                )) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This action is not allowed while the property verification status is '
                                . $revision->status
                                . '.',
                        ],
                    ]);
                }

                /*
             * Admin bypass.
             * Verifier must be assigned.
             */
                $this->ensureActorCanWorkOnRevision(
                    $revision,
                    $actor
                );

                if (
                    $revision->status ===
                    PropertyWorkflowStatus::IN_VERIFICATION
                ) {
                    return $revision;
                }

                $fromStatus = $revision->status;

                /*
             * DO NOT touch:
             *
             * assigned_to
             * assigned_by
             * assigned_at
             */
                $revision->forceFill([
                    'status' =>
                    PropertyWorkflowStatus::IN_VERIFICATION,

                    'verification_started_at' => now(),
                ])->save();

                $this->setPropertyWorkflowState(
                    $lockedProperty,
                    status: $lockedProperty->status
                        ?: 'draft',

                    liveStatus: PropertyWorkflowStatus::IN_VERIFICATION
                );

                $this->recordEvent(
                    property: $lockedProperty,
                    revision: $revision,
                    actor: $actor,
                    event: 'verification_started',
                    fromStatus: $fromStatus,
                    toStatus: PropertyWorkflowStatus::IN_VERIFICATION,
                    message: 'Property verification started.'
                );

                $this->notifyOwnerAfterCommit(
                    ownerId: (int) $lockedProperty->author_id,

                    property: $lockedProperty,
                    event: 'verification_started'
                );

                return $revision;
            }
        );

        return $revision->fresh([
            'property:id,title,slug,status,live_status,author_id,post_type_id',
            'assignedVerifier:id,first_name,last_name,email',
            'assigner:id,first_name,last_name,email',
            'decider:id,first_name,last_name,email',
        ]);
    }

    public function approve(
        DynamicPost $property,
        User $actor,
        ?string $notes = null
    ): PropertyListingRevision {
        $this->assertCanActOnVerification($actor);

        $revision = DB::transaction(function () use (
            $property,
            $actor,
            $notes
        ) {
            $lockedProperty = DynamicPost::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $this->assertPropertyListing($lockedProperty);

            $revision = $this->latestRevisionOrFail($lockedProperty);

            if ($revision->status === PropertyWorkflowStatus::APPROVED) {
                return $revision;
            }

            $allowedStatuses = [
                PropertyWorkflowStatus::UNDER_REVIEW,
                PropertyWorkflowStatus::RESUBMISSION,
                PropertyWorkflowStatus::ASSIGNED,
                PropertyWorkflowStatus::IN_VERIFICATION,
            ];

            if (!in_array($revision->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This action is not allowed while the property verification status is '
                            . $revision->status
                            . '.',
                    ],
                ]);
            }

            $this->ensureActorCanWorkOnRevision($revision, $actor);

            $fromStatus = $revision->status;
            $wasRepublish = $revision->source === 'live_update';

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: 'published',
                liveStatus: PropertyWorkflowStatus::LIVE,
                publishNow: true
            );

            $this->clearRejectionMetadata($lockedProperty);

            $revision->forceFill([
                'status' =>
                PropertyWorkflowStatus::APPROVED,

                'verification_started_at' =>
                $revision->verification_started_at
                    ?: now(),

                'decided_by' => (int) $actor->id,

                'decided_at' => now(),

                'rejection_reason' => null,
            ])->save();

            /*
             * Availability reactivation is finalized only after
             * the verification revision is approved.
             */
            $this->availability->approvePendingReactivation(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor
            );

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: 'property_approved',
                fromStatus: $fromStatus,
                toStatus: PropertyWorkflowStatus::APPROVED,
                message: $notes ?: 'Property verification approved.'
            );

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: $wasRepublish ? 'property_republished' : 'property_published',
                fromStatus: PropertyWorkflowStatus::APPROVED,
                toStatus: PropertyWorkflowStatus::LIVE,
                message: $wasRepublish
                    ? 'Approved property changes were published.'
                    : 'Property was published and is now live.'
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $lockedProperty->author_id,
                property: $lockedProperty,
                event: $wasRepublish ? 'property_republished' : 'property_approved'
            );

            return $revision;
        });

        return $revision->fresh([
            'property:id,title,slug,status,live_status,author_id,post_type_id',
            'assignedVerifier:id,first_name,last_name,email',
            'assigner:id,first_name,last_name,email',
            'decider:id,first_name,last_name,email',
        ]);
    }

    public function reject(
        DynamicPost $property,
        User $actor,
        string $reason
    ): PropertyListingRevision {
        /*
     * Admin can reject without assignment.
     * Non-admin verifier must be valid + assigned.
     */
        $this->assertCanActOnVerification($actor);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => [
                    'Rejection reason is required.',
                ],
            ]);
        }

        $revision = DB::transaction(function () use (
            $property,
            $actor,
            $reason
        ) {
            $lockedProperty = DynamicPost::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $this->assertPropertyListing($lockedProperty);

            $revision = $this->latestRevisionOrFail($lockedProperty);

            if ($revision->status === PropertyWorkflowStatus::APPROVED) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This property is already approved. Reject is not allowed after approval.',
                    ],
                ]);
            }

            $allowedStatuses = [
                PropertyWorkflowStatus::UNDER_REVIEW,
                PropertyWorkflowStatus::RESUBMISSION,
                PropertyWorkflowStatus::ASSIGNED,
                PropertyWorkflowStatus::IN_VERIFICATION,
            ];

            if (!in_array($revision->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This action is not allowed while the property verification status is '
                            . $revision->status
                            . '.',
                    ],
                ]);
            }

            /*
         * Admin bypasses assignment.
         * Company/verifier must be assigned.
         */
            $this->ensureActorCanWorkOnRevision($revision, $actor);

            $fromStatus = $revision->status;

            $revision->forceFill([
                'status' =>
                PropertyWorkflowStatus::REJECTED,

                'verification_started_at' =>
                $revision->verification_started_at
                    ?: now(),

                'decided_by' => (int) $actor->id,
                'decided_at' => now(),

                'rejection_reason' => $reason,
            ])->save();

            /*
             * On reactivation rejection, keep the previous sold/rented/
             * off-market state and clear only the pending Available request.
             */
            $this->availability->rejectPendingReactivation(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                reason: $reason
            );

            /*
         * Important:
         * Do not restore old live version.
         * Your required final status:
         * dynamic_posts.status = draft
         * dynamic_posts.live_status = reject
         */
            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: 'draft',
                liveStatus: PropertyWorkflowStatus::REJECTED,
                clearPublishedAt: true
            );

            $this->setRejectionMetadata(
                $lockedProperty,
                $actor,
                $reason
            );

            $this->markPropertyReviewed(
                $lockedProperty,
                $actor
            );

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: 'property_rejected',
                fromStatus: $fromStatus,
                toStatus: PropertyWorkflowStatus::REJECTED,
                message: $reason,
                metadata: [
                    'rejection_reason' => $reason,
                ]
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $lockedProperty->author_id,
                property: $lockedProperty,
                event: 'property_rejected',
                reason: $reason
            );

            return $revision;
        });

        return $revision->fresh([
            'property:id,title,slug,status,live_status,author_id,post_type_id',
            'assignedVerifier:id,first_name,last_name,email',
            'assigner:id,first_name,last_name,email',
            'decider:id,first_name,last_name,email',
        ]);
    }

    public function timeline(DynamicPost $property): Collection
    {
        return PropertyVerificationEvent::query()
            ->with('actor:id,first_name,last_name,email')
            ->where('dynamic_post_id', $property->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function latestRevision(
        DynamicPost $property
    ): ?PropertyListingRevision {
        return PropertyListingRevision::query()
            ->with([
                'property:id,title,slug,status,live_status,author_id,post_type_id',
                'submitter:id,first_name,last_name,email',
                'assignedVerifier:id,first_name,last_name,email',
                'assigner:id,first_name,last_name,email',
                'decider:id,first_name,last_name,email',
            ])
            ->where('dynamic_post_id', $property->id)
            ->latest('version')
            ->first();
    }

    private function assertNoOpenRevision(DynamicPost $property): void
    {
        $exists = PropertyListingRevision::query()
            ->where('dynamic_post_id', $property->id)
            ->whereIn('status', PropertyWorkflowStatus::OPEN_STATUSES)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'property' => [
                    'This property already has a pending verification request.',
                ],
            ]);
        }
    }

    private function latestOrCreateAssignmentRevision(
        DynamicPost $property,
        User $actor
    ): PropertyListingRevision {
        $revision = PropertyListingRevision::query()
            ->where('dynamic_post_id', $property->id)
            ->latest('version')
            ->lockForUpdate()
            ->first();

        if (!$revision) {
            $fromStatus = $property->live_status ?? null;

            $revision = PropertyListingRevision::create([
                'dynamic_post_id' => $property->id,
                'version' => 1,
                'source' => 'admin_assignment',
                'status' => PropertyWorkflowStatus::UNDER_REVIEW,
                'baseline_payload' => null,
                'submitted_payload' => $this->snapshots->capture($property),
                'submitted_by' => $property->author_id ?: $actor->id,
                'submitted_at' => now(),
            ]);

            $this->setPropertyWorkflowState(
                $property,
                status: $property->status ?: 'draft',
                liveStatus: PropertyWorkflowStatus::UNDER_REVIEW
            );

            $this->recordEvent(
                property: $property,
                revision: $revision,
                actor: $actor,
                event: 'property_verification_revision_created',
                fromStatus: $fromStatus,
                toStatus: PropertyWorkflowStatus::UNDER_REVIEW,
                message: 'Property verification revision created by admin assignment.'
            );
        }

        /*
     * Allow assign/reassign before final decision.
     */
        $allowedStatuses = [
            PropertyWorkflowStatus::UNDER_REVIEW,
            PropertyWorkflowStatus::RESUBMISSION,
            PropertyWorkflowStatus::ASSIGNED,
            PropertyWorkflowStatus::IN_VERIFICATION,
        ];

        if (!in_array($revision->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    'Verifier cannot be assigned because this property verification is already '
                        . $revision->status
                        . '.',
                ],
            ]);
        }

        return $revision;
    }

    private function latestActionableRevision(
        DynamicPost $property,
        array $allowedStatuses
    ): PropertyListingRevision {
        $revision = PropertyListingRevision::query()
            ->where('dynamic_post_id', $property->id)
            ->latest('version')
            ->lockForUpdate()
            ->first();

        if (!$revision) {
            throw new RuntimeException(
                'Property verification revision not found.'
            );
        }

        if (!in_array($revision->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    'This action is not allowed while the property verification status is '
                        . $revision->status
                        . '.',
                ],
            ]);
        }

        return $revision;
    }
    private function latestRevisionOrFail(
        DynamicPost $property
    ): PropertyListingRevision {
        $revision = PropertyListingRevision::query()
            ->where('dynamic_post_id', $property->id)
            ->latest('version')
            ->lockForUpdate()
            ->first();

        if (!$revision) {
            throw new RuntimeException(
                'Property verification revision not found.'
            );
        }

        return $revision;
    }
    private function isSystemAdmin(User $user): bool
    {
        if (
            empty($user->role_id)
            || !Schema::hasTable('roles')
        ) {
            return false;
        }

        $role = DB::table('roles')
            ->where('id', (int) $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleValues = collect([
            $role->name ?? null,
            $role->slug ?? null,
            $role->role_name ?? null,
        ])
            ->filter()
            ->map(fn($value) => Str::slug(
                (string) $value
            ))
            ->values()
            ->toArray();

        return (bool) array_intersect(
            $roleValues,
            [
                'admin',
                'administrator',
                'super-admin',
                'super-admin-user',
                'superadmin',
            ]
        );
    }



    private function clearDynamicPostAssignedVerifier(
        DynamicPost $property
    ): void {
        if (!Schema::hasTable('dynamic_post_user')) {
            return;
        }

        DB::table('dynamic_post_user')
            ->where('dynamic_post_id', (int) $property->id)
            ->delete();
    }

    private function markPropertyReviewed(
        DynamicPost $property,
        User $actor
    ): void {
        $payload = [];

        if (Schema::hasColumn('dynamic_posts', 'review_action')) {
            $payload['review_action'] = null;
        }

        if (Schema::hasColumn('dynamic_posts', 'review_requested_at')) {
            $payload['review_requested_at'] = null;
        }

        if (Schema::hasColumn('dynamic_posts', 'review_previous_status')) {
            $payload['review_previous_status'] = null;
        }

        if (Schema::hasColumn('dynamic_posts', 'review_previous_live_status')) {
            $payload['review_previous_live_status'] = null;
        }

        if (Schema::hasColumn('dynamic_posts', 'reviewed_by')) {
            $payload['reviewed_by'] = (int) $actor->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'reviewed_at')) {
            $payload['reviewed_at'] = now();
        }

        if (!empty($payload)) {
            $property->forceFill($payload)->save();
        }
    }
    private function nextVersion(DynamicPost $property): int
    {
        return ((int) PropertyListingRevision::query()
            ->where('dynamic_post_id', $property->id)
            ->max('version')) + 1;
    }

    private function assertOwner(
        DynamicPost $property,
        User $user
    ): void {
        if ((int) ($property->author_id ?? 0) !== (int) $user->id) {
            throw ValidationException::withMessages([
                'property' => [
                    'You are not allowed to manage this property.',
                ],
            ]);
        }
    }

    private function assertPropertyListing(
        DynamicPost $property
    ): void {
        $slug = DB::table('post_types')
            ->where('id', $property->post_type_id)
            ->value('slug');

        if (Str::slug((string) $slug) !== 'property-listing') {
            throw ValidationException::withMessages([
                'property' => [
                    'The selected post is not a property listing.',
                ],
            ]);
        }
    }

    private function assertVerifier(User $verifier): void
    {
        if (
            Schema::hasColumn('users', 'isapproved')
            && (int) $verifier->isapproved !== 1
        ) {
            throw ValidationException::withMessages([
                'verifier_id' => [
                    'The selected verifier account is not active.',
                ],
            ]);
        }

        if (
            Schema::hasColumn('roles', 'is_admin_login_permission')
            && (int) DB::table('roles')
                ->where('id', $verifier->role_id)
                ->value('is_admin_login_permission') !== 1
        ) {
            throw ValidationException::withMessages([
                'verifier_id' => [
                    'The selected verifier does not have admin-panel login permission.',
                ],
            ]);
        }

        if (
            !Schema::hasTable('permissions')
            || !Schema::hasTable('role_has_permissions')
        ) {
            throw new RuntimeException(
                'Spatie permission tables are required for dynamic verifier selection.'
            );
        }

        $guardName = config('permission_modules.guard', 'sanctum');

        $requiredPermissions = collect(config(
            'property_verification.required_verifier_permissions',
            [
                'property_verifications.review',
                'property_verifications.approve',
                'property_verifications.reject',
            ]
        ))
            ->map(fn($permission) => strtolower(trim((string) $permission)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $rolePermissions = DB::table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('rhp.role_id', (int) $verifier->role_id)
            ->where('p.guard_name', $guardName)
            ->whereIn('p.name', $requiredPermissions)
            ->pluck('p.name')
            ->map(fn($permission) => strtolower((string) $permission))
            ->unique()
            ->values()
            ->all();

        $directPermissions = [];

        if (Schema::hasTable('model_has_permissions')) {
            $directPermissions = DB::table('model_has_permissions as mhp')
                ->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
                ->where('mhp.model_id', (int) $verifier->id)
                ->where('mhp.model_type', User::class)
                ->where('p.guard_name', $guardName)
                ->whereIn('p.name', $requiredPermissions)
                ->pluck('p.name')
                ->map(fn($permission) => strtolower((string) $permission))
                ->unique()
                ->values()
                ->all();
        }

        $grantedPermissions = array_values(array_unique(array_merge(
            $rolePermissions,
            $directPermissions
        )));

        $missingPermissions = array_values(array_diff(
            $requiredPermissions,
            $grantedPermissions
        ));

        if (!empty($missingPermissions)) {
            throw ValidationException::withMessages([
                'verifier_id' => [
                    'The selected user is not eligible to verify properties.',
                ],
                'missing_permissions' => $missingPermissions,
            ]);
        }
    }

    private function assertAssignedVerifier(
        PropertyListingRevision $revision,
        User $actor
    ): void {
        if (empty($revision->assigned_to)) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'Assign this property to a specific verifier before starting verification.',
                ],
            ]);
        }

        if ((int) $revision->assigned_to !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'This property is assigned to another verifier.',
                ],
            ]);
        }
    }

    private function roleSlug(User $user): string
    {
        $role = DB::table('roles')
            ->where('id', $user->role_id)
            ->first();

        return Str::slug((string) (
            $role->slug
            ?? $role->name
            ?? $role->role_name
            ?? ''
        ));
    }

    private function setPropertyWorkflowState(
        DynamicPost $property,
        string $status,
        string $liveStatus,
        bool $publishNow = false,
        bool $clearPublishedAt = false
    ): void {
        $payload = [];

        if (Schema::hasColumn('dynamic_posts', 'status')) {
            $payload['status'] = $status;
        }

        if (Schema::hasColumn('dynamic_posts', 'live_status')) {
            $payload['live_status'] = $this->legacyLiveStatus($liveStatus);
        }

        if (Schema::hasColumn('dynamic_posts', 'published_at')) {
            if ($publishNow) {
                $payload['published_at'] = $property->published_at ?: now();
            } elseif ($clearPublishedAt) {
                $payload['published_at'] = null;
            }
        }

        if (!empty($payload)) {
            $property->forceFill($payload)->save();
        }
    }

    private function syncDynamicPostAssignedVerifier(
        DynamicPost $property,
        User $actor,
        User $verifier
    ): void {
        if (!Schema::hasTable('dynamic_post_user')) {
            return;
        }

        $existing = DB::table('dynamic_post_user')
            ->where('dynamic_post_id', (int) $property->id)
            ->first();

        if ($existing) {
            DB::table('dynamic_post_user')
                ->where('dynamic_post_id', (int) $property->id)
                ->update([
                    'user_id' => (int) $verifier->id,
                    'assigned_by' => (int) $actor->id,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('dynamic_post_user')->insert([
            'dynamic_post_id' => (int) $property->id,
            'user_id' => (int) $verifier->id,
            'assigned_by' => (int) $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function legacyLiveStatus(string $workflowStatus): string
    {
        $status = strtolower(trim($workflowStatus));

        if (in_array($status, [
            'live',
            'approved',
            'approve',
        ], true)) {
            return 'approve';
        }

        if (in_array($status, [
            'rejected',
            'reject',
            'disapprove',
        ], true)) {
            return 'reject';
        }

        if (in_array($status, [
            'under_review',
            'resubmission',
            'assigned',
            'in_verification',
            'submit',
            'modify_review',
        ], true)) {
            return 'under_review';
        }

        return 'under_review';
    }

    private function setRejectionMetadata(
        DynamicPost $property,
        User $actor,
        string $reason
    ): void {
        $payload = [];

        if (Schema::hasColumn('dynamic_posts', 'rejection_reason')) {
            $payload['rejection_reason'] = $reason;
        }

        if (Schema::hasColumn('dynamic_posts', 'rejected_by')) {
            $payload['rejected_by'] = $actor->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'rejected_at')) {
            $payload['rejected_at'] = now();
        }

        if (!empty($payload)) {
            $property->forceFill($payload)->save();
        }
    }

    private function clearRejectionMetadata(
        DynamicPost $property
    ): void {
        $payload = [];

        foreach (
            ['rejection_reason', 'rejected_by', 'rejected_at']
            as $column
        ) {
            if (Schema::hasColumn('dynamic_posts', $column)) {
                $payload[$column] = null;
            }
        }

        if (!empty($payload)) {
            $property->forceFill($payload)->save();
        }
    }

    private function recordEvent(
        DynamicPost $property,
        ?PropertyListingRevision $revision,
        ?User $actor,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $message = null,
        array $metadata = []
    ): PropertyVerificationEvent {
        return PropertyVerificationEvent::create([
            'dynamic_post_id' => $property->id,
            'revision_id' => $revision?->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'message' => $message,
            'metadata' => empty($metadata) ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    private function notifyOwnerAfterCommit(
        int $ownerId,
        DynamicPost $property,
        string $event,
        ?string $reason = null,
        array $metadata = []
    ): void {
        $this->notifyUserAfterCommit(
            userId: $ownerId,
            property: $property,
            event: $event,
            reason: $reason,
            metadata: $metadata
        );
    }

    private function notifyUserAfterCommit(
        int $userId,
        DynamicPost $property,
        string $event,
        ?string $reason = null,
        array $metadata = []
    ): void {
        $propertyId = (int) $property->id;
        $propertyTitle = $property->title ?? null;

        DB::afterCommit(function () use (
            $userId,
            $propertyId,
            $propertyTitle,
            $event,
            $reason,
            $metadata
        ) {
            $user = User::find($userId);

            if (!$user || !method_exists($user, 'notify')) {
                return;
            }

            $user->notify(new PropertyWorkflowNotification(
                propertyId: $propertyId,
                propertyTitle: $propertyTitle,
                event: $event,
                reason: $reason,
                metadata: $metadata
            ));
        });
    }

    private function notifyAdminsAfterCommit(
        DynamicPost $property,
        string $event
    ): void {
        $propertyId = (int) $property->id;
        $propertyTitle = $property->title ?? null;

        DB::afterCommit(function () use (
            $propertyId,
            $propertyTitle,
            $event
        ) {
            if (
                !Schema::hasTable('permissions')
                || !Schema::hasTable('role_has_permissions')
            ) {
                return;
            }

            $guardName = config('permission_modules.guard', 'sanctum');

            User::query()
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->join(
                    'role_has_permissions as rhp',
                    'rhp.role_id',
                    '=',
                    'roles.id'
                )
                ->join(
                    'permissions as p',
                    'p.id',
                    '=',
                    'rhp.permission_id'
                )
                ->where('p.guard_name', $guardName)
                ->where(
                    'p.name',
                    'property_verifications.assign'
                )
                ->select('users.*')
                ->distinct()
                ->each(function (User $assignmentUser) use (
                    $propertyId,
                    $propertyTitle,
                    $event
                ) {
                    if (!method_exists($assignmentUser, 'notify')) {
                        return;
                    }

                    $assignmentUser->notify(
                        new PropertyWorkflowNotification(
                            propertyId: $propertyId,
                            propertyTitle: $propertyTitle,
                            event: $event
                        )
                    );
                });
        });
    }

    private function userName(User $user): string
    {
        return trim(
            ($user->first_name ?? '')
                . ' '
                . ($user->last_name ?? '')
        ) ?: ($user->email ?? ('User #' . $user->id));
    }

    private function ensureActorCanWorkOnRevision(
        PropertyListingRevision $revision,
        User $actor
    ): void {
        /*
     * Admin / Super Admin:
     * - assignment required nahi
     * - assigned_to change nahi hoga
     * - admin auto-assigned nahi hoga
     */
        if ($this->isSystemAdmin($actor)) {
            return;
        }

        /*
     * Non-admin verifier must be assigned.
     */
        if (empty($revision->assigned_to)) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'This property is not assigned to you.',
                ],
            ]);
        }

        if (
            (int) $revision->assigned_to
            !== (int) $actor->id
        ) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'This property is assigned to another verifier.',
                ],
            ]);
        }
    }

    private function assertCanActOnVerification(
        User $actor
    ): void {
        /*
     * Admin bypasses verifier eligibility + assignment.
     */
        if ($this->isSystemAdmin($actor)) {
            return;
        }

        /*
     * Non-admin must first be eligible verifier.
     */
        $this->assertVerifier($actor);
    }
    public function approvePendingReactivation(
        DynamicPost $property,
        PropertyListingRevision $revision,
        User $actor
    ): void {
        /*
     * Availability module/migration not present ho to
     * normal property approval ko block mat karo.
     */
        if (
            !Schema::hasColumn('dynamic_posts', 'availability_pending_status')
            || !Schema::hasColumn('dynamic_posts', 'availability_status')
        ) {
            return;
        }

        $property->refresh();

        /*
     * Normal property verification approval.
     * No availability reactivation pending => nothing to do.
     */
        if (
            $property->availability_pending_status
            !== PropertyAvailabilityStatus::AVAILABLE
        ) {
            return;
        }

        /*
     * Extra safety:
     * Pending Available request should normally come from
     * availability_reactivation revision.
     */
        if (
            !empty($revision->source)
            && $revision->source !== 'availability_reactivation'
        ) {
            return;
        }

        $update = [
            'availability_status' =>
            PropertyAvailabilityStatus::AVAILABLE,

            'availability_pending_status' => null,
        ];

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'availability_review_requested_at'
            )
        ) {
            $update['availability_review_requested_at'] = null;
        }

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'availability_public_until'
            )
        ) {
            $update['availability_public_until'] = null;
        }

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'availability_hidden_at'
            )
        ) {
            $update['availability_hidden_at'] = null;
        }

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'availability_changed_at'
            )
        ) {
            $update['availability_changed_at'] = now();
        }

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'availability_changed_by'
            )
        ) {
            $update['availability_changed_by'] =
                (int) $actor->id;
        }

        /*
     * Property is available again,
     * therefore old Sold metadata must be cleared.
     */
        if (
            Schema::hasColumn(
                'dynamic_posts',
                'sold_at'
            )
        ) {
            $update['sold_at'] = null;
        }

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'sold_by'
            )
        ) {
            $update['sold_by'] = null;
        }

        $property->forceFill($update);
        $property->save();
    }
    public function rejectPendingReactivation(
        DynamicPost $property,
        PropertyListingRevision $revision,
        User $actor,
        string $reason
    ): void {
        if (
            !Schema::hasColumn(
                'dynamic_posts',
                'availability_pending_status'
            )
        ) {
            return;
        }

        $property->refresh();

        /*
     * No pending reactivation => normal rejection.
     */
        if (
            $property->availability_pending_status
            !== PropertyAvailabilityStatus::AVAILABLE
        ) {
            return;
        }

        /*
     * Reject means:
     *
     * sold       stays sold
     * rented     stays rented
     * off_market stays off_market
     *
     * Only requested "available" state is cancelled.
     */
        $update = [
            'availability_pending_status' => null,
        ];

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'availability_review_requested_at'
            )
        ) {
            $update['availability_review_requested_at'] = null;
        }

        $property->forceFill($update);
        $property->save();
    }
}
