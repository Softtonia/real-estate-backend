<?php

namespace App\Services\PropertyVerification;

use App\Enums\PropertyWorkflowStatus;
use App\Models\DynamicPost;
use App\Models\PropertyListingRevision;
use App\Models\PropertyVerificationEvent;
use App\Models\User;
use App\Notifications\PropertyWorkflowNotification;
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
        'property-owner',
        'property-owners',
        'company',
        'companies',
        'consultant',
        'consultants',
        'consultancy',
    ];


    public function __construct(
        private readonly PropertySnapshotService $snapshots
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
            $this->storeDynamicPostAssignment(
                property: $lockedProperty,
                actor: $actor,
                verifier: $verifier
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

    /**
     * Must be called before UserListingController writes any update.
     */
    public function prepareUserUpdate(
        DynamicPost $property,
        User $owner
    ): array {
        $this->assertCanSubmitProperty($owner);
        $this->assertPropertyListing($property);
        $this->assertOwner($property, $owner);
        $this->assertNoOpenRevision($property);

        $wasLive = ($property->status ?? null) === 'published'
            && ($property->live_status ?? null) === PropertyWorkflowStatus::LIVE;

        return [
            'was_live' => $wasLive,
            'previous_status' => $property->status ?? null,
            'previous_live_status' => $property->live_status ?? null,
            'baseline_payload' => $wasLive
                ? $this->snapshots->capture($property)
                : null,
        ];
    }

    /**
     * Must be called after UserListingController has saved base fields,
     * images, taxonomies and custom fields.
     */
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
            $this->assertNoOpenRevision($lockedProperty);

            $wasLive = (bool) ($context['was_live'] ?? false);
            $source = $wasLive ? 'live_update' : 'resubmission';

            $revision = PropertyListingRevision::create([
                'dynamic_post_id' => $lockedProperty->id,
                'version' => $this->nextVersion($lockedProperty),
                'source' => $source,
                'status' => PropertyWorkflowStatus::RESUBMISSION,
                'baseline_payload' => $wasLive
                    ? ($context['baseline_payload'] ?? null)
                    : null,
                'submitted_payload' => $this->snapshots->capture($lockedProperty),
                'submitted_by' => $owner->id,
                'submitted_at' => now(),
            ]);

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: $wasLive ? 'published' : 'draft',
                liveStatus: PropertyWorkflowStatus::RESUBMISSION,
                clearPublishedAt: !$wasLive
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
                fromStatus: $context['previous_live_status'] ?? null,
                toStatus: PropertyWorkflowStatus::RESUBMISSION,
                message: $wasLive
                    ? 'Published property updates submitted for verification.'
                    : 'Property updated and resubmitted for verification.'
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

        return $revision->fresh();
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

            $this->assertPropertyListing($lockedProperty);

            $revision = $this->latestOrCreateAssignmentRevision(
                property: $lockedProperty,
                actor: $actor
            );

            $fromStatus = $revision->status;

            $revision->forceFill([
                'status' => PropertyWorkflowStatus::ASSIGNED,
                'assigned_to' => (int) $verifier->id,
                'assigned_by' => (int) $actor->id,
                'assigned_at' => now(),
                'verification_started_at' => null,
            ])->save();

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: $lockedProperty->status ?: 'draft',
                liveStatus: PropertyWorkflowStatus::ASSIGNED
            );

            /*
         * Also store assignment on dynamic_posts table.
         */
            $this->storeDynamicPostAssignment(
                property: $lockedProperty,
                actor: $actor,
                verifier: $verifier
            );

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: 'property_assigned',
                fromStatus: $fromStatus,
                toStatus: PropertyWorkflowStatus::ASSIGNED,
                message: $notes ?: 'Property assigned for verification.',
                metadata: [
                    'verifier_id' => (int) $verifier->id,
                    'verifier_name' => $this->userName($verifier),
                ]
            );

            $this->notifyUserAfterCommit(
                userId: (int) $verifier->id,
                property: $lockedProperty,
                event: 'property_assigned',
                metadata: [
                    'assigned_by' => (int) $actor->id,
                ]
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $lockedProperty->author_id,
                property: $lockedProperty,
                event: 'property_assigned',
                metadata: [
                    'verifier_id' => (int) $verifier->id,
                ]
            );

            return $revision;
        });

        return $revision->fresh([
            'property:id,title,slug,status,live_status,author_id,post_type_id',
            'assignedVerifier:id,first_name,last_name,email',
            'assigner:id,first_name,last_name,email',
        ]);
    }
    private function storeDynamicPostAssignment(
        DynamicPost $property,
        User $actor,
        User $verifier
    ): void {
        $payload = [];

        /*
     * Preferred columns.
     */
        if (Schema::hasColumn('dynamic_posts', 'assigned_to')) {
            $payload['assigned_to'] = (int) $verifier->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'assigned_by')) {
            $payload['assigned_by'] = (int) $actor->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'assigned_at')) {
            $payload['assigned_at'] = now();
        }

        /*
     * Extra supported names, only used if columns exist.
     */
        if (Schema::hasColumn('dynamic_posts', 'verifier_id')) {
            $payload['verifier_id'] = (int) $verifier->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'verified_by')) {
            $payload['verified_by'] = (int) $verifier->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'verification_assigned_to')) {
            $payload['verification_assigned_to'] = (int) $verifier->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'verification_assigned_by')) {
            $payload['verification_assigned_by'] = (int) $actor->id;
        }

        if (Schema::hasColumn('dynamic_posts', 'verification_assigned_at')) {
            $payload['verification_assigned_at'] = now();
        }

        if (!empty($payload)) {
            $property->forceFill($payload)->save();
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

        $allowedStatuses = [
            PropertyWorkflowStatus::UNDER_REVIEW,
            PropertyWorkflowStatus::RESUBMISSION,
            PropertyWorkflowStatus::ASSIGNED,
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

        return $revision;
    }
    public function startVerification(
        DynamicPost $property,
        User $actor
    ): PropertyListingRevision {
        $revision = DB::transaction(function () use ($property, $actor) {
            $lockedProperty = DynamicPost::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $revision = $this->latestActionableRevision(
                $lockedProperty,
                [
                    PropertyWorkflowStatus::ASSIGNED,
                    PropertyWorkflowStatus::IN_VERIFICATION,
                ]
            );

            $this->assertAssignedVerifier($revision, $actor);

            if ($revision->status === PropertyWorkflowStatus::IN_VERIFICATION) {
                return $revision;
            }

            $revision->forceFill([
                'status' => PropertyWorkflowStatus::IN_VERIFICATION,
                'verification_started_at' => now(),
            ])->save();

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: $lockedProperty->status ?: 'draft',
                liveStatus: PropertyWorkflowStatus::IN_VERIFICATION
            );

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: 'verification_started',
                fromStatus: PropertyWorkflowStatus::ASSIGNED,
                toStatus: PropertyWorkflowStatus::IN_VERIFICATION,
                message: 'Property verification started.'
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $lockedProperty->author_id,
                property: $lockedProperty,
                event: 'verification_started'
            );

            return $revision;
        });

        return $revision->fresh();
    }

    public function approve(
        DynamicPost $property,
        User $actor,
        ?string $notes = null
    ): PropertyListingRevision {
        $revision = DB::transaction(function () use (
            $property,
            $actor,
            $notes
        ) {
            $lockedProperty = DynamicPost::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $revision = $this->latestActionableRevision(
                $lockedProperty,
                [PropertyWorkflowStatus::IN_VERIFICATION]
            );

            $this->assertAssignedVerifier($revision, $actor);

            $wasRepublish = $revision->source === 'live_update';

            $this->setPropertyWorkflowState(
                $lockedProperty,
                status: 'published',
                liveStatus: PropertyWorkflowStatus::LIVE,
                publishNow: true
            );

            $this->clearRejectionMetadata($lockedProperty);

            $revision->forceFill([
                'status' => PropertyWorkflowStatus::APPROVED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: 'property_approved',
                fromStatus: PropertyWorkflowStatus::IN_VERIFICATION,
                toStatus: PropertyWorkflowStatus::APPROVED,
                message: $notes ?: 'Property verification approved.'
            );

            $publicationEvent = $wasRepublish
                ? 'property_republished'
                : 'property_published';

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: $publicationEvent,
                fromStatus: PropertyWorkflowStatus::APPROVED,
                toStatus: PropertyWorkflowStatus::LIVE,
                message: $wasRepublish
                    ? 'Approved property updates were published.'
                    : 'Property was published and is now live.'
            );

            $this->notifyOwnerAfterCommit(
                ownerId: (int) $lockedProperty->author_id,
                property: $lockedProperty,
                event: $wasRepublish
                    ? 'property_republished'
                    : 'property_approved'
            );

            return $revision;
        });

        return $revision->fresh(['decider']);
    }

    public function reject(
        DynamicPost $property,
        User $actor,
        string $reason
    ): PropertyListingRevision {
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

            $revision = $this->latestActionableRevision(
                $lockedProperty,
                [PropertyWorkflowStatus::IN_VERIFICATION]
            );

            $this->assertAssignedVerifier($revision, $actor);

            $revision->forceFill([
                'status' => PropertyWorkflowStatus::REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            /*
             * For an edited live property, restore the previously approved
             * version so rejected changes never replace the public version.
             */
            if (
                $revision->source === 'live_update'
                && is_array($revision->baseline_payload)
                && !empty($revision->baseline_payload)
            ) {
                $lockedProperty = $this->snapshots->restore(
                    $lockedProperty,
                    $revision->baseline_payload
                );

                $this->setPropertyWorkflowState(
                    $lockedProperty,
                    status: 'published',
                    liveStatus: PropertyWorkflowStatus::LIVE,
                    publishNow: true
                );

                $this->recordEvent(
                    property: $lockedProperty,
                    revision: $revision,
                    actor: $actor,
                    event: 'previous_live_version_restored',
                    fromStatus: PropertyWorkflowStatus::IN_VERIFICATION,
                    toStatus: PropertyWorkflowStatus::LIVE,
                    message: 'Rejected updates were discarded and the previously approved version was restored.'
                );
            } else {
                $this->setPropertyWorkflowState(
                    $lockedProperty,
                    status: 'draft',
                    liveStatus: 'reject',
                    clearPublishedAt: true
                );

                $this->setRejectionMetadata(
                    $lockedProperty,
                    $actor,
                    $reason
                );
            }

            $this->recordEvent(
                property: $lockedProperty,
                revision: $revision,
                actor: $actor,
                event: 'property_rejected',
                fromStatus: PropertyWorkflowStatus::IN_VERIFICATION,
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

        return $revision->fresh(['decider']);
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

        $property->forceFill($payload)->save();
    }
    private function legacyLiveStatus(string $workflowStatus): string
    {
        return match ($workflowStatus) {
            PropertyWorkflowStatus::LIVE,
            PropertyWorkflowStatus::APPROVED,
            'live',
            'approved',
            'approve' => 'approve',

            PropertyWorkflowStatus::REJECTED,
            'rejected',
            'reject' => 'reject',

            PropertyWorkflowStatus::UNDER_REVIEW,
            PropertyWorkflowStatus::RESUBMISSION,
            PropertyWorkflowStatus::ASSIGNED,
            PropertyWorkflowStatus::IN_VERIFICATION,
            'under_review',
            'resubmission',
            'assigned',
            'in_verification',
            'submit',
            'modify_review' => 'under_review',

            default => 'under_review',
        };
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
}
