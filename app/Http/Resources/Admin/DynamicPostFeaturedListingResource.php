<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DynamicPostFeaturedListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $featuredImage = $this->relationLoaded('featuredImage')
            ? $this->featuredImage
            : null;

        $galleryMedia = $this->relationLoaded('galleryMediaFiles')
            ? $this->galleryMediaFiles
            : collect();

        return [
            'id' => (int) $this->id,

            'listing_code' => $this->listing_code,
            'display_id' => $this->listing_code,

            'post_type_id' => $this->post_type_id
                ? (int) $this->post_type_id
                : null,

            'post_type' => $this->postType
                ? [
                    'id' => (int) $this->postType->id,
                    'name' => $this->postType->name,
                    'slug' => $this->postType->slug,
                ]
                : null,

            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->content,

            'status' => $this->status,
            'live_status' => $this->live_status,

            'is_published' => $this->status === 'published',

            'is_approved' => $this->live_status === 'approve',

            'is_active' =>
                $this->status === 'published'
                && $this->live_status === 'approve',

            'is_under_review' => in_array(
                $this->live_status,
                [
                    'under_review',
                    'submit',
                    'assigned',
                    'in_verification',
                ],
                true
            ),

            'is_rejected' => in_array(
                $this->live_status,
                [
                    'reject',
                    'rejected',
                    'disapprove',
                ],
                true
            ),

            'author_id' => $this->author_id
                ? (int) $this->author_id
                : null,

            'listing_owner_id' => $this->author_id
                ? (int) $this->author_id
                : null,

            'author' => $this->author
                ? [
                    'id' => (int) $this->author->id,
                    'first_name' => $this->author->first_name,
                    'last_name' => $this->author->last_name,
                    'email' => $this->author->email,
                    'phone' => $this->author->phone,
                    'role_id' => $this->author->role_id
                        ? (int) $this->author->role_id
                        : null,
                ]
                : null,

            'parent_id' => $this->parent_id
                ? (int) $this->parent_id
                : null,

            'parent' => $this->parent
                ? [
                    'id' => (int) $this->parent->id,
                    'post_type_id' => $this->parent->post_type_id
                        ? (int) $this->parent->post_type_id
                        : null,
                    'title' => $this->parent->title,
                    'slug' => $this->parent->slug,
                    'status' => $this->parent->status,
                    'live_status' => $this->parent->live_status,
                ]
                : null,

            'featured_image_id' => $this->featured_image_id
                ? (int) $this->featured_image_id
                : null,

            'featured_image' => $featuredImage?->url,

            'featured_image_media' => $this->formatMedia(
                $featuredImage
            ),

            'gallery_image_ids' => collect(
                $this->gallery_image_ids ?? []
            )
                ->map(fn ($id) => (int) $id)
                ->values()
                ->toArray(),

            'gallery_images' => $galleryMedia
                ->pluck('url')
                ->filter()
                ->values()
                ->toArray(),

            'gallery_image_files' => $galleryMedia
                ->map(
                    fn ($media) =>
                    $this->formatMedia($media)
                )
                ->values()
                ->toArray(),

            'location' => [
                'country_id' => $this->country_id
                    ? (int) $this->country_id
                    : null,

                'country_name' => $this->country?->name,

                'state_id' => $this->state_id
                    ? (int) $this->state_id
                    : null,

                'state_name' => $this->state?->name,

                'city_id' => $this->city_id
                    ? (int) $this->city_id
                    : null,

                'city_name' => $this->city?->name,

                'area_locality' =>
                    $this->area_locality,

                'full_address' => collect([
                    $this->area_locality,
                    $this->city?->name,
                    $this->state?->name,
                    $this->country?->name,
                ])
                    ->filter(
                        fn ($value) =>
                        $value !== null
                        && $value !== ''
                    )
                    ->implode(', ') ?: null,
            ],

            'country' => $this->country?->name,
            'state' => $this->state?->name,
            'city' => $this->city?->name,

            'country_name' => $this->country?->name,
            'state_name' => $this->state?->name,
            'city_name' => $this->city?->name,

            'area_locality' => $this->area_locality,

            'selected_taxonomies' =>
                $this->formatTaxonomies(),

            'taxonomies' =>
                $this->formatTaxonomies(),

            'meta' => $this->meta
                ->map(function ($meta) {
                    return [
                        'id' => (int) $meta->id,

                        'entity_id' => (int) $meta->entity_id,

                        'entity_type' => $meta->entity_type,

                        'custom_field_id' =>
                            (int) $meta->custom_field_id,

                        'value' => $meta->value ?? null,

                        'value_text' =>
                            $meta->value_text ?? null,

                        'value_number' =>
                            $meta->value_number ?? null,

                        'value_boolean' =>
                            $meta->value_boolean ?? null,

                        'value_date' =>
                            $meta->value_date ?? null,

                        'value_json' =>
                            $meta->value_json ?? null,

                        'custom_field' =>
                            $meta->customField
                                ? [
                                    'id' =>
                                        (int) $meta
                                            ->customField
                                            ->id,

                                    'label' =>
                                        $meta
                                            ->customField
                                            ->label
                                            ?? $meta
                                                ->customField
                                                ->field_label
                                            ?? null,

                                    'name' =>
                                        $meta
                                            ->customField
                                            ->name
                                            ?? $meta
                                                ->customField
                                                ->field_name_slug
                                            ?? null,

                                    'field_type' =>
                                        $meta
                                            ->customField
                                            ->field_type,

                                    'options' =>
                                        $meta
                                            ->customField
                                            ->relationLoaded(
                                                'options'
                                            )
                                            ? $meta
                                                ->customField
                                                ->options
                                                ->map(
                                                    fn ($option) => [
                                                        'id' =>
                                                            (int) $option->id,
                                                        'name' =>
                                                            $option->name,
                                                        'value' =>
                                                            $option->value,
                                                    ]
                                                )
                                                ->values()
                                                ->toArray()
                                            : [],
                                ]
                                : null,
                    ];
                })
                ->values()
                ->toArray(),

            'keywords' => $this->keywords
                ->map(fn ($keyword) => [
                    'id' => (int) $keyword->id,
                    'name' => $keyword->name,
                    'slug' => $keyword->slug,
                ])
                ->values()
                ->toArray(),

            'selected_keywords' => $this->keywords
                ->map(fn ($keyword) => [
                    'id' => (int) $keyword->id,
                    'name' => $keyword->name,
                    'slug' => $keyword->slug,
                ])
                ->values()
                ->toArray(),

            'assigned_users' => $this->assignedUsers
                ->map(fn ($user) => [
                    'id' => (int) $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_id' => $user->role_id
                        ? (int) $user->role_id
                        : null,
                    'assigned_by' =>
                        $user->pivot?->assigned_by
                            ? (int) $user->pivot->assigned_by
                            : null,
                ])
                ->values()
                ->toArray(),

            'relationships' => $this->relationships
                ->map(function ($relation) {
                    return [
                        'id' => (int) $relation->id,

                        'related_post_type_id' =>
                            $relation->related_post_type_id
                                ? (int) $relation
                                    ->related_post_type_id
                                : null,

                        'related_post_type' =>
                            $relation->relatedPostType
                                ? [
                                    'id' =>
                                        (int) $relation
                                            ->relatedPostType
                                            ->id,

                                    'name' =>
                                        $relation
                                            ->relatedPostType
                                            ->name,

                                    'slug' =>
                                        $relation
                                            ->relatedPostType
                                            ->slug,
                                ]
                                : null,

                        'related_post_id' =>
                            $relation->related_post_id
                                ? (int) $relation->related_post_id
                                : null,

                        'related_post' =>
                            $relation->relatedPost
                                ? [
                                    'id' =>
                                        (int) $relation
                                            ->relatedPost
                                            ->id,

                                    'listing_code' =>
                                        $relation
                                            ->relatedPost
                                            ->listing_code,

                                    'title' =>
                                        $relation
                                            ->relatedPost
                                            ->title,

                                    'slug' =>
                                        $relation
                                            ->relatedPost
                                            ->slug,

                                    'status' =>
                                        $relation
                                            ->relatedPost
                                            ->status,

                                    'live_status' =>
                                        $relation
                                            ->relatedPost
                                            ->live_status,
                                ]
                                : null,
                    ];
                })
                ->values()
                ->toArray(),

            'availability_status' =>
                $this->availability_status ?? null,

            'availability_changed_at' =>
                optional(
                    $this->availability_changed_at
                )?->toISOString(),

            'availability_public_until' =>
                optional(
                    $this->availability_public_until
                )?->toISOString(),

            'availability_hidden_at' =>
                optional(
                    $this->availability_hidden_at
                )?->toISOString(),

            'sold_at' =>
                optional(
                    $this->sold_at
                )?->toISOString(),

            'sort_order' => (int) ($this->sort_order ?? 0),

            'published_at' =>
                optional(
                    $this->published_at
                )?->toISOString(),

            'created_at' =>
                optional(
                    $this->created_at
                )?->toISOString(),

            'updated_at' =>
                optional(
                    $this->updated_at
                )?->toISOString(),
        ];
    }

    private function formatTaxonomies(): array
    {
        return $this->taxonomyTerms
            ->groupBy(function ($term) {
                return $term->pivot?->taxonomy_id
                    ?? $term->taxonomy?->id;
            })
            ->map(function ($terms) {
                $first = $terms->first();

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
                        $terms
                            ->pluck('id')
                            ->map(
                                fn ($id) =>
                                (int) $id
                            )
                            ->values()
                            ->toArray(),

                    'selected_terms' =>
                        $terms
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
                            ->toArray(),
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    private function formatMedia(
        $media
    ): ?array {
        if (!$media) {
            return null;
        }

        return [
            'id' => (int) $media->id,
            'disk' => $media->disk,
            'context' => $media->context,
            'post_type_slug' =>
                $media->post_type_slug,
            'field_slug' => $media->field_slug,
            'directory' => $media->directory,
            'path' => $media->path,
            'url' => $media->url,
            'file_name' => $media->file_name,
            'original_name' =>
                $media->original_name,
            'mime_type' => $media->mime_type,
            'extension' => $media->extension,
            'size' => $media->size
                ? (int) $media->size
                : null,
            'size_kb' => $media->size
                ? round(
                    $media->size / 1024,
                    2
                )
                : null,
        ];
    }
}