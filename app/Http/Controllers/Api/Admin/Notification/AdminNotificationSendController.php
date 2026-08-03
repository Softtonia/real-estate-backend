<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\SendNotificationRequest;
use App\Http\Resources\Notification\NotificationBatchResource;
use App\Models\User;
use App\Services\Notification\NotificationDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminNotificationSendController extends Controller
{
    public function send(
        SendNotificationRequest $request,
        NotificationDispatchService $service
    ): JsonResponse {
        try {
            $batch = $service->send(
                data: $request->validated(),
                admin: $this->currentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => $batch->scheduled_at
                    ? 'Notification scheduled successfully.'
                    : 'Notification queued successfully.',
                'data' => new NotificationBatchResource($batch),
            ], 202);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'error' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to queue notification.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    private function currentUser(Request $request): ?User
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

        return Auth::user();
    }
}