<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\PageBuilder\Services\TemplateResolveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TemplateResolveController extends Controller
{
    public function __construct(
        protected TemplateResolveService $templateResolveService
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_type' => ['nullable', 'in:single_post,page,section'],

            'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'post_type' => ['nullable', 'string'],

            'id' => ['nullable'],
            'post_id' => ['nullable', 'integer'],
            'dynamic_post_id' => ['nullable', 'integer'],

            'title' => ['nullable', 'string'],
            'slug' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],

            'fields' => ['nullable', 'array'],
            'content_data' => ['nullable', 'array'],

            'taxonomy_term_ids' => ['nullable', 'array'],
            'taxonomy_term_ids.*' => ['integer'],

            'taxonomy_terms' => ['nullable', 'array'],
            'taxonomies' => ['nullable', 'array'],
            'terms' => ['nullable', 'array'],
        ]);

        $validator->after(function ($validator) use ($payload) {
            $templateType = $payload['template_type'] ?? 'single_post';

            if ($templateType === 'single_post') {
                if (empty($payload['post_type_id']) && empty($payload['post_type'])) {
                    $validator->errors()->add(
                        'post_type',
                        'Post type id or post type slug is required.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->resolvePayload($payload);
    }

    public function showDynamicPostTemplate(Request $request, DynamicPost $dynamicPost): JsonResponse
    {
        $payload = [
            'template_type' => 'single_post',
            'post_type_id' => (int) $dynamicPost->post_type_id,
            'post_id' => (int) $dynamicPost->id,
            'dynamic_post_id' => (int) $dynamicPost->id,
            'id' => (int) $dynamicPost->id,
            'render_for' => 'frontend',
        ];

        return $this->resolvePayload($payload);
    }

    public function showDynamicPostTemplateBySlug(Request $request, string $slug): JsonResponse
    {
        $query = DynamicPost::query()
            ->where('slug', $slug);

        if ($request->filled('post_type_id')) {
            $query->where('post_type_id', (int) $request->post_type_id);
        }

        if ($request->filled('post_type')) {
            $postType = PostType::where('slug', $request->post_type)
                ->orWhere('name', $request->post_type)
                ->first();

            if ($postType) {
                $query->where('post_type_id', $postType->id);
            }
        }

        $dynamicPost = $query->first();

        if (! $dynamicPost) {
            return response()->json([
                'status' => false,
                'message' => 'Dynamic post not found.',
            ], 404);
        }

        return $this->showDynamicPostTemplate($request, $dynamicPost);
    }

    private function resolvePayload(array $payload): JsonResponse
    {
        try {
            $resolved = $this->templateResolveService->resolve($payload);

            if (! $resolved) {
                return response()->json([
                    'status' => false,
                    'message' => 'No matching active template found.',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Template resolved successfully.',
                'data' => $resolved,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to resolve template.',
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