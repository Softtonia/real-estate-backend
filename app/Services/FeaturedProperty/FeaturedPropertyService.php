<?php

namespace App\Services\FeaturedProperty;

use App\Models\DynamicPost;
use App\Models\PropertyFeaturedPromotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FeaturedPropertyService
{
    /**
     * Admin creates featured promotion.
     *
     * Existing admin controller continues using this method.
     */
    public function create(
        array $data,
        User $actor
    ): PropertyFeaturedPromotion {
        return $this->createPromotion(
            data: $data,
            actor: $actor,
            source: PropertyFeaturedPromotion::SOURCE_ADMIN,
            requireOwnership: false
        );
    }

    /**
     * User membership creates featured promotion.
     *
     * IMPORTANT:
     * Credit is NOT consumed here yet.
     *
     * Later a membership orchestration service will:
     * 1. verify credit
     * 2. consume credit
     * 3. call this method
     *
     * inside one transaction.
     */
    public function createForMembership(
        array $data,
        User $actor
    ): PropertyFeaturedPromotion {
        return $this->createPromotion(
            data: $data,
            actor: $actor,
            source: PropertyFeaturedPromotion::SOURCE_MEMBERSHIP,
            requireOwnership: true
        );
    }

    /**
     * Shared promotion creation.
     *
     * Supports ANY DynamicPost post type.
     */
    private function createPromotion(
        array $data,
        User $actor,
        string $source,
        bool $requireOwnership
    ): PropertyFeaturedPromotion {
        return DB::transaction(function () use (
            $data,
            $actor,
            $source,
            $requireOwnership
        ) {
            $this->assertValidSource($source);

            $post = $this->lockProperty(
                (int) $data['dynamic_post_id']
            );

            /*
             * Generic DynamicPost validation.
             */
            $this->assertPropertyListing($post);

            /*
             * Admin can feature any DynamicPost.
             *
             * Membership user can feature only own DynamicPost.
             */
            if ($requireOwnership) {
                $this->assertUserOwnsDynamicPost(
                    post: $post,
                    user: $actor
                );
            }

            $now = $this->now();

            $startsAt = !empty($data['starts_at'])
                ? $this->parseDate(
                    $data['starts_at']
                )
                : $now;

            $endsAt = $this->parseDate(
                $data['ends_at']
            );

            $this->assertValidDateRange(
                startsAt: $startsAt,
                endsAt: $endsAt,
                now: $now
            );

            $this->assertNoOverlappingPromotion(
                propertyId: (int) $post->id,
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
                            (int) $post->id,

                        /*
                         * admin / membership
                         */
                        'source' =>
                            $source,

                        'status' =>
                            $status,

                        'starts_at' =>
                            $startsAt,

                        'ends_at' =>
                            $endsAt,

                        'priority' =>
                            (int) (
                                $data['priority']
                                ?? 0
                            ),

                        /*
                         * User-side APIs should normally
                         * not expose admin_notes.
                         */
                        'admin_notes' =>
                            $source
                            === PropertyFeaturedPromotion::SOURCE_ADMIN
                                && isset(
                                    $data['admin_notes']
                                )
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

            return $promotion->fresh([
                'property.postType',
                'createdBy',
                'updatedBy',
                'cancelledBy',
            ]);
        }, 3);
    }

    /**
     * Update / extend active or scheduled promotion.
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
            $post = $this->lockProperty(
                (int) $promotion->dynamic_post_id
            );

            $lockedPromotion =
                PropertyFeaturedPromotion::query()
                    ->whereKey(
                        $promotion->id
                    )
                    ->lockForUpdate()
                    ->first();

            if (!$lockedPromotion) {
                throw ValidationException::withMessages([
                    'promotion' => [
                        'Featured promotion does not exist.',
                    ],
                ]);
            }

            $this->assertPropertyListing(
                $post
            );

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
                    (int) $lockedPromotion
                        ->dynamic_post_id,

                startsAt:
                    $startsAt,

                endsAt:
                    $endsAt,

                excludePromotionId:
                    (int) $lockedPromotion->id
            );

            $updateData = [
                'starts_at' =>
                    $startsAt,

                'ends_at' =>
                    $endsAt,

                'status' =>
                    $this->resolveStatus(
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

            /*
             * admin_notes should only be manageable
             * for admin-created operations.
             */
            if (
                $lockedPromotion->source
                    === PropertyFeaturedPromotion::SOURCE_ADMIN
                && array_key_exists(
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

            $lockedPromotion
                ->forceFill(
                    $updateData
                )
                ->save();

            return $lockedPromotion
                ->fresh([
                    'property.postType',
                    'createdBy',
                    'updatedBy',
                    'cancelledBy',
                ]);
        }, 3);
    }

    /**
     * Cancel promotion.
     *
     * Physical delete is never performed.
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
            $this->lockProperty(
                (int) $promotion->dynamic_post_id
            );

            $lockedPromotion =
                PropertyFeaturedPromotion::query()
                    ->whereKey(
                        $promotion->id
                    )
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

            $lockedPromotion
                ->forceFill([
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
                ])
                ->save();

            return $lockedPromotion
                ->fresh([
                    'property.postType',
                    'createdBy',
                    'updatedBy',
                    'cancelledBy',
                ]);
        }, 3);
    }

    /**
     * Scheduler helper.
     *
     * scheduled -> active
     * active/scheduled -> expired
     */
    public function syncTimeBasedStatuses(): array
    {
        $now = $this->now();

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
            'activated' =>
                $activatedCount,

            'expired' =>
                $expiredCount,
        ];
    }

    /**
     * Lock selected DynamicPost.
     *
     * Method name retained for backward compatibility.
     */
    private function lockProperty(
        int $propertyId
    ): DynamicPost {
        $post = DynamicPost::query()
            ->whereKey(
                $propertyId
            )
            ->lockForUpdate()
            ->first();

        if (!$post) {
            throw ValidationException::withMessages([
                'dynamic_post_id' => [
                    'Selected dynamic post does not exist.',
                ],
            ]);
        }

        return $post;
    }

    /**
     * Legacy method name retained.
     *
     * NO property-listing restriction exists anymore.
     *
     * Any DynamicPost with a valid PostType can be featured.
     */
    private function assertPropertyListing(
        DynamicPost $property
    ): void {
        if (empty($property->post_type_id)) {
            throw ValidationException::withMessages([
                'dynamic_post_id' => [
                    'Selected dynamic post does not have a valid post type.',
                ],
            ]);
        }

        $postTypeExists = DB::table(
            'post_types'
        )
            ->where(
                'id',
                (int) $property->post_type_id
            )
            ->exists();

        if (!$postTypeExists) {
            throw ValidationException::withMessages([
                'dynamic_post_id' => [
                    'Post type for the selected dynamic post does not exist.',
                ],
            ]);
        }
    }

    /**
     * Membership users may only feature their own post.
     *
     * Ownership remains dynamic_posts.author_id.
     */
    private function assertUserOwnsDynamicPost(
        DynamicPost $post,
        User $user
    ): void {
        if (
            !Schema::hasColumn(
                'dynamic_posts',
                'author_id'
            )
        ) {
            throw ValidationException::withMessages([
                'dynamic_post_id' => [
                    'Dynamic post ownership is not configured.',
                ],
            ]);
        }

        if (
            empty($post->author_id)
            || (int) $post->author_id
                !== (int) $user->id
        ) {
            throw ValidationException::withMessages([
                'dynamic_post_id' => [
                    'You can only feature your own listing.',
                ],
            ]);
        }
    }

    /**
     * Validate promotion source.
     */
    private function assertValidSource(
        string $source
    ): void {
        if (
            !in_array(
                $source,
                PropertyFeaturedPromotion::SOURCES,
                true
            )
        ) {
            throw ValidationException::withMessages([
                'source' => [
                    'Invalid featured promotion source.',
                ],
            ]);
        }
    }

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
     * Prevent overlapping open promotions.
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
                ->orderBy(
                    'starts_at'
                )
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
                'This dynamic post already has an overlapping featured promotion.',
            ],

            'featured_period' => [
                sprintf(
                    'Conflicting promotion #%d runs from %s to %s.',
                    (int) $conflictingPromotion->id,
                    $conflictingPromotion->starts_at
                        ? $conflictingPromotion
                            ->starts_at
                            ->format(
                                'Y-m-d H:i:s'
                            )
                        : '-',
                    $conflictingPromotion->ends_at
                        ? $conflictingPromotion
                            ->ends_at
                            ->format(
                                'Y-m-d H:i:s'
                            )
                        : '-'
                ),
            ],
        ]);
    }

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
            config(
                'app.timezone',
                'UTC'
            )
        );
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(
            config(
                'app.timezone',
                'UTC'
            )
        );
    }
}