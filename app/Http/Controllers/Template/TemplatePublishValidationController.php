<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplatePublishValidationService;
use Illuminate\Http\JsonResponse;

class TemplatePublishValidationController extends Controller
{
    public function __construct(
        protected TemplatePublishValidationService $templatePublishValidationService
    ) {
    }

    public function check(int $id): JsonResponse
    {
        $template = Template::with(['layout', 'conditions', 'postType'])->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $validation = $this->templatePublishValidationService->validate($template);

        return response()->json([
            'status' => true,
            'message' => $validation['can_publish']
                ? 'Template is ready to publish.'
                : 'Template is not ready to publish.',
            'data' => $validation,
        ]);
    }
}