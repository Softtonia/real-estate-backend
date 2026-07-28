<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Models\Membership\MembershipNotification;
use App\Models\User;
use App\Services\Membership\MembershipNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserMembershipNotificationController extends Controller
{
    public function index(
        Request $request,
        MembershipNotificationService $notificationService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $notifications = $notificationService->userNotifications($user, $request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership notifications fetched successfully.',
                'data' => $notifications,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership notifications.', $e);
        }
    }

    public function markAsRead(
        Request $request,
        MembershipNotification $notification,
        MembershipNotificationService $notificationService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $notification = $notificationService->markAsRead($user, $notification);

            return response()->json([
                'status' => true,
                'message' => 'Notification marked as read successfully.',
                'data' => $notification,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to mark notification as read.', $e);
        }
    }

    public function markAllAsRead(
        Request $request,
        MembershipNotificationService $notificationService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $count = $notificationService->markAllAsRead($user);

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
            return $this->serverError('Unable to mark all notifications as read.', $e);
        }
    }

    private function authenticatedUserOrFail(Request $request): User
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user.'],
            ]);
        }

        if ($this->isAdminUser($user)) {
            throw ValidationException::withMessages([
                'auth' => ['Admin token is not allowed for frontend membership notification API.'],
            ]);
        }

        return $user;
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if ($token && Schema::hasColumn('users', 'api_token')) {
            $user = User::query()->where('api_token', $token)->first();

            if ($user) {
                return $user;
            }
        }

        $authUser = $request->user() ?: Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function isAdminUser(User $user): bool
    {
        if ((int) $user->id === 1 || (string) $user->role_id === '1') {
            return true;
        }

        if (!Schema::hasTable('roles') || !$user->role_id || !is_numeric($user->role_id)) {
            return false;
        }

        $role = \App\Models\Role::query()->find((int) $user->role_id);

        if (!$role) {
            return false;
        }

        foreach (['name', 'role_name', 'title'] as $column) {
            if (Schema::hasColumn('roles', $column) && isset($role->{$column})) {
                $roleName = strtolower(str_replace([' ', '_', '-'], '', (string) $role->{$column}));

                return in_array($roleName, [
                    'admin',
                    'administrator',
                    'superadmin',
                    'superadministrator',
                ], true);
            }
        }

        return false;
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => $e->errors(),
        ], 422);
    }


    private function serverError(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}