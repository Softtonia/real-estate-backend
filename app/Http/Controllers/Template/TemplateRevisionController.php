<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplateRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TemplateRevisionController extends Controller
{
    public function __construct(
        protected TemplateRevisionService $templateRevisionService
    ) {
    }

    public function index(Request $request, int $template_id): JsonResponse
    {
        $template = Template::find($template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template revisions fetched successfully.',
            'data' => $this->templateRevisionService->list(
                $template->id,
                (int) $request->get('per_page', 20)
            ),
        ]);
    }

    public function show(int $template_id, int $revision_id): JsonResponse
    {
        $template = Template::find($template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        try {
            $revision = $this->templateRevisionService->findForTemplate(
                $template->id,
                $revision_id
            );

            return response()->json([
                'status' => true,
                'message' => 'Template revision fetched successfully.',
                'data' => $revision,
            ]);
        } catch (Throwable) {
            return response()->json([
                'status' => false,
                'message' => 'Template revision not found.',
            ], 404);
        }
    }

    public function restore(Request $request, int $template_id, int $revision_id): JsonResponse
    {
        $template = Template::find($template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        try {
            $revision = $this->templateRevisionService->findForTemplate(
                $template->id,
                $revision_id
            );

            $template = $this->templateRevisionService->restoreLayout(
                $template,
                $revision
            );

            return response()->json([
                'status' => true,
                'message' => 'Template revision restored successfully.',
                'data' => $template,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to restore template revision.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}