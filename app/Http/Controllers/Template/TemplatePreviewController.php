<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplateRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TemplatePreviewController extends Controller
{
    public function __construct(
        protected TemplateRenderService $templateRenderService
    ) {
    }

    public function preview(Request $request, int $template_id): JsonResponse
    {
        $template = Template::with(['layout', 'conditions', 'postType'])->find($template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'fields' => ['nullable', 'array'],
            'content_data' => ['nullable', 'array'],
            'taxonomies' => ['nullable', 'array'],
            'terms' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json([
                'status' => true,
                'message' => 'Template preview rendered successfully.',
                'data' => $this->templateRenderService->preview($template, $payload),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to render template preview.',
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