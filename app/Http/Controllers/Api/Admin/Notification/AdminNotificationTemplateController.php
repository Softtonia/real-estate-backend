<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\NotificationTemplateRequest;
use App\Http\Resources\Notification\NotificationTemplateResource;
use App\Models\Notification\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\NotificationTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminNotificationTemplateController extends Controller
{
    public function index(
        Request $request,
        NotificationTemplateService $service
    ): JsonResponse {
        try {
            $templates = $service->list($request->only([
                'search',
                'status',
                'channel',
                'per_page',
            ]));

            return response()->json([
                'status' => true,
                'message' => 'Notification templates fetched successfully.',
                'data' => NotificationTemplateResource::collection($templates),
                'meta' => [
                    'current_page' => $templates->currentPage(),
                    'last_page' => $templates->lastPage(),
                    'per_page' => $templates->perPage(),
                    'total' => $templates->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification templates.', $e);
        }
    }

    public function store(
        NotificationTemplateRequest $request,
        NotificationTemplateService $service
    ): JsonResponse {
        try {
            $template = $service->create(
                data: $request->validated(),
                admin: $this->currentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Notification template created successfully.',
                'data' => new NotificationTemplateResource($template),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to create notification template.', $e);
        }
    }

    public function show(NotificationTemplate $template): JsonResponse
    {
        try {
            $template->load([
                'createdBy:id,first_name,last_name,email',
                'updatedBy:id,first_name,last_name,email',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Notification template fetched successfully.',
                'data' => new NotificationTemplateResource($template),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification template.', $e);
        }
    }

    public function update(
        NotificationTemplateRequest $request,
        NotificationTemplate $template,
        NotificationTemplateService $service
    ): JsonResponse {
        try {
            $template = $service->update(
                template: $template,
                data: $request->validated(),
                admin: $this->currentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Notification template updated successfully.',
                'data' => new NotificationTemplateResource($template),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to update notification template.', $e);
        }
    }

    public function destroy(
        NotificationTemplate $template,
        NotificationTemplateService $service
    ): JsonResponse {
        try {
            $service->delete($template);

            return response()->json([
                'status' => true,
                'message' => 'Notification template deleted successfully.',
                'data' => [],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete notification template.', $e);
        }
    }

    public function options(NotificationTemplateService $service): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Notification template options fetched successfully.',
                'data' => $service->options(),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch notification template options.', $e);
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