<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\RetryFailedNotificationRequest;
use App\Models\Notification\NotificationBatch;
use App\Services\Notification\NotificationRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminNotificationRetryController extends Controller
{
    public function retryBatchFailed(
        RetryFailedNotificationRequest $request,
        NotificationBatch $batch,
        NotificationRetryService $service
    ): JsonResponse {
        try {
            $result = $service->retryBatchFailed(
                batch: $batch,
                filters: $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => $result['message'],
                'data' => $result,
            ], 202);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to retry failed batch notifications.', $e);
        }
    }

    public function retryLogsFailed(
        RetryFailedNotificationRequest $request,
        NotificationRetryService $service
    ): JsonResponse {
        try {
            $result = $service->retrySpecificLogs(
                filters: $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => $result['message'],
                'data' => $result,
            ], 202);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to retry failed notification logs.', $e);
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