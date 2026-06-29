<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiClientRequest;
use App\Http\Requests\UpdateApiClientRequest;
use App\Http\Resources\ApiClientResource;
use App\Models\ApiClient;
use App\Services\ApiClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApiClientController extends Controller
{
    public function __construct(
        private readonly ApiClientService $apiClientService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ApiClient::class);

        $clients = $this->apiClientService->paginate(20);

        return ApiClientResource::collection($clients);
    }

    public function store(StoreApiClientRequest $request): JsonResponse
    {
        Gate::authorize('create', ApiClient::class);

        $client = $this->apiClientService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'API client created successfully.',
            'data' => new ApiClientResource(
                $client->loadCount('applicationPasswords')
            ),
        ], 201);
    }

    public function show(ApiClient $apiClient): JsonResponse
    {
        Gate::authorize('view', $apiClient);

        return response()->json([
            'success' => true,
            'data' => new ApiClientResource(
                $apiClient->loadCount('applicationPasswords')
            ),
        ]);
    }

    public function update(UpdateApiClientRequest $request, ApiClient $apiClient): JsonResponse
    {
        Gate::authorize('update', $apiClient);

        $client = $this->apiClientService->update(
            $apiClient,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'API client updated successfully.',
            'data' => new ApiClientResource(
                $client->loadCount('applicationPasswords')
            ),
        ]);
    }

    public function destroy(ApiClient $apiClient): JsonResponse
    {
        Gate::authorize('delete', $apiClient);

        $this->apiClientService->delete($apiClient);

        return response()->json([
            'success' => true,
            'message' => 'API client deleted successfully.',
        ]);
    }
}