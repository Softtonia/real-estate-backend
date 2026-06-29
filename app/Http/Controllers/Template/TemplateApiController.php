<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

class TemplateController extends Controller
{
    public function __construct(
        protected TemplateService $templateService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $templates = $this->templateService->list($request->all());

        return response()->json([
            'status' => true,
            'message' => 'Templates fetched successfully.',
            'data' => $templates,
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_type' => ['required', 'in:single_post,page,section'],
            'template_name' => ['required', 'string', 'max:255'],
            'post_type_id' => ['required_if:template_type,single_post', 'nullable', 'integer', 'exists:post_types,id'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $template = $this->templateService->create($payload);

            return response()->json([
                'status' => true,
                'message' => 'Template created successfully.',
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
                'message' => 'Unable to create template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $template = $this->templateService->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template fetched successfully.',
            'data' => $template,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = Template::find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_type' => ['required', 'in:single_post,page,section'],
            'template_name' => ['required', 'string', 'max:255'],
            'post_type_id' => ['required_if:template_type,single_post', 'nullable', 'integer', 'exists:post_types,id'],
            'status' => ['nullable', 'in:active,draft'],
            'regenerate_slug' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $template = $this->templateService->update($template, $payload);

            return response()->json([
                'status' => true,
                'message' => 'Template updated successfully.',
                'data' => $template,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $template = Template::find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'in:active,draft'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $template = $this->templateService->updateStatus(
            $template,
            (string) $request->status
        );

        return response()->json([
            'status' => true,
            'message' => 'Template status updated successfully.',
            'data' => $template,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $template = Template::with(['conditions', 'layout'])->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        try {
            $this->templateService->delete($template);

            return response()->json([
                'status' => true,
                'message' => 'Template deleted successfully.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Template options fetched successfully.',
            'data' => $this->templateService->options(),
        ]);
    }

    public function shortcodes(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Template shortcodes fetched successfully.',
            'data' => $this->templateService->shortcodes(),
        ]);
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

    private function validationErrorResponse($validator): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }
}