<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\PageBuilder\Services\RelatedPostQueryService;
use App\PageBuilder\Widgets\RelatedPostsWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RelatedPostsWidgetPreviewController extends Controller
{
    public function __construct(
        protected RelatedPostsWidget $relatedPostsWidget,
        protected RelatedPostQueryService $relatedPostQueryService
    ) {
    }

    public function schema(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Related posts widget schema fetched successfully.',
            'data' => $this->relatedPostsWidget->sidebarItem(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'current_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'dynamic_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'entity_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'selected_taxonomy_term_ids' => ['nullable'],
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
                ? DynamicPost::find((int) $currentPostId)
                : null;

            $context = [
                'entity_id' => $payload['entity_id'] ?? $currentPostId,
                'current_post_id' => $currentPostId,
                'post_id' => $currentPostId,
                'dynamic_post_id' => $currentPostId,
                'post_type_id' => $payload['post_type_id'] ?? $currentPost?->post_type_id,
                'selected_taxonomy_term_ids' => $this->normalizeIds($payload['selected_taxonomy_term_ids'] ?? []),
            ];

            return response()->json([
                'status' => true,
                'message' => 'Related posts preview fetched successfully.',
                'data' => $this->relatedPostsWidget->data(
                    $payload['settings'] ?? [],
                    $currentPost,
                    $context
                ),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch related posts preview.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

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
                ? DynamicPost::find((int) $currentPostId)
                : null;

            $settings = $payload['settings'] ?? [];

            $context = [
                'entity_id' => $payload['entity_id'] ?? $currentPostId,
                'current_post_id' => $currentPostId,
                'post_id' => $currentPostId,
                'dynamic_post_id' => $currentPostId,
                'post_type_id' => $payload['post_type_id'] ?? $currentPost?->post_type_id,
                'selected_taxonomy_term_ids' => $this->normalizeIds($payload['selected_taxonomy_term_ids'] ?? []),
            ];

            $candidates = $this->relatedPostQueryService->getCandidatePosts(
                $settings,
                $currentPost,
                $context
            );

            return response()->json([
                'status' => true,
                'message' => 'Matched related posts fetched successfully.',
                'data' => [
                    'current_post_id' => $currentPostId ? (int) $currentPostId : null,
                    'post_type_id' => $context['post_type_id'] ? (int) $context['post_type_id'] : null,
                    'matched_count' => $candidates->count(),
                    'selected_post_ids' => $this->normalizeIds(
                        $payload['selected_post_ids']
                        ?? data_get($settings, 'selected_post_ids', [])
                    ),
                    'options' => $candidates,
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
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }
}