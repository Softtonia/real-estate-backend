<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\AdminNotificationDeviceListRequest;
use App\Http\Resources\Notification\AdminNotificationDeviceResource;
use App\Services\Notification\AdminNotificationDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminNotificationDeviceController extends Controller
{
    public function index(
        AdminNotificationDeviceListRequest $request,
        AdminNotificationDeviceService $service
    ): JsonResponse {
        try {
            $devices = $service->devices(
                $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' =>
                    'Notification devices fetched successfully.',

                'data' =>
                    AdminNotificationDeviceResource::collection(
                        $devices->getCollection()
                    )->resolve($request),

                'meta' => [
                    'current_page' =>
                        $devices->currentPage(),

                    'last_page' =>
                        $devices->lastPage(),

                    'per_page' =>
                        $devices->perPage(),

                    'total' =>
                        $devices->total(),

                    'from' =>
                        $devices->firstItem(),

                    'to' =>
                        $devices->lastItem(),

                    'has_more_pages' =>
                        $devices->hasMorePages(),
                ],
            ]);
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
                'message' =>
                    'Unable to fetch notification devices.',
                'error' =>
                    config('app.debug')
                        ? $e->getMessage()
                        : 'Server error',
            ], 500);
        }
    }
}