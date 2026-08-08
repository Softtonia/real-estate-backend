<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Throwable;

class NotificationPayloadOptionController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $types = config('notification_payload.types', []);

            $payloadTypes = collect($types)
                ->map(function (array $typeConfig, string $typeKey) {
                    return [
                        'value' => $typeKey,
                        'label' => $typeConfig['label'] ?? ucfirst(str_replace('_', ' ', $typeKey)),

                        'screens' => collect($typeConfig['screens'] ?? [])
                            ->map(function (array $screenConfig, string $screenKey) {
                                return [
                                    'value' => $screenKey,
                                    'label' => $screenConfig['label'] ?? ucfirst(str_replace('_', ' ', $screenKey)),
                                    'required_fields' => $screenConfig['required_fields'] ?? [],
                                ];
                            })
                            ->values(),
                    ];
                })
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Notification payload options fetched successfully.',
                'data' => [
                    'types' => $payloadTypes,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch notification payload options.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }
}