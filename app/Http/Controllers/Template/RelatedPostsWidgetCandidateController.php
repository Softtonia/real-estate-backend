<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\PageBuilder\Services\RelatedPostQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

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
                    'posts' => $this->formatFrontendPosts($displayCandidates),

                    /*
                 * Exact matched result only.
                 */
                    'strict_options' => $strictCandidates->values(),
                    'strict_posts' => $this->formatFrontendPosts($strictCandidates),

                    /*
                 * Suggestions shown when exact match is empty.
                 */
                    'suggested_options' => $suggestedCandidates->values(),
                    'suggested_posts' => $this->formatFrontendPosts($suggestedCandidates),

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

    private function formatFrontendPosts($posts): array
    {
        return collect($posts)
            ->map(function ($post) {
                $post = is_array($post) ? $post : (array) $post;

                return [
                    'id' => isset($post['id']) ? (int) $post['id'] : null,
                    'value' => isset($post['value']) ? (int) $post['value'] : (isset($post['id']) ? (int) $post['id'] : null),
                    'label' => $post['label'] ?? $post['title'] ?? null,
                    'title' => $post['title'] ?? $post['label'] ?? null,
                    'slug' => $post['slug'] ?? null,
                    'listing_code' => $post['listing_code'] ?? null,
                    'post_type_id' => isset($post['post_type_id']) ? (int) $post['post_type_id'] : null,
                    'status' => $post['status'] ?? null,
                    'live_status' => $post['live_status'] ?? null,
                    'country_id' => $post['country_id'] ?? null,
                    'state_id' => $post['state_id'] ?? null,
                    'city_id' => $post['city_id'] ?? null,
                    'area_locality' => $post['area_locality'] ?? null,
                ];
            })
            ->values()
            ->toArray();
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
