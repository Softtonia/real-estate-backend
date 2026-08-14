<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\AdminNotificationInboxListRequest;
use App\Http\Resources\Notification\AdminNotificationInboxResource;
use App\Models\User;
use App\Services\Notification\AdminNotificationInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminNotificationInboxController extends Controller
{
    public function index(
        AdminNotificationInboxListRequest $request,
        AdminNotificationInboxService $service
    ): JsonResponse {
        try {
            $admin = $this->currentAdmin($request);

            $notifications = $service->index(
                $admin,
                $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' =>
                'Admin notifications fetched successfully.',

                'data' =>
                AdminNotificationInboxResource::collection(
                    $notifications->getCollection()
                )->resolve($request),

                'meta' => [
                    'current_page' =>
                    $notifications->currentPage(),

                    'last_page' =>
                    $notifications->lastPage(),

                    'per_page' =>
                    $notifications->perPage(),

                    'total' =>
                    $notifications->total(),

                    'from' =>
                    $notifications->firstItem(),

                    'to' =>
                    $notifications->lastItem(),

                    'has_more_pages' =>
                    $notifications->hasMorePages(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch admin inbox.',
                'error' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch admin inbox.',
                $e
            );
        }
    }

    public function unreadCount(
        Request $request,
        AdminNotificationInboxService $service
    ): JsonResponse {
        try {
            $admin = $this->currentAdmin($request);

            $count = $service->unreadCount(
                $admin
            );

            return response()->json([
                'status' => true,
                'message' =>
                'Unread notification count fetched successfully.',
                'data' => [
                    'unread_count' => $count,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' =>
                'Unable to fetch unread notification count.',
                'error' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch unread notification count.',
                $e
            );
        }
    }

    public function show(
        Request $request,
        string $notification,
        AdminNotificationInboxService $service
    ): JsonResponse {
        try {
            $admin = $this->currentAdmin($request);

            $item = $service->findForAdmin(
                $admin,
                $notification
            );

            return response()->json([
                'status' => true,
                'message' =>
                'Notification fetched successfully.',
                'data' => (new AdminNotificationInboxResource(
                    $item
                ))->resolve($request),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Notification not found.',
                'error' => $e->errors(),
            ], 404);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch notification.',
                $e
            );
        }
    }

    public function markAsRead(
        Request $request,
        string $notification,
        AdminNotificationInboxService $service
    ): JsonResponse {
        try {
            $admin = $this->currentAdmin($request);

            $item = $service->markAsRead(
                $admin,
                $notification
            );

            return response()->json([
                'status' => true,
                'message' =>
                'Notification marked as read successfully.',
                'data' => (new AdminNotificationInboxResource(
                    $item
                ))->resolve($request),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Notification not found.',
                'error' => $e->errors(),
            ], 404);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to mark notification as read.',
                $e
            );
        }
    }

    public function markAllAsRead(
        Request $request,
        AdminNotificationInboxService $service
    ): JsonResponse {
        try {
            $admin = $this->currentAdmin($request);

            $updated = $service->markAllAsRead(
                $admin
            );

            return response()->json([
                'status' => true,
                'message' =>
                'All notifications marked as read successfully.',
                'data' => [
                    'updated_count' => $updated,
                    'unread_count' => 0,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' =>
                'Unable to mark notifications as read.',
                'error' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to mark notifications as read.',
                $e
            );
        }
    }

    /**
     * Resolve the administrator using the same api_token
     * authentication style already used by this project.
     *
     * admin.token middleware remains the primary security layer.
     */
    private function currentAdmin(
        Request $request
    ): User {
        $authUser =
            $request->user()
            ?: Auth::user();

        if ($authUser instanceof User) {
            return $authUser;
        }

        $token = $request->bearerToken();

        if (
            is_string($token)
            && trim($token) !== ''
        ) {
            $user = User::query()
                ->where(
                    'api_token',
                    trim($token)
                )
                ->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        throw ValidationException::withMessages([
            'auth' => [
                'Authenticated administrator not found.',
            ],
        ]);
    }

    private function errorResponse(
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
