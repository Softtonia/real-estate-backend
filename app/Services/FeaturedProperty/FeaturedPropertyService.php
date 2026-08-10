<?php

namespace App\Services\FeaturedProperty;

use App\Models\DynamicPost;
use App\Models\PropertyFeaturedPromotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeaturedPropertyService
{
    /**
     * Admin creates a featured promotion.
     *
     * Important:
     * - Does NOT modify property verification.
     * - Does NOT modify property publication status.
     * - Does NOT modify availability.
     * - Does NOT consume membership credits.
     */
    public function create(
        array $data,
        User $actor
    ): PropertyFeaturedPromotion {
        return DB::transaction(function () use (
            $data,
            $actor
        ) {
            /*
             * Locking the property serializes concurrent
             * featured operations for the same property.
             */
            $property = $this->lockProperty(
                (int) $data['dynamic_post_id']
            );

            $this->assertPropertyListing($property);

            $now = $this->now();

            $startsAt = !empty($data['starts_at'])
                ? $this->parseDate($data['starts_at'])
                : $now;

            $endsAt = $this->parseDate(
                $data['ends_at']
            );

            $this->assertValidDateRange(
                startsAt: $startsAt,
                endsAt: $endsAt,
                now: $now
            );

            /*
             * Prevent overlapping active/scheduled periods
             * for the same property.
             *
             * Non-overlapping future promotions are allowed.
             */
            $this->assertNoOverlappingPromotion(
                propertyId: (int) $property->id,
                startsAt: $startsAt,
                endsAt: $endsAt
            );

            $status = $this->resolveStatus(
                startsAt: $startsAt,
                endsAt: $endsAt,
                now: $now
            );

            $promotion =
                PropertyFeaturedPromotion::query()
                    ->create([
                        'dynamic_post_id' =>
                            (int) $property->id,

                        'source' =>
                            PropertyFeaturedPromotion::SOURCE_ADMIN,

                        'status' =>
                            $status,

                        'starts_at' =>
                            $startsAt,

                        'ends_at' =>
                            $endsAt,

                        'priority' =>
                            (int) ($data['priority'] ?? 0),

                        'admin_notes' =>
                            isset($data['admin_notes'])
                                ? trim(
                                    (string) $data['admin_notes']
                                )
                                : null,

                        'created_by' =>
                            (int) $actor->id,

                        'updated_by' =>
                            (int) $actor->id,

                        'cancelled_by' =>
                            null,

                        'cancelled_at' =>
                            null,

                        'cancellation_reason' =>
                            null,
                    ]);

            return $promotion
                ->fresh([
                    'property',
                    'createdBy',
                    'updatedBy',
                    'cancelledBy',
                ]);
        }, 3);
    }

    /**
     * Update / extend an active or scheduled promotion.
     */
    public function update(
        PropertyFeaturedPromotion $promotion,
        array $data,
        User $actor
    ): PropertyFeaturedPromotion {
        return DB::transaction(function () use (
            $promotion,
            $data,
            $actor
        ) {
            /*
             * Always lock property first.
             * Same locking order is used by create/cancel.
             */
            $property = $this->lockProperty(
                (int) $promotion->dynamic_post_id
            );

            $lockedPromotion =
                PropertyFeaturedPromotion::query()
                    ->whereKey($promotion->id)
                    ->lockForUpdate()
                    ->first();

            if (!$lockedPromotion) {
                throw ValidationException::withMessages([
                    'promotion' => [
                        'Featured promotion does not exist.',
                    ],
                ]);
            }

            $this->assertPropertyListing($property);

            $this->assertPromotionEditable(
                $lockedPromotion
            );

            $now = $this->now();

            $startsAt =
                array_key_exists(
                    'starts_at',
                    $data
                )
                    ? $this->parseDate(
                        $data['starts_at']
                    )
                    : $this->parseDate(
                        $lockedPromotion->starts_at
                    );

            $endsAt =
                array_key_exists(
                    'ends_at',
                    $data
                )
                    ? $this->parseDate(
                        $data['ends_at']
                    )
                    : $this->parseDate(
                        $lockedPromotion->ends_at
                    );

            $this->assertValidDateRange(
                startsAt: $startsAt,
                endsAt: $endsAt,
                now: $now
            );

            $this->assertNoOverlappingPromotion(
                propertyId:
                    (int) $lockedPromotion->dynamic_post_id,

                startsAt:
                    $startsAt,

                endsAt:
                    $endsAt,

                excludePromotionId:
                    (int) $lockedPromotion->id
            );

            $updateData = [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,

                'status' => $this->resolveStatus(
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    now: $now
                ),

                'updated_by' =>
                    (int) $actor->id,
            ];

            if (
                array_key_exists(
                    'priority',
                    $data
                )
            ) {
                $updateData['priority'] =
                    (int) $data['priority'];
            }

            if (
                array_key_exists(
                    'admin_notes',
                    $data
                )
            ) {
                $updateData['admin_notes'] =
                    $data['admin_notes'] !== null
                        ? trim(
                            (string) $data['admin_notes']
                        )
                        : null;
            }

            $lockedPromotion->forceFill(
                $updateData
            )->save();

            return $lockedPromotion
                ->fresh([
                    'property',
                    'createdBy',
                    'updatedBy',
                    'cancelledBy',
                ]);
        }, 3);
    }

    /**
     * Cancel featured promotion.
     *
     * We never physically delete the promotion.
     */
    public function cancel(
        PropertyFeaturedPromotion $promotion,
        User $actor,
        ?string $reason = null
    ): PropertyFeaturedPromotion {
        return DB::transaction(function () use (
            $promotion,
            $actor,
            $reason
        ) {
            /*
             * Same lock ordering:
             * property first, promotion second.
             */
            $this->lockProperty(
                (int) $promotion->dynamic_post_id
            );

            $lockedPromotion =
                PropertyFeaturedPromotion::query()
                    ->whereKey($promotion->id)
                    ->lockForUpdate()
                    ->first();

            if (!$lockedPromotion) {
                throw ValidationException::withMessages([
                    'promotion' => [
                        'Featured promotion does not exist.',
                    ],
                ]);
            }

            $this->assertPromotionCancellable(
                $lockedPromotion
            );

            $now = $this->now();

            $lockedPromotion->forceFill([
                'status' =>
                    PropertyFeaturedPromotion::STATUS_CANCELLED,

                'cancelled_by' =>
                    (int) $actor->id,

                'cancelled_at' =>
                    $now,

                'cancellation_reason' =>
                    $reason !== null
                        && trim($reason) !== ''
                            ? trim($reason)
                            : null,

                'updated_by' =>
                    (int) $actor->id,
            ])->save();

            return $lockedPromotion
                ->fresh([
                    'property',
                    'createdBy',
                    'updatedBy',
                    'cancelledBy',
                ]);
        }, 3);
    }

    /**
     * Called later by Laravel Scheduler.
     *
     * scheduled -> active
     * active/scheduled -> expired
     */
    public function syncTimeBasedStatuses(): array
    {
        $now = $this->now();

        /*
         * Expire first.
         */
        $expiredCount =
            PropertyFeaturedPromotion::query()
                ->whereIn('status', [
                    PropertyFeaturedPromotion::STATUS_ACTIVE,
                    PropertyFeaturedPromotion::STATUS_SCHEDULED,
                ])
                ->where(
                    'ends_at',
                    '<=',
                    $now
                )
                ->update([
                    'status' =>
                        PropertyFeaturedPromotion::STATUS_EXPIRED,

                    'updated_at' =>
                        $now,
                ]);

        /*
         * Activate scheduled promotions whose time has arrived.
         */
        $activatedCount =
            PropertyFeaturedPromotion::query()
                ->where(
                    'status',
                    PropertyFeaturedPromotion::STATUS_SCHEDULED
                )
                ->where(
                    'starts_at',
                    '<=',
                    $now
                )
                ->where(
                    'ends_at',
                    '>',
                    $now
                )
                ->update([
                    'status' =>
                        PropertyFeaturedPromotion::STATUS_ACTIVE,

                    'updated_at' =>
                        $now,
                ]);

        return [
            'activated' => $activatedCount,
            'expired' => $expiredCount,
        ];
    }

    /**
     * Lock property row.
     *
     * This is the main concurrency guard.
     */
    private function lockProperty(
        int $propertyId
    ): DynamicPost {
        $property = DynamicPost::query()
            ->whereKey($propertyId)
            ->lockForUpdate()
            ->first();

        if (!$property) {
            throw ValidationException::withMessages([
                'dynamic_post_id' => [
                    'Selected property does not exist.',
                ],
            ]);
        }

        return $property;
    }

    /**
     * Confirm selected DynamicPost is actually
     * a Property Listing.
     *
     * This follows the same pattern already used
     * by the property verification workflow.
     */
    private function assertPropertyListing(
        DynamicPost $property
    ): void {
        $postTypeSlug = DB::table(
            'post_types'
        )
            ->where(
                'id',
                (int) $property->post_type_id
            )
            ->value('slug');

        if (
            Str::slug(
                (string) $postTypeSlug
            ) !== 'property-listing'
        ) {
            throw ValidationException::withMessages([
                'dynamic_post_id' => [
                    'The selected post is not a property listing.',
                ],
            ]);
        }
    }

    /**
     * Validate final effective date range.
     */
    private function assertValidDateRange(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $now
    ): void {
        if (
            $endsAt->lessThanOrEqualTo(
                $startsAt
            )
        ) {
            throw ValidationException::withMessages([
                'ends_at' => [
                    'Featured end date must be after the start date.',
                ],
            ]);
        }

        if (
            $endsAt->lessThanOrEqualTo(
                $now
            )
        ) {
            throw ValidationException::withMessages([
                'ends_at' => [
                    'Featured end date must be in the future.',
                ],
            ]);
        }
    }

    /**
     * Prevent overlapping promotions.
     *
     * Existing:
     *      |----------|
     *
     * New overlapping:
     *          |----------|
     *
     * Condition:
     * existing.starts_at < new.ends_at
     * AND
     * existing.ends_at > new.starts_at
     *
     * Exactly touching periods are allowed.
     */
    private function assertNoOverlappingPromotion(
        int $propertyId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $excludePromotionId = null
    ): void {
        $query =
            PropertyFeaturedPromotion::query()
                ->where(
                    'dynamic_post_id',
                    $propertyId
                )
                ->whereIn('status', [
                    PropertyFeaturedPromotion::STATUS_SCHEDULED,
                    PropertyFeaturedPromotion::STATUS_ACTIVE,
                ])
                ->where(
                    'starts_at',
                    '<',
                    $endsAt
                )
                ->where(
                    'ends_at',
                    '>',
                    $startsAt
                );

        if ($excludePromotionId) {
            $query->where(
                'id',
                '!=',
                $excludePromotionId
            );
        }

        $conflictingPromotion =
            $query
                ->orderBy('starts_at')
                ->first([
                    'id',
                    'starts_at',
                    'ends_at',
                    'status',
                ]);

        if (!$conflictingPromotion) {
            return;
        }

        throw ValidationException::withMessages([
            'dynamic_post_id' => [
                'This property already has an overlapping featured promotion.',
            ],
            'featured_period' => [
                sprintf(
                    'Conflicting promotion #%d runs from %s to %s.',
                    (int) $conflictingPromotion->id,
                    $conflictingPromotion->starts_at
                        ? $conflictingPromotion->starts_at
                            ->format('Y-m-d H:i:s')
                        : '-',
                    $conflictingPromotion->ends_at
                        ? $conflictingPromotion->ends_at
                            ->format('Y-m-d H:i:s')
                        : '-'
                ),
            ],
        ]);
    }

    /**
     * An expired/cancelled promotion should not
     * silently become active again through update.
     *
     * Admin should create a new promotion instead.
     */
    private function assertPromotionEditable(
        PropertyFeaturedPromotion $promotion
    ): void {
        if (
            $promotion->status
            === PropertyFeaturedPromotion::STATUS_CANCELLED
        ) {
            throw ValidationException::withMessages([
                'promotion' => [
                    'Cancelled featured promotion cannot be edited. Create a new promotion instead.',
                ],
            ]);
        }

        if (
            $promotion->status
            === PropertyFeaturedPromotion::STATUS_EXPIRED
        ) {
            throw ValidationException::withMessages([
                'promotion' => [
                    'Expired featured promotion cannot be edited. Create a new promotion instead.',
                ],
            ]);
        }

        if (
            $promotion->ends_at
            && $promotion->ends_at->lte(
                $this->now()
            )
        ) {
            throw ValidationException::withMessages([
                'promotion' => [
                    'This featured promotion has already expired. Create a new promotion instead.',
                ],
            ]);
        }
    }

    private function assertPromotionCancellable(
        PropertyFeaturedPromotion $promotion
    ): void {
        if (
            $promotion->status
            === PropertyFeaturedPromotion::STATUS_CANCELLED
        ) {
            throw ValidationException::withMessages([
                'promotion' => [
                    'Featured promotion is already cancelled.',
                ],
            ]);
        }

        if (
            $promotion->status
            === PropertyFeaturedPromotion::STATUS_EXPIRED
            || (
                $promotion->ends_at
                && $promotion->ends_at->lte(
                    $this->now()
                )
            )
        ) {
            throw ValidationException::withMessages([
                'promotion' => [
                    'Expired featured promotion cannot be cancelled.',
                ],
            ]);
        }
    }

    /**
     * Decide status from dates.
     */
    private function resolveStatus(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $now
    ): string {
        if (
            $endsAt->lessThanOrEqualTo(
                $now
            )
        ) {
            return PropertyFeaturedPromotion::STATUS_EXPIRED;
        }

        if (
            $startsAt->greaterThan(
                $now
            )
        ) {
            return PropertyFeaturedPromotion::STATUS_SCHEDULED;
        }

        return PropertyFeaturedPromotion::STATUS_ACTIVE;
    }

    private function parseDate(
        mixed $value
    ): CarbonImmutable {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance(
                $value
            );
        }

        return CarbonImmutable::parse(
            (string) $value,
            config('app.timezone', 'UTC')
        );
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(
            config('app.timezone', 'UTC')
        );
    }
}