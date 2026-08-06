<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePropertyAvailabilityRequest;
use App\Http\Resources\PropertyAvailabilityHistoryResource;
use App\Http\Resources\PropertyAvailabilityResource;
use App\Models\DynamicPost;
use App\Models\User;
use App\Services\PropertyAvailability\PropertyAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class PropertyAvailabilityController extends Controller
{
    public function __construct(
        private readonly PropertyAvailabilityService $service
    ) {}

    public function adminUpdate(
        ChangePropertyAvailabilityRequest $request,
        DynamicPost $property
    ): JsonResponse {
        return $this->updateAvailability(
            request: $request,
            property: $property,
            isAdmin: true
        );
    }

    public function ownerUpdate(
        ChangePropertyAvailabilityRequest $request,
        DynamicPost $property
    ): JsonResponse {
        return $this->updateAvailability(
            request: $request,
            property: $property,
            isAdmin: false
        );
    }

    public function adminHistory(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        return $this->historyResponse(
            request: $request,
            property: $property,
            isAdmin: true
        );
    }

    public function ownerHistory(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        return $this->historyResponse(
            request: $request,
            property: $property,
            isAdmin: false
        );
    }

    private function updateAvailability(
        ChangePropertyAvailabilityRequest $request,
        DynamicPost $property,
        bool $isAdmin
    ): JsonResponse {
        try {
            $actor = $this->resolveActor($request);

            if (!$actor) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Unauthenticated user.',
                    'error' => null,
                ], 401);
            }

            $validated = $request->validated();

            $updatedProperty =
                $this->service->change(
                    property: $property,
                    actor: $actor,

                    targetStatus: (string)
                        $validated[
                            'availability_status'
                        ],

                    notes:
                        $validated['notes'] ?? null,

                    isAdmin: $isAdmin
                );

            $message = !empty(
                $updatedProperty
                    ->availability_pending_status
            )
                ? 'Property reactivation submitted for admin review.'
                : 'Property availability updated successfully.';

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' =>
                    new PropertyAvailabilityResource(
                        $updatedProperty
                    ),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unable to update property availability.',

                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function historyResponse(
        Request $request,
        DynamicPost $property,
        bool $isAdmin
    ): JsonResponse {
        try {
            $request->validate([
                'per_page' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:100',
                ],
            ]);

            $actor = $this->resolveActor($request);

            if (!$actor) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Unauthenticated user.',
                    'error' => null,
                ], 401);
            }

            $history = $this->service->history(
                property: $property,
                actor: $actor,
                isAdmin: $isAdmin,
                perPage: (int) $request->get(
                    'per_page',
                    20
                )
            );

            $items =
                PropertyAvailabilityHistoryResource::
                    collection(
                        $history->getCollection()
                    )->resolve($request);

            return response()->json([
                'status' => true,
                'message' =>
                    'Property availability history fetched successfully.',

                'data' => $items,

                'meta' => [
                    'current_page' =>
                        $history->currentPage(),

                    'last_page' =>
                        $history->lastPage(),

                    'per_page' =>
                        $history->perPage(),

                    'total' =>
                        $history->total(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unable to fetch property availability history.',

                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function resolveActor(
        Request $request
    ): ?User {
        $authenticatedUser = $request->user();

        if ($authenticatedUser instanceof User) {
            return $authenticatedUser;
        }

        $token = $request->bearerToken();

        if (
            !$token
            && $request->filled('api_token')
        ) {
            $token = (string)
                $request->input('api_token');
        }

        if (
            !$token
            || !Schema::hasColumn(
                'users',
                'api_token'
            )
        ) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }
}
