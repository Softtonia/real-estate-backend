<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\PropertySearchRequest;
use App\Services\Frontend\PropertySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class PropertySearchController extends Controller
{
    public function options(
        PropertySearchService $service
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Property search options fetched successfully.',
                'data' => $service->options(),
            ]);
        } catch (Throwable $e) {
            return $this->serverError(
                'Unable to fetch property search options.',
                $e
            );
        }
    }

    public function locationSuggestions(
        Request $request,
        PropertySearchService $service
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'search' => [
                    'required',
                    'string',
                    'min:2',
                    'max:100',
                ],
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Location suggestions fetched successfully.',
                'data' => $service->locationSuggestions($validated),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->serverError(
                'Unable to fetch location suggestions.',
                $e
            );
        }
    }

    public function search(
        PropertySearchRequest $request,
        PropertySearchService $service
    ): JsonResponse {
        try {
            $properties = $service->search(
                $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => 'Properties fetched successfully.',
                'data' => $properties->getCollection()->values(),
                'meta' => [
                    'current_page' => $properties->currentPage(),
                    'last_page' => $properties->lastPage(),
                    'per_page' => $properties->perPage(),
                    'total' => $properties->total(),
                    'from' => $properties->firstItem(),
                    'to' => $properties->lastItem(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError(
                'Unable to search properties.',
                $e
            );
        }
    }

    private function serverError(
        string $message,
        Throwable $e
    ): JsonResponse {
        report($e);

        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug')
                ? $e->getMessage()
                : 'Server error',
        ], 500);
    }
}
