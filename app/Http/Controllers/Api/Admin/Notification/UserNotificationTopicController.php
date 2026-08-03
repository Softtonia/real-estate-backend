<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\SubscribeNotificationTopicRequest;
use App\Http\Resources\Notification\NotificationTopicResource;
use App\Models\Notification\NotificationTopic;
use App\Models\User;
use App\Services\Notification\NotificationTopicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserNotificationTopicController extends Controller
{
    public function index(Request $request, NotificationTopicService $service): JsonResponse
    {
        try {
            $user = $this->currentUser($request);

            $topics = $service->userList($user, $request->only([
                'search',
                'per_page',
            ]));

            return response()->json([
                'status' => true,
                'message' => 'Notification topics fetched successfully.',
                'data' => NotificationTopicResource::collection($topics),
                'meta' => [
                    'current_page' => $topics->currentPage(),
                    'last_page' => $topics->lastPage(),
                    'per_page' => $topics->perPage(),
                    'total' => $topics->total(),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification topics.', $e);
        }
    }

    public function subscribe(
        SubscribeNotificationTopicRequest $request,
        NotificationTopic $topic,
        NotificationTopicService $service
    ): JsonResponse {
        try {
            $result = $service->subscribe(
                user: $this->currentUser($request),
                topic: $topic,
                data: $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => 'Subscribed to notification topic successfully.',
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to subscribe notification topic.', $e);
        }
    }

    public function unsubscribe(
        SubscribeNotificationTopicRequest $request,
        NotificationTopic $topic,
        NotificationTopicService $service
    ): JsonResponse {
        try {
            $result = $service->unsubscribe(
                user: $this->currentUser($request),
                topic: $topic,
                data: $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => 'Unsubscribed from notification topic successfully.',
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to unsubscribe notification topic.', $e);
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