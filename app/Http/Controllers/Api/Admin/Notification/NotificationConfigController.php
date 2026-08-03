<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\FirebaseConfigRequest;
use App\Models\User;
use App\Services\Notification\FirebaseConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;
use App\Http\Requests\Admin\Notification\FirebaseTestNotificationRequest;
use App\Services\Notification\FirebaseMessagingService;

class NotificationConfigController extends Controller
{
    public function firebase(FirebaseConfigService $service): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Firebase config fetched successfully.',
                'data' => $service->firebaseConfig(masked: true),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch Firebase config.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function updateFirebase(
        FirebaseConfigRequest $request,
        FirebaseConfigService $service
    ): JsonResponse {
        try {
            $config = $service->updateFirebaseConfig(
                data: $request->validated(),
                admin: $this->currentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Firebase config updated successfully.',
                'data' => $config,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to update Firebase config.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function testToken(FirebaseConfigService $service): JsonResponse
    {
        try {
            $token = $service->accessToken();

            return response()->json([
                'status' => true,
                'message' => 'Firebase access token generated successfully.',
                'data' => [
                    'token_status' => $token ? 'generated' : 'missing',
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to generate Firebase access token.',
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
    public function testSend(
        FirebaseTestNotificationRequest $request,
        FirebaseMessagingService $messagingService
    ): JsonResponse {
        try {
            $data = $request->validated();

            $result = ! empty($data['dry_run'])
                ? $messagingService->dryRunToToken(
                    token: $data['fcm_token'],
                    title: $data['title'],
                    body: $data['body'],
                    imageUrl: $data['image_url'] ?? null,
                    data: $data['data'] ?? [],
                    platform: $data['platform'] ?? null
                )
                : $messagingService->sendToToken(
                    token: $data['fcm_token'],
                    title: $data['title'],
                    body: $data['body'],
                    imageUrl: $data['image_url'] ?? null,
                    data: $data['data'] ?? [],
                    platform: $data['platform'] ?? null
                );

            return response()->json([
                'status' => (bool) ($result['status'] ?? false),
                'message' => ($result['status'] ?? false)
                    ? 'Firebase test notification sent successfully.'
                    : 'Firebase test notification failed.',
                'data' => $result,
            ], ($result['status'] ?? false) ? 200 : 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to send Firebase test notification.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }
}
