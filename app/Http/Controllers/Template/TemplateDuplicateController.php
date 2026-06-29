<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplateDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TemplateDuplicateController extends Controller
{
    public function __construct(
        protected TemplateDuplicateService $templateDuplicateService
    ) {
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $template = Template::with(['layout', 'conditions', 'postType'])->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'template_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $newTemplate = $this->templateDuplicateService->duplicate(
                $template,
                $request->input('template_name')
            );

            return response()->json([
                'status' => true,
                'message' => 'Template duplicated successfully.',
                'data' => $newTemplate,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to duplicate template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}