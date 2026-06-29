<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplateConflictService;
use Illuminate\Http\JsonResponse;

class TemplateConflictController extends Controller
{
    public function __construct(
        protected TemplateConflictService $templateConflictService
    ) {
    }

    public function check(int $id): JsonResponse
    {
        $template = Template::with(['conditions', 'postType'])->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $result = $this->templateConflictService->check($template);

        return response()->json([
            'status' => true,
            'message' => $result['has_conflict']
                ? 'Template conflicts found.'
                : 'No template conflicts found.',
            'data' => $result,
        ]);
    }
}