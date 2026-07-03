<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\PageBuilder\Services\DynamicPostDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class DynamicContextController extends Controller
{
    public function __construct(
        protected DynamicPostDataService $dynamicPostDataService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $payload = $request->all();

        $validator = Validator::make($payload, [
            'post_id' => ['required', 'integer'],
            'post_type_id' => ['required', 'integer', 'exists:post_types,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            /*
             * This service should return resolved data like:
             * system.title
             * custom.property_price
             * taxonomy.purpose
             */
            $context = $this->dynamicPostDataService->buildContext(
                (int) $payload['post_id'],
                (int) $payload['post_type_id']
            );

            return response()->json([
                'status' => true,
                'message' => 'Dynamic context fetched successfully.',
                'data' => $context,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch dynamic context.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}