<?php

namespace App\Http\Resources\Guest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestDynamicPostCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $postType = $this->postType;

        $featuredPromotion = $this->getAttribute(
            '_guest_featured_promotion'
        );

        $featuredMedia = $this->getAttribute(
            '_guest_featured_media'
        );

        return [
            'id' => (int) $this->id,

            'listing_code' =>
                $this->listing_code ?? null,

            'title' =>
                $this->title ?? null,

            'slug' =>
                $this->slug ?? null,

            'excerpt' =>
                $this->excerpt ?? null,

            'post_type' => $postType
                ? [
                    'id' => (int) $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                ]
                : null,

            'location' => [
                'country_id' =>
                    $this->country_id
                        ? (int) $this->country_id
                        : null,

                'country_name' =>
                    $this->guest_country_name ?? null,

                'state_id' =>
                    $this->state_id
                        ? (int) $this->state_id
                        : null,

                'state_name' =>
                    $this->guest_state_name ?? null,

                'city_id' =>
                    $this->city_id
                        ? (int) $this->city_id
                        : null,

                'city_name' =>
                    $this->guest_city_name ?? null,

                'area_locality' =>
                    $this->area_locality ?? null,

                'full_address' =>
                    $this->fullAddress(),
            ],

            'featured_image_id' =>
                $this->featured_image_id
                    ? (int) $this->featured_image_id
                    : null,

            'featured_image' =>
                $this->mediaUrl($featuredMedia),

            'featured_image_media' =>
                $this->formatMedia($featuredMedia),

            'selected_taxonomies' =>
                $this->formatTaxonomies(),

            'featured' => [
                'is_featured' =>
                    $featuredPromotion !== null,

                'promotion_id' =>
                    $featuredPromotion
                        ? (int) $featuredPromotion->id
                        : null,

                'source' =>
                    $featuredPromotion?->source,

                'priority' =>
                    $featuredPromotion
                        ? (int) $featuredPromotion->priority
                        : null,

                'starts_at' =>
                    $featuredPromotion
                        ?->starts_at
                        ?->toISOString(),

                'ends_at' =>
                    $featuredPromotion
                        ?->ends_at
                        ?->toISOString(),
            ],

            /*
             * Availability is useful mainly for property-listing.
             * Other post types normally return null here.
             */
            'availability' => [
                'status' =>
                    $this->availability_status ?? null,

                'public_until' =>
                    $this->formatDate(
                        $this->availability_public_until
                            ?? null
                    ),

                'sold_at' =>
                    $this->formatDate(
                        $this->sold_at ?? null
                    ),
            ],

            'published_at' =>
                $this->formatDate(
                    $this->published_at ?? null
                ),
        ];
    }

    private function formatTaxonomies(): array
    {
        $terms = $this->taxonomyTerms ?? collect();

        return $terms
            ->groupBy('taxonomy_id')
            ->map(function ($taxonomyTerms) {
                $first = $taxonomyTerms->first();

                $taxonomy = $first?->taxonomy;

                if (!$taxonomy) {
                    return null;
                }

                return [
                    'taxonomy_id' =>
                        (int) $taxonomy->id,

                    'taxonomy_name' =>
                        $taxonomy->name,

                    'taxonomy_slug' =>
                        $taxonomy->slug,

                    'selected_term_ids' =>
                        $taxonomyTerms
                            ->pluck('id')
                            ->map(
                                fn ($id) => (int) $id
                            )
                            ->values()
                            ->all(),

                    'selected_terms' =>
                        $taxonomyTerms
                            ->map(
                                fn ($term) => [
                                    'id' =>
                                        (int) $term->id,

                                    'name' =>
                                        $term->name,

                                    'slug' =>
                                        $term->slug,
                                ]
                            )
                            ->values()
                            ->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function fullAddress(): ?string
    {
        $address = collect([
            $this->area_locality ?? null,
            $this->guest_city_name ?? null,
            $this->guest_state_name ?? null,
            $this->guest_country_name ?? null,
        ])
            ->filter(
                fn ($value) =>
                    $value !== null
                    && trim((string) $value) !== ''
            )
            ->unique()
            ->implode(', ');

        return $address !== ''
            ? $address
            : null;
    }

    private function formatMedia(
        mixed $media
    ): ?array {
        if (!$media) {
            return null;
        }

        return [
            'id' =>
                (int) $media->id,

            'url' =>
                $this->mediaUrl($media),

            'original_name' =>
                $media->original_name ?? null,

            'mime_type' =>
                $media->mime_type ?? null,

            'extension' =>
                $media->extension ?? null,

            'size' =>
                $media->size
                    ? (int) $media->size
                    : null,
        ];
    }

    private function mediaUrl(
        mixed $media
    ): ?string {
        if (!$media) {
            return null;
        }

        $url = $media->url ?? null;

        if (!$url) {
            return null;
        }

        if (
            str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
        ) {
            return $url;
        }

        return url($url);
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