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

        $dynamicPost = $promotion->relationLoaded('property')
            ? $promotion->property
            : null;

        $dynamicPostData = $dynamicPost
            ? (new DynamicPostFeaturedListingResource(
                $dynamicPost
            ))->resolve($request)
            : null;

        return [
            'id' => (int) $promotion->id,

            'dynamic_post_id' => (int) $promotion->dynamic_post_id,

            'listing_id' => (int) $promotion->dynamic_post_id,

            'is_featured' => $promotion->isCurrentlyFeatured(),

            'source' => $promotion->source,

            'featured_via' => $this->featuredVia($promotion),

            'promotion_type' => $promotion->promotion_type,

            'display_label' => $this->displayLabel($promotion),

            'status' => $promotion->status,

            'is_currently_featured' => $promotion->isCurrentlyFeatured(),

            'placements' => [
                'home' => (bool) $promotion->show_on_home,
                'search' => (bool) $promotion->show_on_search,
                'property_detail' => (bool) $promotion->show_on_detail,
            ],

            'show_on_home' => (bool) $promotion->show_on_home,

            'show_on_search' => (bool) $promotion->show_on_search,

            'show_on_detail' => (bool) $promotion->show_on_detail,

            'starts_at' => $promotion->starts_at?->toISOString(),

            'ends_at' => $promotion->ends_at?->toISOString(),

            'priority' => (int) $promotion->priority,

            'admin_notes' => $promotion->admin_notes,

            'dynamic_post' => $dynamicPostData,

            'property' => $dynamicPostData,

            'post_type' => $dynamicPost
                ? $this->formatPostType($dynamicPost)
                : null,

            'created_by' => $promotion->relationLoaded('createdBy')
                ? $this->formatUser($promotion->createdBy)
                : null,

            'updated_by' => $promotion->relationLoaded('updatedBy')
                ? $this->formatUser($promotion->updatedBy)
                : null,

            'cancelled_by' => $promotion->relationLoaded('cancelledBy')
                ? $this->formatUser($promotion->cancelledBy)
                : null,

            'cancelled_at' => $promotion->cancelled_at?->toISOString(),

            'cancellation_reason' => $promotion->cancellation_reason,

            'created_at' => $promotion->created_at?->toISOString(),

            'updated_at' => $promotion->updated_at?->toISOString(),
        ];
    }

    private function formatPostType(
        mixed $post
    ): ?array {
        if (!$post) {
            return null;
        }

        if (
            method_exists($post, 'relationLoaded')
            && $post->relationLoaded('postType')
            && $post->postType
        ) {
            return [
                'id' => (int) $post->postType->id,
                'name' => $post->postType->name,
                'slug' => $post->postType->slug,
            ];
        }

        return !empty($post->post_type_id)
            ? [
                'id' => (int) $post->post_type_id,
                'name' => null,
                'slug' => null,
            ]
            : null;
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
            $name = $user->name
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

    private function featuredVia(
        PropertyFeaturedPromotion $promotion
    ): string {
        return match ($promotion->source) {
            PropertyFeaturedPromotion::SOURCE_ADMIN => 'admin',

            PropertyFeaturedPromotion::SOURCE_MEMBERSHIP => 'membership',

            default => (string) $promotion->source,
        };
    }

    private function displayLabel(
        PropertyFeaturedPromotion $promotion
    ): string {
        return match ($promotion->promotion_type) {
            PropertyFeaturedPromotion::TYPE_SPONSORED => 'Sponsored',

            PropertyFeaturedPromotion::TYPE_FEATURED => 'Featured',

            default => 'Featured',
        };
    }
}