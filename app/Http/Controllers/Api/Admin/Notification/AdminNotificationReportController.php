<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\Notification\NotificationBatchResource;
use App\Http\Resources\Notification\NotificationLogResource;
use App\Models\Notification\NotificationBatch;
use App\Services\Notification\AdminNotificationMaintenanceService;
use App\Services\Notification\AdminNotificationReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminNotificationReportController extends Controller
{
    public function dashboard(
        Request $request,
        AdminNotificationReportService $service
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Notification dashboard fetched successfully.',
                'data' => $service->dashboard(
                    $request->only([
                        'date_from',
                        'date_to',
                    ])
                ),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch notification dashboard.',
                $e
            );
        }
    }

    public function batches(
        Request $request,
        AdminNotificationReportService $service
    ): JsonResponse {
        try {
            $batches = $service->batches(
                $request->only([
                    'search',
                    'status',
                    'target_type',
                    'created_by',
                    'date_from',
                    'date_to',
                    'per_page',
                ])
            );

            return response()->json([
                'status' => true,
                'message' => 'Notification batches fetched successfully.',
                'data' => NotificationBatchResource::collection($batches),
                'meta' => [
                    'current_page' => $batches->currentPage(),
                    'last_page' => $batches->lastPage(),
                    'per_page' => $batches->perPage(),
                    'total' => $batches->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch notification batches.',
                $e
            );
        }
    }

    public function showBatch(
        NotificationBatch $batch,
        AdminNotificationReportService $service
    ): JsonResponse {
        try {
            $detail = $service->batchDetail($batch);

            return response()->json([
                'status' => true,
                'message' => 'Notification batch fetched successfully.',
                'data' => [
                    'batch' => new NotificationBatchResource(
                        $detail['batch']
                    ),
                    'live_stats' => $detail['live_stats'],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch notification batch.',
                $e
            );
        }
    }

    public function logs(
        Request $request,
        AdminNotificationReportService $service
    ): JsonResponse {
        try {
            $logs = $service->logs(
                $request->only([
                    'batch_id',
                    'user_id',
                    'device_id',
                    'status',
                    'platform',
                    'error_code',
                    'search',
                    'date_from',
                    'date_to',
                    'per_page',
                ])
            );

            return response()->json([
                'status' => true,
                'message' => 'Notification logs fetched successfully.',
                'data' => NotificationLogResource::collection($logs),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch notification logs.',
                $e
            );
        }
    }

    public function batchLogs(
        Request $request,
        NotificationBatch $batch,
        AdminNotificationReportService $service
    ): JsonResponse {
        try {
            $logs = $service->batchLogs(
                $batch,
                $request->only([
                    'user_id',
                    'device_id',
                    'status',
                    'platform',
                    'error_code',
                    'search',
                    'date_from',
                    'date_to',
                    'per_page',
                ])
            );

            return response()->json([
                'status' => true,
                'message' => 'Notification batch logs fetched successfully.',
                'data' => NotificationLogResource::collection($logs),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to fetch notification batch logs.',
                $e
            );
        }
    }

    public function clearLogs(
        AdminNotificationMaintenanceService $service
    ): JsonResponse {
        return $this->clearAllLogs($service);
    }

    public function clearAllLogs(
        AdminNotificationMaintenanceService $service
    ): JsonResponse {
        try {
            $result = $service->clearAllLogs();

            return response()->json([
                'status' => true,
                'message' => 'All notification logs cleared successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to clear notification logs.',
                $e
            );
        }
    }

    public function clearBatch(
        NotificationBatch $batch,
        AdminNotificationMaintenanceService $service
    ): JsonResponse {
        try {
            $result = $service->clearBatch($batch);

            return response()->json([
                'status' => true,
                'message' => 'Notification batch cleared successfully.',
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to clear notification batch.',
                $e
            );
        }
    }

    public function clearAllBatches(
        AdminNotificationMaintenanceService $service
    ): JsonResponse {
        try {
            $result = $service->clearAllBatches();

            return response()->json([
                'status' => true,
                'message' => 'All notification batches cleared successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to clear notification batches.',
                $e
            );
        }
    }

    private function validationError(
        ValidationException $e
    ): JsonResponse {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => $e->errors(),
        ], 422);
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
