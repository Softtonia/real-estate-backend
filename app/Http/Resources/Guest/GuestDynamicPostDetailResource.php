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
            $galleryMedia = collect();
        }

        return [
            'id' => (int) $this->id,

            'listing_code' => $this->listing_code,

            'title' => $this->title,

            'slug' => $this->slug,

            'content' => $this->content,

            'excerpt' => $this->excerpt,

            'post_type' => $this->postType
                ? [
                    'id' => (int) $this->postType->id,
                    'name' => $this->postType->name,
                    'slug' => $this->postType->slug,
                ]
                : null,

            'location' => [
                'country' =>
                    $this->guest_country_name,

                'state' =>
                    $this->guest_state_name,

                'city' =>
                    $this->guest_city_name,

                'area_locality' =>
                    $this->area_locality,

                'full_address' =>
                    $this->fullAddress(),
            ],

            'featured_image' =>
                $this->mediaUrl(
                    $featuredMedia
                ),

            'gallery_images' =>
                $galleryMedia
                    ->map(
                        fn ($media) =>
                            $this->mediaUrl($media)
                    )
                    ->filter()
                    ->values()
                    ->all(),

            'taxonomies' =>
                $this->formatTaxonomies(),

            'custom_fields' =>
                $this->formatCustomFields(),

            'featured' => [
                'is_featured' =>
                    $promotion !== null,

                'ends_at' =>
                    $promotion
                        ?->ends_at
                        ?->toISOString(),
            ],

            'availability_status' =>
                $this->availability_status,

            'published_at' =>
                $this->formatDate(
                    $this->published_at
                ),
        ];
    }

    private function formatTaxonomies(): array
    {
        $terms = $this->taxonomyTerms
            ?? collect();

        return $terms
            ->filter(
                fn ($term) =>
                    $term->taxonomy !== null
            )
            ->groupBy(
                fn ($term) =>
                    $term->taxonomy->slug
            )
            ->map(
                function ($items) {
                    return $items
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
                        ->all();
                }
            )
            ->toArray();
    }

    private function formatCustomFields(): array
    {
        $meta = $this->meta
            ?? collect();

        $mediaMap = $this->getAttribute(
            '_guest_media_map'
        );

        if (!$mediaMap instanceof Collection) {
            $mediaMap = collect();
        }

        return $meta
            ->mapWithKeys(
                function ($item) use (
                    $mediaMap
                ) {
                    $field =
                        $item->customField;

                    if (!$field) {
                        return [];
                    }

                    $slug =
                        $field->field_name_slug
                        ?? $field->slug
                        ?? null;

                    if (!$slug) {
                        return [];
                    }

                    $value =
                        $this->resolveMetaValue(
                            $item
                        );

                    if (
                        in_array(
                            $field->field_type,
                            [
                                'media',
                                'file',
                            ],
                            true
                        )
                    ) {
                        $value =
                            $this->resolveMediaValue(
                                $value,
                                $mediaMap
                            );
                    }

                    return [
                        $slug => $value,
                    ];
                }
            )
            ->toArray();
    }

    private function resolveMetaValue(
        mixed $item
    ): mixed {
        if (
            $item->value_number
            !== null
        ) {
            return $item->value_number + 0;
        }

        if (
            $item->value_text
            !== null
        ) {
            return $item->value_text;
        }

        if (
            $item->value_string
            !== null
        ) {
            return $item->value_string;
        }

        if (
            $item->value_date
            !== null
        ) {
            return $item->value_date;
        }

        if (
            $item->value_datetime
            !== null
        ) {
            return $this->formatDate(
                $item->value_datetime
            );
        }

        if (
            $item->value_json
            !== null
        ) {
            return $this->decodeJson(
                $item->value_json
            );
        }

        return null;
    }

    private function resolveMediaValue(
        mixed $value,
        Collection $mediaMap
    ): mixed {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        if (is_numeric($value)) {
            return $this->mediaUrl(
                $mediaMap->get(
                    (int) $value
                )
            );
        }

        if (!is_array($value)) {
            return null;
        }

        /*
         * Support:
         *
         * [1, 2]
         *
         * or:
         *
         * [
         *   ['id' => 1],
         *   ['media_id' => 2]
         * ]
         *
         * or:
         *
         * ['media' => [...]]
         */
        if (
            isset($value['media'])
            && is_array($value['media'])
        ) {
            $value = $value['media'];
        }

        $urls = collect($value)
            ->map(
                function ($item) use (
                    $mediaMap
                ) {
                    if (is_numeric($item)) {
                        return $this->mediaUrl(
                            $mediaMap->get(
                                (int) $item
                            )
                        );
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
                        return $this->mediaUrl(
                            $mediaMap->get(
                                (int) $mediaId
                            )
                        );
                    }

                    if (
                        !empty($item['url'])
                    ) {
                        return $this->absoluteUrl(
                            $item['url']
                        );
                    }

                    return null;
                }
            )
            ->filter()
            ->values()
            ->all();

        if (count($urls) === 0) {
            return null;
        }

        if (count($urls) === 1) {
            return $urls[0];
        }

        return $urls;
    }

    private function decodeJson(
        mixed $value
    ): mixed {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode(
            $value,
            true
        );

        if (
            json_last_error()
            !== JSON_ERROR_NONE
        ) {
            return $value;
        }

        /*
         * Some existing DB values may be
         * double JSON encoded.
         *
         * Example:
         * "\"[]\""
         */
        if (is_string($decoded)) {
            $secondDecoded =
                json_decode(
                    $decoded,
                    true
                );

            if (
                json_last_error()
                === JSON_ERROR_NONE
            ) {
                return $secondDecoded;
            }
        }

        return $decoded;
    }

    private function mediaUrl(
        mixed $media
    ): ?string {
        if (!$media) {
            return null;
        }

        if (!empty($media->url)) {
            return $this->absoluteUrl(
                $media->url
            );
        }

        if (empty($media->path)) {
            return null;
        }

        $url = Storage::disk(
            $media->disk
            ?? 'public'
        )->url(
            $media->path
        );

        return $this->absoluteUrl(
            $url
        );
    }

    private function absoluteUrl(
        string $value
    ): string {
        if (
            str_starts_with(
                $value,
                'http://'
            )
            || str_starts_with(
                $value,
                'https://'
            )
        ) {
            return $value;
        }

        return url($value);
    }

    private function fullAddress(): ?string
    {
        $address = collect([
            $this->area_locality,
            $this->guest_city_name,
            $this->guest_state_name,
            $this->guest_country_name,
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