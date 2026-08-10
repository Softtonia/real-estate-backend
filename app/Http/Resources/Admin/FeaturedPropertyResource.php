<?php

namespace App\Http\Resources\Admin;

use App\Models\PropertyFeaturedPromotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeaturedPropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PropertyFeaturedPromotion $promotion */
        $promotion = $this->resource;

        return [
            'id' => (int) $promotion->id,

            'dynamic_post_id' =>
                (int) $promotion->dynamic_post_id,

            /*
            |--------------------------------------------------------------------------
            | Promotion state
            |--------------------------------------------------------------------------
            */

            'source' => $promotion->source,

            'status' => $promotion->status,

            'is_currently_featured' =>
                $promotion->isCurrentlyFeatured(),

            'starts_at' =>
                $promotion->starts_at?->toISOString(),

            'ends_at' =>
                $promotion->ends_at?->toISOString(),

            'priority' =>
                (int) $promotion->priority,

            'admin_notes' =>
                $promotion->admin_notes,

            /*
            |--------------------------------------------------------------------------
            | Property
            |--------------------------------------------------------------------------
            |
            | No extra query will run here.
            | Controller must eager-load "property".
            |
            */

            'property' =>
                $this->when(
                    $promotion->relationLoaded('property'),
                    function () use ($promotion) {
                        if (!$promotion->property) {
                            return null;
                        }

                        return [
                            'id' =>
                                (int) $promotion->property->id,

                            'post_type_id' =>
                                (int) $promotion->property->post_type_id,

                            'listing_code' =>
                                $promotion->property->listing_code
                                ?? null,

                            'title' =>
                                $promotion->property->title
                                ?? null,

                            'slug' =>
                                $promotion->property->slug
                                ?? null,

                            'author_id' =>
                                !empty(
                                    $promotion->property->author_id
                                )
                                    ? (int) $promotion->property->author_id
                                    : null,

                            'status' =>
                                $promotion->property->status
                                ?? null,

                            'live_status' =>
                                $promotion->property->live_status
                                ?? null,

                            'availability_status' =>
                                $promotion->property->availability_status
                                ?? null,

                            'published_at' =>
                                $promotion->property->published_at
                                    ? (
                                        method_exists(
                                            $promotion->property->published_at,
                                            'toISOString'
                                        )
                                            ? $promotion->property
                                                ->published_at
                                                ->toISOString()
                                            : $promotion->property
                                                ->published_at
                                    )
                                    : null,
                        ];
                    }
                ),

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'created_by' =>
                $promotion->relationLoaded('createdBy')
                    ? $this->formatUser(
                        $promotion->createdBy
                    )
                    : null,

            'updated_by' =>
                $promotion->relationLoaded('updatedBy')
                    ? $this->formatUser(
                        $promotion->updatedBy
                    )
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Cancellation
            |--------------------------------------------------------------------------
            */

            'cancelled_by' =>
                $promotion->relationLoaded('cancelledBy')
                    ? $this->formatUser(
                        $promotion->cancelledBy
                    )
                    : null,

            'cancelled_at' =>
                $promotion->cancelled_at?->toISOString(),

            'cancellation_reason' =>
                $promotion->cancellation_reason,

            /*
            |--------------------------------------------------------------------------
            | Record timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' =>
                $promotion->created_at?->toISOString(),

            'updated_at' =>
                $promotion->updated_at?->toISOString(),
        ];
    }

    private function formatUser(
        mixed $user
    ): ?array {
        if (!$user) {
            return null;
        }

        $name = trim(
            (string) (
                ($user->first_name ?? '')
                . ' '
                . ($user->last_name ?? '')
            )
        );

        if ($name === '') {
            $name =
                $user->name
                ?? $user->user_name
                ?? $user->email
                ?? ('User #' . $user->id);
        }

        return [
            'id' => (int) $user->id,
            'name' => $name,
            'email' => $user->email ?? null,
        ];
    }
}