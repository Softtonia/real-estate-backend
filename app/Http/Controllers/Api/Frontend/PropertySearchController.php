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
        Request $request,
        PropertySearchService $service
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Property search options fetched successfully.',
                'data' => $service->options($request->all()),
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
            $filters = $request->validated();
            $tab = mb_strtolower(trim((string) ($request->input('tab') ?: 'properties')));

            if (in_array($tab, ['project', 'projects', 'new_project', 'new_projects', 'new-projects', 'new-project'], true)) {
                $paginator = $service->searchProjects($filters);
                $activeTabKey = 'projects';
            } elseif (in_array($tab, ['agent', 'agents', 'top_agent', 'top_agents', 'top-agents', 'top-agent'], true)) {
                $paginator = $service->searchAgents($filters);
                $activeTabKey = 'agents';
            } else {
                $paginator = $service->search($filters);
                $activeTabKey = 'properties';
            }

            $propertiesCount = ($activeTabKey === 'properties') ? $paginator->total() : $service->countProperties($filters);
            $summary = $service->searchSummary($filters, $propertiesCount, $activeTabKey);

            return response()->json([
                'status' => true,
                'message' => 'Properties fetched successfully.',
                'data' => $paginator->getCollection()->values(),
                'meta' => array_merge([
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ], $summary),
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
