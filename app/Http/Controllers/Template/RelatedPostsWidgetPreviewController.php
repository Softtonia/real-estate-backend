<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\PageBuilder\Widgets\RelatedPostsWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RelatedPostsWidgetPreviewController extends Controller
{
    public function __construct(
        protected RelatedPostsWidget $relatedPostsWidget
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
                ?? null;

            $currentPost = $currentPostId
                ? DynamicPost::find((int) $currentPostId)
                : null;

            return response()->json([
                'status' => true,
                'message' => 'Related posts preview fetched successfully.',
                'data' => $this->relatedPostsWidget->data(
                    $payload['settings'] ?? [],
                    $currentPost
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
}