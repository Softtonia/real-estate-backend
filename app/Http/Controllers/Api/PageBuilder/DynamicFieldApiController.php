<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PageBuilder;

use App\Http\Controllers\Controller;
use App\PageBuilder\Services\DynamicFieldApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DynamicFieldApiController extends Controller
{
    public function __construct(
        protected DynamicFieldApiService $dynamicFieldApiService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_type_id' => ['nullable', 'integer'],
            'taxonomy_id' => ['nullable', 'integer'],
            'taxonomy_term_ids' => ['nullable', 'array'],
            'taxonomy_term_ids.*' => ['integer'],
        ]);

        try {
            $data = $this->dynamicFieldApiService->getFields(
                isset($validated['post_type_id']) ? (int) $validated['post_type_id'] : null,
                $validated
            );

            return response()->json([
                'status' => true,
                'message' => 'Dynamic fields fetched successfully.',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch dynamic fields.',
                'errors' => [
                    'exception' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}