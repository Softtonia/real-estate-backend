<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\TemplateComponent;
use App\PageBuilder\Services\LayoutValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\PageBuilder\Services\TemplateRevisionService;

class TemplateBuilderController extends Controller
{
    public function __construct(
        protected LayoutValidationService $layoutValidationService,
        protected TemplateRevisionService $templateRevisionService
    ) {}

    public function show($template_id): JsonResponse
    {
        $template = Template::with(['layout', 'conditions', 'postType'])->find($template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $components = class_exists(TemplateComponent::class)
            ? TemplateComponent::where('status', true)->get()
            : collect();

        return response()->json([
            'status' => true,
            'message' => 'Template builder data fetched successfully.',
            'data' => [
                'template' => $template,
                'components' => $components,
                'layout_json' => $template->layout?->layout_json ?? [
                    'sections' => [],
                ],
            ],
        ]);
    }

    public function save(Request $request, $template_id): JsonResponse
    {
        $template = Template::find($template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'layout_json' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $layoutJson = $request->layout_json;

        if (is_string($layoutJson)) {
            $decoded = json_decode($layoutJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid layout JSON.',
                ], 422);
            }

            $layoutJson = $decoded;
        }

        if (! is_array($layoutJson)) {
            return response()->json([
                'status' => false,
                'message' => 'Layout JSON must be an array.',
            ], 422);
        }

        $validation = $this->layoutValidationService->validate($layoutJson);

        if (! $validation['valid']) {
            return response()->json([
                'status' => false,
                'message' => 'Layout validation failed.',
                'errors' => $validation['errors'],
            ], 422);
        }

        $layoutJson = $validation['layout_json'];

        $layoutJson = $validation['layout_json'];

        $this->templateRevisionService->createSnapshot(
            $template->load(['layout', 'conditions']),
            'layout_save',
            'Auto snapshot before layout save'
        );

        $template->layout()->updateOrCreate(
            ['template_id' => $template->id],
            ['layout_json' => $layoutJson]
        );

        return response()->json([
            'status' => true,
            'message' => 'Template layout saved successfully.',
            'data' => [
                'template_id' => $template->id,
                'layout_json' => $layoutJson,
            ],
        ]);
    }
}
