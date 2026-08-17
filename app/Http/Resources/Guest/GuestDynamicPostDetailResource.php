<?php

namespace App\Http\Resources\Guest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class GuestDynamicPostDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $promotion = $this->getAttribute(
            '_guest_featured_promotion'
        );

        $featuredMedia = $this->getAttribute(
            '_guest_featured_media'
        );

        $galleryMedia = $this->getAttribute(
            '_guest_gallery_media'
        );

        if (!$galleryMedia instanceof Collection) {
            $galleryMedia = collect(
                is_array($galleryMedia)
                    ? $galleryMedia
                    : []
            );
        }

        return [
            'id' =>
                (int) $this->id,

            'listing_code' =>
                $this->listing_code ?? null,

            'title' =>
                $this->title ?? null,

            'slug' =>
                $this->slug ?? null,

            'content' =>
                $this->content ?? null,

            'excerpt' =>
                $this->excerpt ?? null,

            /*
            |--------------------------------------------------------------------------
            | Post Type
            |--------------------------------------------------------------------------
            */

            'post_type' =>
                $this->postType
                    ? [
                        'id' =>
                            (int) $this->postType->id,

                        'name' =>
                            $this->postType->name,

                        'slug' =>
                            $this->postType->slug,
                    ]
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Publication
            |--------------------------------------------------------------------------
            */

            'status' =>
                $this->status ?? null,

            'live_status' =>
                $this->live_status ?? null,

            'published_at' =>
                $this->formatDate(
                    $this->published_at ?? null
                ),

            /*
            |--------------------------------------------------------------------------
            | Location
            |--------------------------------------------------------------------------
            */

            'location' => [
                'country_id' =>
                    !empty($this->country_id)
                        ? (int) $this->country_id
                        : null,

                'country_name' =>
                    $this->guest_country_name
                    ?? null,

                'state_id' =>
                    !empty($this->state_id)
                        ? (int) $this->state_id
                        : null,

                'state_name' =>
                    $this->guest_state_name
                    ?? null,

                'city_id' =>
                    !empty($this->city_id)
                        ? (int) $this->city_id
                        : null,

                'city_name' =>
                    $this->guest_city_name
                    ?? null,

                'area_locality' =>
                    $this->area_locality ?? null,

                'full_address' =>
                    $this->fullAddress(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Featured Image
            |--------------------------------------------------------------------------
            */

            'featured_image_id' =>
                !empty($this->featured_image_id)
                    ? (int) $this->featured_image_id
                    : null,

            'featured_image' =>
                $this->mediaUrl(
                    $featuredMedia
                ),

            'featured_image_media' =>
                $this->formatMedia(
                    $featuredMedia
                ),

            /*
            |--------------------------------------------------------------------------
            | Gallery
            |--------------------------------------------------------------------------
            */

            'gallery_image_ids' =>
                $galleryMedia
                    ->pluck('id')
                    ->map(
                        fn ($id) => (int) $id
                    )
                    ->values()
                    ->all(),

            'gallery_images' =>
                $galleryMedia
                    ->map(
                        fn ($media) =>
                            $this->mediaUrl($media)
                    )
                    ->filter()
                    ->values()
                    ->all(),

            'gallery_image_files' =>
                $galleryMedia
                    ->map(
                        fn ($media) =>
                            $this->formatMedia($media)
                    )
                    ->filter()
                    ->values()
                    ->all(),

            /*
            |--------------------------------------------------------------------------
            | Taxonomies
            |--------------------------------------------------------------------------
            */

            'taxonomies' =>
                $this->formatTaxonomies(),

            /*
            |--------------------------------------------------------------------------
            | Custom Fields
            |--------------------------------------------------------------------------
            */

            'custom_fields' =>
                $this->formatCustomFields(),

            /*
            |--------------------------------------------------------------------------
            | Featured Promotion
            |--------------------------------------------------------------------------
            |
            | Admin notes / audit information are intentionally
            | not exposed to guest APIs.
            |
            */

            'featured' => [
                'is_featured' =>
                    $promotion !== null,

                'promotion_id' =>
                    $promotion
                        ? (int) $promotion->id
                        : null,

                'source' =>
                    $promotion?->source,

                'promotion_type' =>
                    $promotion?->promotion_type,

                'priority' =>
                    $promotion
                        ? (int) $promotion->priority
                        : null,

                'placements' => [
                    'home' => (bool) ($promotion?->show_on_home ?? false),
                    'search' => (bool) ($promotion?->show_on_search ?? false),
                    'property_detail' => (bool) ($promotion?->show_on_detail ?? false),
                ],

                'show_on_home' =>
                    (bool) ($promotion?->show_on_home ?? false),

                'show_on_search' =>
                    (bool) ($promotion?->show_on_search ?? false),

                'show_on_detail' =>
                    (bool) ($promotion?->show_on_detail ?? false),

                'starts_at' =>
                    $promotion
                        ?->starts_at
                        ?->toISOString(),

                'ends_at' =>
                    $promotion
                        ?->ends_at
                        ?->toISOString(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Availability
            |--------------------------------------------------------------------------
            |
            | Relevant mainly for property-listing.
            | Other post types normally return null values.
            |
            */

            'availability' => [
                'status' =>
                    $this->availability_status
                    ?? null,

                'sold_at' =>
                    $this->formatDate(
                        $this->sold_at ?? null
                    ),

                'public_until' =>
                    $this->formatDate(
                        $this->availability_public_until
                            ?? null
                    ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Public Relationships
            |--------------------------------------------------------------------------
            */

            'relationships' => [
                'parent' =>
                    $this->formatRelatedPost(
                        $this->relationLoaded('parent')
                            ? $this->parent
                            : null
                    ),

                'children' =>
                    $this->relationLoaded('children')
                        ? $this->children
                            ->map(
                                fn ($post) =>
                                    $this->formatRelatedPost(
                                        $post
                                    )
                            )
                            ->filter()
                            ->values()
                            ->all()
                        : [],
            ],

            'created_at' =>
                $this->formatDate(
                    $this->created_at ?? null
                ),

            'updated_at' =>
                $this->formatDate(
                    $this->updated_at ?? null
                ),
        ];
    }

    private function formatTaxonomies(): array
    {
        $terms =
            $this->taxonomyTerms
            ?? collect();

        return $terms
            ->groupBy('taxonomy_id')
            ->map(function ($taxonomyTerms) {
                $first =
                    $taxonomyTerms->first();

                $taxonomy =
                    $first?->taxonomy;

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
                                fn ($id) =>
                                    (int) $id
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

    private function formatCustomFields(): array
    {
        $meta =
            $this->meta
            ?? collect();

        $repeaterValues =
            $this->getAttribute(
                '_guest_repeater_values'
            );

        if (!$repeaterValues instanceof Collection) {
            $repeaterValues = collect(
                is_array($repeaterValues)
                    ? $repeaterValues
                    : []
            );
        }

        $mediaMap =
            $this->getAttribute(
                '_guest_media_map'
            );

        if (!$mediaMap instanceof Collection) {
            $mediaMap = collect();
        }

        return $meta
            ->map(function ($item) use (
                $repeaterValues,
                $mediaMap
            ) {
                $field =
                    $item->customField
                    ?? null;

                if (!$field) {
                    return null;
                }

                $fieldType =
                    $field->field_type
                    ?? null;

                $valueJson =
                    $this->decodeJsonValue(
                        $item->value_json
                        ?? null
                    );

                $response = [
                    'id' =>
                        (int) $item->id,

                    'custom_field_id' =>
                        (int) $field->id,

                    'name' =>
                        $field->name
                        ?? null,

                    'label' =>
                        $field->label
                        ?? $field->name
                        ?? null,

                    'slug' =>
                        $field->field_name_slug
                        ?? $field->slug
                        ?? null,

                    'field_type' =>
                        $fieldType,

                    'value_string' =>
                        $item->value_string
                        ?? null,

                    'value_text' =>
                        $item->value_text
                        ?? null,

                    'value_number' =>
                        $item->value_number
                        ?? null,

                    'value_date' =>
                        $item->value_date
                        ?? null,

                    'value_datetime' =>
                        $this->formatDate(
                            $item->value_datetime
                            ?? null
                        ),

                    'value_json' =>
                        $valueJson,
                ];

                /*
                 * Media / file custom fields.
                 */
                if (
                    in_array(
                        $fieldType,
                        [
                            'media',
                            'file',
                        ],
                        true
                    )
                ) {
                    $files =
                        $this->formatCustomFieldMedia(
                            $valueJson,
                            $mediaMap
                        );

                    $response['media_files'] =
                        $files;

                    $response['media_urls'] =
                        collect($files)
                            ->pluck('url')
                            ->filter()
                            ->values()
                            ->all();
                }

                /*
                 * Repeater values were loaded by service,
                 * therefore no query happens here.
                 */
                if (
                    $fieldType === 'repeater'
                ) {
                    $response['repeaters'] =
                        $repeaterValues
                            ->get(
                                (int) $field->id,
                                []
                            );
                }

                return $response;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function formatCustomFieldMedia(
        mixed $value,
        Collection $mediaMap
    ): array {
        if (!$value) {
            return [];
        }

        if (
            is_array($value)
            && isset($value['media'])
            && is_array($value['media'])
        ) {
            $value = $value['media'];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->map(function ($item) use (
                $mediaMap
            ) {
                if (is_numeric($item)) {
                    $media =
                        $mediaMap->get(
                            (int) $item
                        );

                    return $media
                        ? $this->formatMedia(
                            $media
                        )
                        : null;
                }

                if (!is_array($item)) {
                    return null;
                }

                $mediaId =
                    $item['id']
                    ?? $item['media_id']
                    ?? null;

                if (
                    $mediaId
                    && is_numeric($mediaId)
                ) {
                    $media =
                        $mediaMap->get(
                            (int) $mediaId
                        );

                    if ($media) {
                        return $this->formatMedia(
                            $media
                        );
                    }
                }

                $path =
                    $item['path']
                    ?? null;

                $url =
                    $item['url']
                    ?? null;

                if (!$url && $path) {
                    $url =
                        $this->storageUrl(
                            $path,
                            $item['disk']
                                ?? 'public'
                        );
                }

                if (!$url) {
                    return null;
                }

                return array_merge(
                    $item,
                    [
                        'url' => $url,
                    ]
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function formatRelatedPost(
        mixed $post
    ): ?array {
        if (!$post) {
            return null;
        }

        return [
            'id' =>
                (int) $post->id,

            'post_type_id' =>
                !empty($post->post_type_id)
                    ? (int) $post->post_type_id
                    : null,

            'listing_code' =>
                $post->listing_code
                ?? null,

            'title' =>
                $post->title
                ?? null,

            'slug' =>
                $post->slug
                ?? null,
        ];
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

            'disk' =>
                $media->disk
                ?? 'public',

            'path' =>
                $media->path
                ?? null,

            'url' =>
                $this->mediaUrl(
                    $media
                ),

            'file_name' =>
                $media->file_name
                ?? null,

            'original_name' =>
                $media->original_name
                ?? null,

            'mime_type' =>
                $media->mime_type
                ?? null,

            'extension' =>
                $media->extension
                ?? null,

            'size' =>
                !empty($media->size)
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

        $url =
            $media->url
            ?? null;

        if ($url) {
            if (
                str_starts_with(
                    $url,
                    'http://'
                )
                || str_starts_with(
                    $url,
                    'https://'
                )
            ) {
                return $url;
            }

            return url($url);
        }

        if (!empty($media->path)) {
            return $this->storageUrl(
                $media->path,
                $media->disk
                    ?? 'public'
            );
        }

        return null;
    }

    private function storageUrl(
        string $path,
        string $disk = 'public'
    ): string {
        if (
            str_starts_with(
                $path,
                'http://'
            )
            || str_starts_with(
                $path,
                'https://'
            )
        ) {
            return $path;
        }

        $url =
            Storage::disk($disk)
                ->url($path);

        if (
            str_starts_with(
                $url,
                'http://'
            )
            || str_starts_with(
                $url,
                'https://'
            )
        ) {
            return $url;
        }

        return url($url);
    }

    private function decodeJsonValue(
        mixed $value
    ): mixed {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $decoded =
            json_decode(
                $value,
                true
            );

        return json_last_error()
            === JSON_ERROR_NONE
                ? $decoded
                : $value;
    }

    private function fullAddress(): ?string
    {
        $address =
            collect([
                $this->area_locality
                    ?? null,

                $this->guest_city_name
                    ?? null,

                $this->guest_state_name
                    ?? null,

                $this->guest_country_name
                    ?? null,
            ])
                ->filter(
                    fn ($value) =>
                        $value !== null
                        && trim(
                            (string) $value
                        ) !== ''
                )
                ->unique()
                ->implode(', ');

        return $address !== ''
            ? $address
            : null;
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
            return $value
                ->toISOString();
        }

        return $value;
    }
}