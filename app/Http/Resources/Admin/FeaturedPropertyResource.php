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
            ? $this->formatDynamicPost($dynamicPost)
            : null;

        return [
            'id' => (int) $promotion->id,

            'dynamic_post_id' =>
                (int) $promotion->dynamic_post_id,

            /*
            |--------------------------------------------------------------------------
            | Promotion
            |--------------------------------------------------------------------------
            */

            'source' =>
                $promotion->source,

            'status' =>
                $promotion->status,

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
            | Generic Dynamic Post
            |--------------------------------------------------------------------------
            |
            | This is now the preferred frontend/admin key.
            |
            | Works for:
            | - property-listing
            | - project-listing
            | - developer-listing
            | - guest-post
            | - any future DynamicPost type
            |
            */

            'dynamic_post' =>
                $dynamicPostData,

            /*
            |--------------------------------------------------------------------------
            | Backward Compatibility
            |--------------------------------------------------------------------------
            |
            | Keep old "property" key so existing frontend does not break.
            | It points to the exact same DynamicPost data.
            |
            */

            'property' =>
                $dynamicPostData,

            /*
            |--------------------------------------------------------------------------
            | Convenient Post Type
            |--------------------------------------------------------------------------
            */

            'post_type' =>
                $dynamicPost
                    ? $this->formatPostType(
                        $dynamicPost
                    )
                    : null,

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

    /**
     * Format any DynamicPost.
     */
    private function formatDynamicPost(
        mixed $post
    ): array {
        return [
            'id' =>
                (int) $post->id,

            'post_type_id' =>
                !empty($post->post_type_id)
                    ? (int) $post->post_type_id
                    : null,

            'post_type' =>
                $this->formatPostType($post),

            'listing_code' =>
                $post->listing_code
                ?? null,

            'title' =>
                $post->title
                ?? null,

            'slug' =>
                $post->slug
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Owner
            |--------------------------------------------------------------------------
            */

            'author_id' =>
                !empty($post->author_id)
                    ? (int) $post->author_id
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Publication
            |--------------------------------------------------------------------------
            */

            'status' =>
                $post->status
                ?? null,

            'live_status' =>
                $post->live_status
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Availability
            |--------------------------------------------------------------------------
            |
            | Property listings may use availability_status.
            | Other DynamicPost types can return null.
            |
            */

            'availability_status' =>
                $post->availability_status
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Media reference
            |--------------------------------------------------------------------------
            */

            'featured_image_id' =>
                !empty($post->featured_image_id)
                    ? (int) $post->featured_image_id
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Publication date
            |--------------------------------------------------------------------------
            */

            'published_at' =>
                $this->formatDate(
                    $post->published_at
                    ?? null
                ),
        ];
    }

    /**
     * Format DynamicPost post type.
     *
     * Controller/service should eager-load property.postType.
     */
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
                'id' =>
                    (int) $post->postType->id,

                'name' =>
                    $post->postType->name,

                'slug' =>
                    $post->postType->slug,
            ];
        }

        /*
         * Do NOT execute another database query here.
         * Resource must remain N+1 safe.
         */
        return !empty($post->post_type_id)
            ? [
                'id' =>
                    (int) $post->post_type_id,

                'name' =>
                    null,

                'slug' =>
                    null,
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
            $name =
                $user->name
                ?? $user->user_name
                ?? $user->email
                ?? ('User #' . $user->id);
        }

        return [
            'id' =>
                (int) $user->id,

            'name' =>
                $name,

            'email' =>
                $user->email
                ?? null,
        ];
    }

    private function formatDate(
        mixed $value
    ): mixed {
        if (!$value) {
            return null;
        }

        if (
            is_object($value)
            && method_exists(
                $value,
                'toISOString'
            )
        ) {
            return $value->toISOString();
        }

        return $value;
    }
}