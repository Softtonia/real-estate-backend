<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\PageBuilder\Services\TemplateResolveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;
use App\Models\DynamicPost;

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
    public function showDynamicPostTemplate(Request $request, DynamicPost $dynamicPost): JsonResponse
    {
        $request->merge([
            'post_type_id' => $dynamicPost->post_type_id,
            'post_id' => $dynamicPost->id,
            'dynamic_post_id' => $dynamicPost->id,
            'render_for' => 'frontend',
        ]);

        return $this->resolve($request);
    }
}
