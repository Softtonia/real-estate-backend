<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\RegisterNotificationDeviceRequest;
use App\Http\Requests\Notification\RevokeNotificationDeviceRequest;
use App\Http\Resources\Notification\NotificationDeviceResource;
use App\Models\User;
use App\Services\Notification\NotificationDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserNotificationDeviceController extends Controller
{
    public function index(
        Request $request,
        NotificationDeviceService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $devices = $service->devices($user, $request->only([
                'status',
                'platform',
                'per_page',
            ]));

            return response()->json([
                'status' => true,
                'message' => 'Notification devices fetched successfully.',
                'data' => NotificationDeviceResource::collection($devices),
                'meta' => [
                    'current_page' => $devices->currentPage(),
                    'last_page' => $devices->lastPage(),
                    'per_page' => $devices->perPage(),
                    'total' => $devices->total(),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification devices.', $e);
        }
    }

    public function register(
        RegisterNotificationDeviceRequest $request,
        NotificationDeviceService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $device = $service->register(
                user: $user,
                data: $request->validated(),
                request: $request
            );

            return response()->json([
                'status' => true,
                'message' => 'Notification device registered successfully.',
                'data' => new NotificationDeviceResource($device),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to register notification device.', $e);
        }
    }

    public function revoke(
        RevokeNotificationDeviceRequest $request,
        NotificationDeviceService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $count = $service->revoke(
                user: $user,
                data: $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => 'Notification device revoked successfully.',
                'data' => [
                    'revoked_count' => $count,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to revoke notification device.', $e);
        }
    }

    private function currentUser(Request $request): User
    {
        $token = $request->bearerToken();

        if ($token) {
            $user = User::query()
                ->where('api_token', $token)
                ->first();

            if ($user) {
                return $user;
            }
        }

        $user = Auth::user();

        if ($user instanceof User) {
            return $user;
        }

        throw ValidationException::withMessages([
            'auth' => ['Unauthenticated user.'],
        ]);
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => $e->errors(),
        ], 422);
    }

    private function errorResponse(string $message, Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}