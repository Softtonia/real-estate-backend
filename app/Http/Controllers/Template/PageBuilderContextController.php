<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\PageBuilder\Services\DynamicPostFieldValueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PageBuilderContextController extends Controller
{
    public function __construct(
        protected DynamicPostFieldValueService $dynamicPostFieldValueService
    ) {
    }

    public function resolve(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'post_id' => ['required', 'integer'],
            'post_type_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $this->dynamicPostFieldValueService->resolveForPost(
            (int) $payload['post_id'],
            ! empty($payload['post_type_id']) ? (int) $payload['post_type_id'] : null
        );

        return response()->json([
            'status' => true,
            'message' => 'Page builder context resolved successfully.',
            'data' => $data,
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
}