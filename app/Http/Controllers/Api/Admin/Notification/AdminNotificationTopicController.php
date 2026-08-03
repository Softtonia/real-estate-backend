<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\NotificationTopicRequest;
use App\Http\Resources\Notification\NotificationTopicResource;
use App\Http\Resources\Notification\NotificationTopicSubscriberResource;
use App\Models\Notification\NotificationTopic;
use App\Services\Notification\NotificationTopicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminNotificationTopicController extends Controller
{
    public function index(Request $request, NotificationTopicService $service): JsonResponse
    {
        try {
            $topics = $service->adminList($request->only([
                'search',
                'status',
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
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification topics.', $e);
        }
    }

    public function store(NotificationTopicRequest $request, NotificationTopicService $service): JsonResponse
    {
        try {
            $topic = $service->create($request->validated());

            return response()->json([
                'status' => true,
                'message' => 'Notification topic created successfully.',
                'data' => new NotificationTopicResource($topic),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to create notification topic.', $e);
        }
    }

    public function show(NotificationTopic $topic): JsonResponse
    {
        try {
            $topic->loadCount(['subscribers', 'activeSubscribers']);

            return response()->json([
                'status' => true,
                'message' => 'Notification topic fetched successfully.',
                'data' => new NotificationTopicResource($topic),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification topic.', $e);
        }
    }

    public function update(
        NotificationTopicRequest $request,
        NotificationTopic $topic,
        NotificationTopicService $service
    ): JsonResponse {
        try {
            $topic = $service->update($topic, $request->validated());

            return response()->json([
                'status' => true,
                'message' => 'Notification topic updated successfully.',
                'data' => new NotificationTopicResource($topic),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to update notification topic.', $e);
        }
    }

    public function destroy(NotificationTopic $topic, NotificationTopicService $service): JsonResponse
    {
        try {
            $service->delete($topic);

            return response()->json([
                'status' => true,
                'message' => 'Notification topic deleted successfully.',
                'data' => [],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete notification topic.', $e);
        }
    }

    public function subscribers(
        Request $request,
        NotificationTopic $topic,
        NotificationTopicService $service
    ): JsonResponse {
        try {
            $subscribers = $service->subscribers($topic, $request->only([
                'status',
                'platform',
                'user_id',
                'per_page',
            ]));

            return response()->json([
                'status' => true,
                'message' => 'Notification topic subscribers fetched successfully.',
                'data' => NotificationTopicSubscriberResource::collection($subscribers),
                'meta' => [
                    'current_page' => $subscribers->currentPage(),
                    'last_page' => $subscribers->lastPage(),
                    'per_page' => $subscribers->perPage(),
                    'total' => $subscribers->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch topic subscribers.', $e);
        }
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