<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class NotificationPayloadOptionController extends Controller
{
    public function index(): JsonResponse
    {
        $types = config('notification_payload.types', []);

        $data = collect($types)->map(function (array $typeConfig, string $typeKey) {
            return [
                'value' => $typeKey,
                'label' => $typeConfig['label'] ?? ucfirst($typeKey),
                'screens' => collect($typeConfig['screens'] ?? [])->map(function (array $screenConfig, string $screenKey) {
                    return [
                        'value' => $screenKey,
                        'label' => $screenConfig['label'] ?? ucfirst(str_replace('_', ' ', $screenKey)),
                        'required_fields' => $screenConfig['required_fields'] ?? [],
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Notification payload options fetched successfully.',
            'data' => [
                'types' => $data,
            ],
        ]);
    }
}