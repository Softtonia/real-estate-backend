<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\PageBuilder\Services\RelatedPostQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

class RelatedPostsWidgetCandidateController extends Controller
{
    public function __construct(
        protected RelatedPostQueryService $relatedPostQueryService
    ) {}

    public function candidates(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'current_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'dynamic_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'entity_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],

            'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],

            'selected_taxonomy_term_ids' => ['nullable'],
            'selected_post_ids' => ['nullable'],

            'settings' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $currentPostId = $payload['current_post_id']
                ?? $payload['dynamic_post_id']
                ?? $payload['post_id']
                ?? $payload['entity_id']
                ?? null;

            $currentPost = $currentPostId
                ? DynamicPost::query()->find((int) $currentPostId)
                : null;

            $settings = $payload['settings'] ?? [];

            $context = [
                'entity_id' => $payload['entity_id'] ?? $currentPostId,
                'current_post_id' => $currentPostId,
                'post_id' => $currentPostId,
                'dynamic_post_id' => $currentPostId,
                'post_type_id' => $payload['post_type_id'] ?? $currentPost?->post_type_id,
                'selected_taxonomy_term_ids' => $this->normalizeIds(
                    $payload['selected_taxonomy_term_ids'] ?? []
                ),
            ];

            /*
         * 1. Exact matched posts.
         * Uses all selected filters:
         * post type + taxonomy + location + query filter.
         */
            $strictCandidates = $this->relatedPostQueryService->getCandidatePosts(
                $settings,
                $currentPost,
                $context
            );

            /*
         * 2. Frontend display posts.
         * If exact match is empty, show suggested posts using:
         * post type + query filter only.
         * This makes frontend easy and dynamic posts visible.
         */
            $displayCandidates = $strictCandidates;
            $suggestedCandidates = collect();
            $isStrictMatch = true;

            if ($strictCandidates->isEmpty()) {
                $suggestionSettings = $this->makeSuggestionSettings($settings);

                $suggestedCandidates = $this->relatedPostQueryService->getCandidatePosts(
                    $suggestionSettings,
                    $currentPost,
                    $context
                );

                if ($suggestedCandidates->isNotEmpty()) {
                    $displayCandidates = $suggestedCandidates;
                    $isStrictMatch = false;
                }
            }

            $normalizedSettings = $this->relatedPostQueryService->normalizeSettings($settings);

            return response()->json([
                'status' => true,
                'message' => $isStrictMatch
                    ? 'Matched related posts fetched successfully.'
                    : 'No exact related posts found. Suggested dynamic posts fetched successfully.',

                'data' => [
                    'current_post_id' => $currentPostId ? (int) $currentPostId : null,
                    'post_type_id' => ! empty($context['post_type_id'])
                        ? (int) $context['post_type_id']
                        : null,

                    /*
                 * Frontend should use this for dropdown/list.
                 */
                    'options' => $displayCandidates->values(),

                    /*
                 * Frontend can use this for cards/list display.
                 */
                    'posts' => $this->formatFullDynamicPosts($displayCandidates),
                    'full_posts' => $this->formatFullDynamicPosts($displayCandidates),

                    /*
                 * Exact matched result only.
                 */
                    'strict_options' => $strictCandidates->values(),
                    'strict_posts' => $this->formatFullDynamicPosts($strictCandidates),
                    /*
                 * Suggestions shown when exact match is empty.
                 */
                    'suggested_options' => $suggestedCandidates->values(),
                    'suggested_posts' => $this->formatFullDynamicPosts($suggestedCandidates),

                    'matched_count' => $strictCandidates->count(),
                    'display_count' => $displayCandidates->count(),
                    'suggested_count' => $suggestedCandidates->count(),

                    'is_strict_match' => $isStrictMatch,
                    'frontend_should_use' => 'data.options or data.posts',

                    'selected_post_ids' => $this->normalizeIds(
                        $payload['selected_post_ids']
                            ?? data_get($settings, 'selected_post_ids', [])
                    ),

                    'applied_filters' => [
                        'match_post_type' => $normalizedSettings['match_post_type'],
                        'match_taxonomy_terms' => $normalizedSettings['match_taxonomy_terms'],
                        'match_locations' => $normalizedSettings['match_locations'],
                        'exclude_current' => $normalizedSettings['exclude_current'],
                        'query' => $normalizedSettings['query'],
                    ],

                    'note' => $isStrictMatch
                        ? 'Posts matched all selected filters.'
                        : 'Exact taxonomy/location match was empty, so options contain suggested posts using post type and query filter.',
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch matched related posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    private function makeSuggestionSettings(array $settings): array
    {
        /*
     * Keep these filters:
     * - post type
     * - exclude current
     * - query_mapping like price <= 5000
     *
     * Disable strict filters:
     * - taxonomy
     * - location
     */
        $settings['match_taxonomy_terms'] = false;
        $settings['match_locations'] = false;

        data_set($settings, 'taxonomy_mapping.enabled', false);
        data_set($settings, 'location_mapping.enabled', false);

        return $settings;
    }

    private function formatFullDynamicPosts($posts): array
    {
        $ids = collect($posts)
            ->map(function ($post) {
                $post = is_array($post) ? $post : (array) $post;

                return $post['id'] ?? null;
            })
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        if (empty($ids)) {
            return [];
        }

        $dynamicPosts = DynamicPost::query()
            ->with([
                'postType',
                'parent:id,post_type_id,title,slug,status,live_status',
                'taxonomyTerms.taxonomy',
                'meta.customField.options',
                'meta.customField.repeaters.options',
            ])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn($id) => $dynamicPosts->get($id))
            ->filter()
            ->map(fn(DynamicPost $post) => $this->formatFullDynamicPost($post))
            ->values()
            ->toArray();
    }

    private function formatFullDynamicPost(DynamicPost $post): array
    {
        $data = $post->toArray();

        $featuredMedia = $this->formatMediaFileById($post->featured_image_id ?? null);
        $galleryMedia = $this->formatMediaFilesByIds($post->gallery_image_ids ?? []);

        $data['value'] = (int) $post->id;
        $data['label'] = $this->dynamicPostLabel($post);
        $data['display_id'] = $post->listing_code ?? null;

        $data['featured_image'] = $featuredMedia['url'] ?? null;
        $data['featured_image_media'] = $featuredMedia;

        $data['gallery_images'] = collect($galleryMedia)
            ->pluck('url')
            ->filter()
            ->values()
            ->toArray();

        $data['gallery_image_files'] = $galleryMedia;

        $data['selected_taxonomies'] = $this->formatSelectedTaxonomies($post);
        $data['custom_fields'] = $this->formatCustomFields($post);

        $data['country_id'] = $post->country_id ? (int) $post->country_id : null;
        $data['state_id'] = $post->state_id ? (int) $post->state_id : null;
        $data['city_id'] = $post->city_id ? (int) $post->city_id : null;
        $data['area_locality'] = $post->area_locality ?? null;

        return $data;
    }

    private function dynamicPostLabel(DynamicPost $post): string
    {
        $title = $post->title
            ?? $post->slug
            ?? ('Post #' . $post->id);

        if (! empty($post->listing_code)) {
            return $post->listing_code . ' - ' . $title;
        }

        return (string) $title;
    }

    private function formatSelectedTaxonomies(DynamicPost $post): array
    {
        return $post->taxonomyTerms
            ->groupBy(fn($term) => $term->taxonomy?->slug ?? 'taxonomy')
            ->map(function ($terms, $taxonomySlug) {
                $taxonomy = $terms->first()?->taxonomy;

                return [
                    'taxonomy_id' => $taxonomy?->id ? (int) $taxonomy->id : null,
                    'taxonomy_name' => $taxonomy?->name,
                    'taxonomy_slug' => $taxonomySlug,
                    'terms' => $terms
                        ->map(fn($term) => [
                            'id' => (int) $term->id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                        ])
                        ->values()
                        ->toArray(),
                ];
            })
            ->values()
            ->toArray();
    }

    private function formatCustomFields(DynamicPost $post): array
    {
        return collect($post->meta ?? [])
            ->mapWithKeys(function ($meta) {
                $field = $meta->customField ?? null;

                $key = $field?->field_name_slug
                    ?? $field?->slug
                    ?? $field?->field_key
                    ?? $field?->field_label
                    ?? ('field_' . ($field?->id ?? $meta->id));

                return [
                    $key => [
                        'custom_field_id' => $field?->id ? (int) $field->id : null,
                        'label' => $field?->field_label ?? $field?->name ?? $key,
                        'type' => $field?->field_type ?? null,
                        'value' => $this->metaValue($meta),
                        'raw' => is_object($meta) && method_exists($meta, 'toArray')
                            ? $meta->toArray()
                            : (array) $meta,
                    ],
                ];
            })
            ->toArray();
    }

    private function metaValue($meta): mixed
    {
        foreach (
            [
                'value_number',
                'value_string',
                'value_text',
                'value_json',
                'value_date',
                'value_datetime',
                'value',
                'field_value',
                'meta_value',
            ] as $column
        ) {
            if (! isset($meta->{$column}) || $meta->{$column} === null || $meta->{$column} === '') {
                continue;
            }

            $value = $meta->{$column};

            if ($column === 'value_json' && is_string($value)) {
                $decoded = json_decode($value, true);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }

            return $value;
        }

        return null;
    }

    private function formatMediaFileById(null|int|string $mediaId): ?array
    {
        if (empty($mediaId)) {
            return null;
        }

        $media = MediaFile::query()->find((int) $mediaId);

        return $media ? $this->formatMediaFile($media) : null;
    }

    private function formatMediaFilesByIds(array|string|null $mediaIds): array
    {
        $ids = $this->normalizeIds($mediaIds);

        if (empty($ids)) {
            return [];
        }

        $mediaFiles = MediaFile::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn($id) => $mediaFiles->get((int) $id))
            ->filter()
            ->map(fn(MediaFile $media) => $this->formatMediaFile($media))
            ->values()
            ->toArray();
    }

    private function formatMediaFile(MediaFile $media): array
    {
        return [
            'id' => (int) $media->id,
            'disk' => $media->disk ?: 'public',
            'context' => $media->context ?? null,
            'post_type_slug' => $media->post_type_slug ?? null,
            'field_slug' => $media->field_slug ?? null,
            'directory' => $media->directory ?? null,
            'path' => $media->path ?? null,
            'url' => $this->mediaFileUrl($media),
            'file_name' => $media->file_name ?? null,
            'original_name' => $media->original_name ?? null,
            'mime_type' => $media->mime_type ?? null,
            'extension' => $media->extension ?? null,
            'size' => $media->size ?? null,
            'size_kb' => $media->size ? round(((int) $media->size) / 1024, 2) : null,
            'created_at' => optional($media->created_at)->toDateTimeString(),
            'updated_at' => optional($media->updated_at)->toDateTimeString(),
        ];
    }

    private function mediaFileUrl(MediaFile $media): ?string
    {
        if (! empty($media->url)) {
            return $media->url;
        }

        if (empty($media->path)) {
            return null;
        }

        try {
            return Storage::disk($media->disk ?: 'public')->url($media->path);
        } catch (Throwable) {
            return $media->path;
        }
    }
    private function getPayload(Request $request): array
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            $payload = $request->all();
        }

        if (empty($payload) && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return is_array($payload) ? $payload : [];
    }

    private function normalizeIds(mixed $ids): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = str_contains($ids, ',') ? explode(',', $ids) : [$ids];
            }
        }

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }
}
