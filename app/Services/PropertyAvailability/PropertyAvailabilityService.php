<?php

namespace App\Services\PropertyAvailability;

use App\Enums\PropertyAvailabilityStatus;
use App\Enums\PropertyWorkflowStatus;
use App\Models\DynamicPost;
use App\Models\PropertyAvailabilityHistory;
use App\Models\PropertyListingRevision;
use App\Models\PropertyVerificationEvent;
use App\Models\User;
use App\Services\PropertyVerification\PropertySnapshotService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyAvailabilityService
{
    public function __construct(
        private readonly PropertySnapshotService $snapshots
    ) {}

    public function change(
        DynamicPost $property,
        User $actor,
        string $targetStatus,
        ?string $notes,
        bool $isAdmin
    ): DynamicPost {
        $targetStatus = strtolower(trim($targetStatus));
        $notes = $notes !== null ? trim($notes) : null;

        return Cache::lock(
            'property-availability:' . $property->id,
            15
        )->block(5, function () use (
            $property,
            $actor,
            $targetStatus,
            $notes,
            $isAdmin
        ) {
            return DB::transaction(function () use (
                $property,
                $actor,
                $targetStatus,
                $notes,
                $isAdmin
            ) {
                $lockedProperty = DynamicPost::query()
                    ->lockForUpdate()
                    ->findOrFail($property->id);

                $this->assertPropertyListing($lockedProperty);

                if (!$isAdmin) {
                    $this->assertOwner(
                        $lockedProperty,
                        $actor
                    );
                }

                $currentStatus =
                    $lockedProperty->availability_status
                    ?: PropertyAvailabilityStatus::AVAILABLE;

                if ($currentStatus === $targetStatus) {
                    return $this->freshProperty(
                        $lockedProperty
                    );
                }

                $this->validateTransition(
                    property: $lockedProperty,
                    currentStatus: $currentStatus,
                    targetStatus: $targetStatus,
                    isAdmin: $isAdmin
                );

                $requiresAdminReview =
                    $targetStatus ===
                        PropertyAvailabilityStatus::AVAILABLE
                    && in_array(
                        $currentStatus,
                        PropertyAvailabilityStatus::
                            REACTIVATION_REVIEW_STATUSES,
                        true
                    );

                $availabilityBaseline =
                    $this->availabilitySnapshot(
                        $lockedProperty
                    );

                $fullBaseline = $requiresAdminReview
                    ? $this->snapshots->capture(
                        $lockedProperty
                    )
                    : null;

                $this->applyAvailabilityState(
                    property: $lockedProperty,
                    targetStatus: $targetStatus,
                    actor: $actor,
                    notes: $notes
                );

                if ($requiresAdminReview) {
                    $this->registerReactivationReview(
                        property: $lockedProperty,
                        actor: $actor,
                        availabilityBaseline:
                            $availabilityBaseline,
                        fullBaseline: is_array($fullBaseline)
                            ? $fullBaseline
                            : []
                    );
                }

                PropertyAvailabilityHistory::create([
                    'dynamic_post_id' =>
                        (int) $lockedProperty->id,

                    'from_status' => $currentStatus,
                    'to_status' => $targetStatus,
                    'changed_by' => (int) $actor->id,

                    'actor_context' => $isAdmin
                        ? 'admin'
                        : 'owner',

                    'notes' => $notes,

                    'metadata' => [
                        'requires_admin_review' =>
                            $requiresAdminReview,

                        'public_until' =>
                            optional(
                                $lockedProperty
                                    ->availability_public_until
                            )->toISOString(),
                    ],

                    'created_at' => now(),
                ]);

                $this->invalidateAfterCommit(
                    propertyId: (int) $lockedProperty->id,
                    ownerId: (int) $lockedProperty->author_id
                );

                return $this->freshProperty(
                    $lockedProperty
                );
            });
        });
    }

    public function history(
        DynamicPost $property,
        User $actor,
        bool $isAdmin,
        int $perPage = 20
    ): LengthAwarePaginator {
        $this->assertPropertyListing($property);

        if (!$isAdmin) {
            $this->assertOwner($property, $actor);
        }

        return PropertyAvailabilityHistory::query()
            ->with([
                'changedBy:id,first_name,last_name,email',
            ])
            ->where(
                'dynamic_post_id',
                (int) $property->id
            )
            ->latest('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function expireSoldVisibility(
        int $propertyId
    ): void {
        DB::transaction(function () use ($propertyId) {
            $property = DynamicPost::query()
                ->lockForUpdate()
                ->find($propertyId);

            if (!$property) {
                return;
            }

            if (
                $property->availability_status !==
                    PropertyAvailabilityStatus::SOLD
                || empty(
                    $property->availability_public_until
                )
                || $property->availability_public_until
                    ->isFuture()
                || !empty(
                    $property->availability_hidden_at
                )
            ) {
                return;
            }

            $property->forceFill([
                'availability_hidden_at' => now(),
            ])->save();

            PropertyAvailabilityHistory::create([
                'dynamic_post_id' => (int) $property->id,
                'from_status' =>
                    PropertyAvailabilityStatus::SOLD,
                'to_status' =>
                    PropertyAvailabilityStatus::SOLD,
                'changed_by' => null,
                'actor_context' => 'system',
                'notes' =>
                    'Sold property public visibility expired.',
                'metadata' => [
                    'event' =>
                        'sold_public_visibility_expired',
                    'public_until' =>
                        optional(
                            $property
                                ->availability_public_until
                        )->toISOString(),
                ],
                'created_at' => now(),
            ]);

            $this->invalidateAfterCommit(
                propertyId: (int) $property->id,
                ownerId: (int) $property->author_id
            );
        });
    }

    private function validateTransition(
        DynamicPost $property,
        string $currentStatus,
        string $targetStatus,
        bool $isAdmin
    ): void {
        if (!in_array(
            $targetStatus,
            PropertyAvailabilityStatus::VALUES,
            true
        )) {
            throw ValidationException::withMessages([
                'availability_status' => [
                    'Invalid availability status.',
                ],
            ]);
        }

        if (!$isAdmin) {
            $ownerTransitions = [
                PropertyAvailabilityStatus::AVAILABLE => [
                    PropertyAvailabilityStatus::RESERVED,
                    PropertyAvailabilityStatus::SOLD,
                    PropertyAvailabilityStatus::RENTED,
                    PropertyAvailabilityStatus::OFF_MARKET,
                ],

                PropertyAvailabilityStatus::RESERVED => [
                    PropertyAvailabilityStatus::AVAILABLE,
                    PropertyAvailabilityStatus::SOLD,
                    PropertyAvailabilityStatus::RENTED,
                    PropertyAvailabilityStatus::OFF_MARKET,
                ],

                PropertyAvailabilityStatus::SOLD => [
                    PropertyAvailabilityStatus::AVAILABLE,
                ],

                PropertyAvailabilityStatus::RENTED => [
                    PropertyAvailabilityStatus::AVAILABLE,
                ],

                PropertyAvailabilityStatus::OFF_MARKET => [
                    PropertyAvailabilityStatus::AVAILABLE,
                ],
            ];

            $allowedTargets =
                $ownerTransitions[$currentStatus] ?? [];

            if (!in_array(
                $targetStatus,
                $allowedTargets,
                true
            )) {
                throw ValidationException::withMessages([
                    'availability_status' => [
                        'This availability transition is not allowed.',
                    ],
                ]);
            }
        }

        /*
         * Property can only become unavailable after it has
         * been approved and published.
         */
        if (
            $targetStatus !==
                PropertyAvailabilityStatus::AVAILABLE
            && (
                $property->status !== 'published'
                || $property->live_status !== 'approve'
            )
        ) {
            throw ValidationException::withMessages([
                'availability_status' => [
                    'Only an approved and published property can be marked reserved, sold, rented or off market.',
                ],
            ]);
        }
    }

    private function applyAvailabilityState(
        DynamicPost $property,
        string $targetStatus,
        User $actor,
        ?string $notes
    ): void {
        $now = now();

        $payload = [
            'availability_status' => $targetStatus,
            'availability_changed_at' => $now,
            'availability_changed_by' =>
                (int) $actor->id,
            'availability_notes' => $notes,
        ];

        switch ($targetStatus) {
            case PropertyAvailabilityStatus::SOLD:
                $payload['availability_public_until'] =
                    $now->copy()->addDays(
                        (int) config(
                            'property_availability.sold_public_days',
                            7
                        )
                    );

                $payload['availability_hidden_at'] = null;
                $payload['sold_at'] = $now;
                $payload['sold_by'] = (int) $actor->id;
                break;

            case PropertyAvailabilityStatus::RENTED:
            case PropertyAvailabilityStatus::OFF_MARKET:
                $payload['availability_public_until'] = null;
                $payload['availability_hidden_at'] = $now;
                $payload['sold_at'] = null;
                $payload['sold_by'] = null;
                break;

            case PropertyAvailabilityStatus::RESERVED:
            case PropertyAvailabilityStatus::AVAILABLE:
            default:
                $payload['availability_public_until'] = null;
                $payload['availability_hidden_at'] = null;
                $payload['sold_at'] = null;
                $payload['sold_by'] = null;
                break;
        }

        $property->forceFill($payload)->save();
    }

    private function registerReactivationReview(
        DynamicPost $property,
        User $actor,
        array $availabilityBaseline,
        array $fullBaseline
    ): void {
        $latestOpenRevision =
            PropertyListingRevision::query()
                ->where(
                    'dynamic_post_id',
                    $property->id
                )
                ->whereIn(
                    'status',
                    PropertyWorkflowStatus::OPEN_STATUSES
                )
                ->latest('version')
                ->lockForUpdate()
                ->first();

        /*
         * Preserve any previous full baseline and add
         * availability-specific restoration information.
         */
        $baselinePayload =
            is_array($latestOpenRevision?->baseline_payload)
                ? $latestOpenRevision->baseline_payload
                : $fullBaseline;

        $baselinePayload[
            '_availability_reactivation'
        ] = $availabilityBaseline;

        $fromStatus = $latestOpenRevision?->status
            ?: ($property->live_status ?? null);

        $propertyPayload = [
            'status' => 'draft',
            'live_status' => 'under_review',
        ];

        if (
            Schema::hasColumn(
                'dynamic_posts',
                'published_at'
            )
        ) {
            $propertyPayload['published_at'] = null;
        }

        foreach (
            [
                'rejection_reason',
                'rejected_by',
                'rejected_at',
            ] as $column
        ) {
            if (
                Schema::hasColumn(
                    'dynamic_posts',
                    $column
                )
            ) {
                $propertyPayload[$column] = null;
            }
        }

        $property->forceFill($propertyPayload)->save();

        $submittedPayload =
            $this->snapshots->capture($property);

        if ($latestOpenRevision) {
            $latestOpenRevision->forceFill([
                'status' =>
                    PropertyWorkflowStatus::RESUBMISSION,

                'baseline_payload' => $baselinePayload,
                'submitted_payload' => $submittedPayload,
                'submitted_by' => (int) $actor->id,
                'submitted_at' => now(),

                /*
                 * Existing verifier assignment remains preserved.
                 */
                'assigned_to' =>
                    $latestOpenRevision->assigned_to,

                'assigned_by' =>
                    $latestOpenRevision->assigned_by,

                'assigned_at' =>
                    $latestOpenRevision->assigned_at,

                'verification_started_at' => null,
                'decided_by' => null,
                'decided_at' => null,
                'rejection_reason' => null,
            ])->save();

            $revision = $latestOpenRevision;
        } else {
            $nextVersion = (
                (int) PropertyListingRevision::query()
                    ->where(
                        'dynamic_post_id',
                        $property->id
                    )
                    ->max('version')
            ) + 1;

            $revision =
                PropertyListingRevision::create([
                    'dynamic_post_id' =>
                        (int) $property->id,

                    'version' => $nextVersion,

                    'source' =>
                        'availability_reactivation',

                    'status' =>
                        PropertyWorkflowStatus::RESUBMISSION,

                    'baseline_payload' =>
                        $baselinePayload,

                    'submitted_payload' =>
                        $submittedPayload,

                    'submitted_by' =>
                        (int) $actor->id,

                    'submitted_at' => now(),
                ]);
        }

        PropertyVerificationEvent::create([
            'dynamic_post_id' => (int) $property->id,
            'revision_id' => (int) $revision->id,
            'actor_id' => (int) $actor->id,

            'event' =>
                'availability_reactivation_submitted',

            'from_status' => $fromStatus,

            'to_status' =>
                PropertyWorkflowStatus::RESUBMISSION,

            'message' =>
                'Property availability reactivation submitted for admin review.',

            'metadata' => [
                'previous_availability_status' =>
                    $availabilityBaseline[
                        'availability_status'
                    ] ?? null,

                'requested_availability_status' =>
                    PropertyAvailabilityStatus::AVAILABLE,
            ],

            'created_at' => now(),
        ]);
    }

    private function availabilitySnapshot(
        DynamicPost $property
    ): array {
        return [
            'availability_status' =>
                $property->availability_status,

            'availability_changed_at' =>
                optional(
                    $property->availability_changed_at
                )->toISOString(),

            'availability_changed_by' =>
                $property->availability_changed_by,

            'availability_notes' =>
                $property->availability_notes,

            'availability_public_until' =>
                optional(
                    $property->availability_public_until
                )->toISOString(),

            'availability_hidden_at' =>
                optional(
                    $property->availability_hidden_at
                )->toISOString(),

            'sold_at' =>
                optional(
                    $property->sold_at
                )->toISOString(),

            'sold_by' => $property->sold_by,
        ];
    }

    private function assertOwner(
        DynamicPost $property,
        User $actor
    ): void {
        if (
            (int) $property->author_id !==
            (int) $actor->id
        ) {
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
        $postTypeSlug = DB::table('post_types')
            ->where('id', $property->post_type_id)
            ->value('slug');

        if (
            Str::slug((string) $postTypeSlug) !==
            'property-listing'
        ) {
            throw ValidationException::withMessages([
                'property' => [
                    'The selected post is not a property listing.',
                ],
            ]);
        }
    }

    private function invalidateAfterCommit(
        int $propertyId,
        int $ownerId
    ): void {
        DB::afterCommit(function () use (
            $propertyId,
            $ownerId
        ) {
            Cache::forget(
                'property:' . $propertyId . ':detail'
            );

            Cache::forget(
                'user:' . $ownerId . ':property-listings'
            );

            /*
             * Listing cache keys should include this version:
             *
             * property:list:v{version}:filters...
             */
            Cache::add(
                'property:list:version',
                0,
                now()->addYears(5)
            );

            Cache::increment(
                'property:list:version'
            );
        });
    }

    private function freshProperty(
        DynamicPost $property
    ): DynamicPost {
        return $property->fresh([
            'latestVerificationRevision',
            'availabilityChangedBy:id,first_name,last_name,email',
            'soldBy:id,first_name,last_name,email',
        ]);
    }
}