<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplateExportImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

class TemplateExportImportController extends Controller
{
    public function __construct(
        protected TemplateExportImportService $templateExportImportService
    ) {
    }

    public function export(int $id): JsonResponse
    {
        $template = Template::with(['layout', 'conditions', 'postType'])->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template exported successfully.',
            'data' => $this->templateExportImportService->export($template),
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_json' => ['required'],
            'template_name' => ['nullable', 'string', 'max:255'],
            'publish' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $template = $this->templateExportImportService->import(
                $payload,
                [
                    'template_name' => $payload['template_name'] ?? null,
                    'publish' => (bool) ($payload['publish'] ?? false),
                    'priority' => $payload['priority'] ?? null,
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Template imported successfully.',
                'data' => $template,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to import template.',
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