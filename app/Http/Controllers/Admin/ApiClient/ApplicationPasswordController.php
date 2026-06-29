<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateApplicationPasswordRequest;
use App\Http\Resources\ApplicationPasswordResource;
use App\Models\ApiClient;
use App\Models\ApplicationPassword;
use App\Services\ApplicationPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApplicationPasswordController extends Controller
{
    public function __construct(
        private readonly ApplicationPasswordService $applicationPasswordService
    ) {}

    public function index(ApiClient $apiClient): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ApplicationPassword::class, $apiClient]);

        $passwords = $this->applicationPasswordService->paginateByClient($apiClient, 20);

        return ApplicationPasswordResource::collection($passwords);
    }

    public function store(CreateApplicationPasswordRequest $request, ApiClient $apiClient): JsonResponse
    {
        Gate::authorize('create', [ApplicationPassword::class, $apiClient]);

        $result = $this->applicationPasswordService->create(
            $apiClient,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Application password created successfully. Copy this token now. It will not be shown again.',
            'data' => new ApplicationPasswordResource($result['password']),
            'plain_token' => $result['plain_token'],
        ], 201);
    }

    public function destroy(ApiClient $apiClient, ApplicationPassword $applicationPassword): JsonResponse
    {
        abort_unless($applicationPassword->api_client_id === $apiClient->id, 404);

        Gate::authorize('delete', $applicationPassword);

        $password = $this->applicationPasswordService->revoke($applicationPassword);

        return response()->json([
            'success' => true,
            'message' => 'Application password revoked successfully.',
            'data' => new ApplicationPasswordResource($password),
        ]);
    }

    public function rotate(
        CreateApplicationPasswordRequest $request,
        ApiClient $apiClient,
        ApplicationPassword $applicationPassword
    ): JsonResponse {
        abort_unless($applicationPassword->api_client_id === $apiClient->id, 404);

        Gate::authorize('rotate', $applicationPassword);

        $result = $this->applicationPasswordService->rotate(
            $apiClient,
            $applicationPassword,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Application password rotated successfully. Copy this token now. It will not be shown again.',
            'data' => new ApplicationPasswordResource($result['password']),
            'plain_token' => $result['plain_token'],
        ]);
    }
}