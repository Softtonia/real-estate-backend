<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\Notification\UserNotificationResource;
use App\Models\Notification\UserNotification;
use App\Models\User;
use App\Services\Notification\UserNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserNotificationController extends Controller
{
    public function index(
        Request $request,
        UserNotificationService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $notifications = $service->list($user, $request->only([
                'type',
                'is_read',
                'search',
                'per_page',
            ]));

            return response()->json([
                'status' => true,
                'message' => 'Notifications fetched successfully.',
                'data' => UserNotificationResource::collection($notifications),
                'meta' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'unread_count' => $service->unreadCount($user),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notifications.', $e);
        }
    }

    public function unreadCount(
        Request $request,
        UserNotificationService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            return response()->json([
                'status' => true,
                'message' => 'Unread notification count fetched successfully.',
                'data' => [
                    'unread_count' => $service->unreadCount($user),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch unread notification count.', $e);
        }
    }

    public function show(
        Request $request,
        UserNotification $notification,
        UserNotificationService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $notification = $service->show($user, $notification);

            return response()->json([
                'status' => true,
                'message' => 'Notification fetched successfully.',
                'data' => new UserNotificationResource($notification),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification.', $e);
        }
    }

    public function markAsRead(
        Request $request,
        UserNotification $notification,
        UserNotificationService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $notification = $service->markAsRead($user, $notification);

            return response()->json([
                'status' => true,
                'message' => 'Notification marked as read successfully.',
                'data' => new UserNotificationResource($notification),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to mark notification as read.', $e);
        }
    }

    public function markAllAsRead(
        Request $request,
        UserNotificationService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $count = $service->markAllAsRead($user);

            return response()->json([
                'status' => true,
                'message' => 'All notifications marked as read successfully.',
                'data' => [
                    'updated_count' => $count,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to mark all notifications as read.', $e);
        }
    }

    public function destroy(
        Request $request,
        UserNotification $notification,
        UserNotificationService $service
    ): JsonResponse {
        try {
            $user = $this->currentUser($request);

            $service->delete($user, $notification);

            return response()->json([
                'status' => true,
                'message' => 'Notification deleted successfully.',
                'data' => [],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete notification.', $e);
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
            'auth' => ['Authenticated user not found.'],
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