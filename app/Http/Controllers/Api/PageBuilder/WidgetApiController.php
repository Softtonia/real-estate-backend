<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PageBuilder;

use App\Http\Controllers\Controller;
use App\PageBuilder\Services\WidgetApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WidgetApiController extends Controller
{
    public function __construct(
        protected WidgetApiService $widgetApiService
    ) {
    }

    public function index(): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Widgets fetched successfully.',
                'data' => $this->widgetApiService->getWidgets(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch widgets.',
                'errors' => [
                    'exception' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    public function show(Request $request, string $type): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Widget fetched successfully.',
                'data' => $this->widgetApiService->getWidget($type),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Widget not found.',
                'errors' => [
                    'type' => $type,
                    'exception' => $e->getMessage(),
                ],
            ], 404);
        }
    }
}