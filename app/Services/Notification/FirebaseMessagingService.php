<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationDevice;
use App\Models\Notification\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class FirebaseMessagingService
{
    public function __construct(
        private readonly FirebaseConfigService $configService,
        private readonly NotificationDeviceService $deviceService
    ) {}

    public function sendToDevice(
        NotificationDevice $device,
        string $title,
        string $body,
        ?string $imageUrl = null,
        array $data = [],
        ?NotificationLog $log = null
    ): array {
        if (! $device->status || $device->revoked_at) {
            $log?->markSkipped('Device is inactive or revoked.');

            return [
                'status' => false,
                'skipped' => true,
                'message' => 'Device is inactive or revoked.',
            ];
        }

        if (! $device->fcm_token) {
            $log?->markSkipped('FCM token is missing.');

            return [
                'status' => false,
                'skipped' => true,
                'message' => 'FCM token is missing.',
            ];
        }

        return $this->sendToToken(
            token: $device->fcm_token,
            title: $title,
            body: $body,
            imageUrl: $imageUrl,
            data: $data,
            platform: $device->platform,
            log: $log
        );
    }

    public function sendToToken(
        string $token,
        string $title,
        string $body,
        ?string $imageUrl = null,
        array $data = [],
        ?string $platform = null,
        ?NotificationLog $log = null
    ): array {
        try {
            $message = $this->buildMessage(
                token: $token,
                title: $title,
                body: $body,
                imageUrl: $imageUrl,
                data: $data,
                platform: $platform
            );

            $response = Http::withToken($this->configService->accessToken())
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->retry(2, 500)
                ->post($this->configService->messagingUrl(), [
                    'message' => $message,
                ]);

            $responseData = $response->json() ?: [];

            if ($response->successful()) {
                $firebaseMessageId = $responseData['name'] ?? null;

                $log?->markSent($firebaseMessageId);

                return [
                    'status' => true,
                    'message' => 'Notification sent successfully.',
                    'firebase_message_id' => $firebaseMessageId,
                    'response' => $responseData,
                ];
            }

            $errorCode = $this->extractFirebaseErrorCode($responseData);
            $errorMessage = $this->extractFirebaseErrorMessage($responseData);

            $log?->markFailed($errorCode, $errorMessage);

            if ($this->isInvalidTokenError($errorCode, $errorMessage)) {
                $this->deviceService->markInvalidToken($token);
            }

            return [
                'status' => false,
                'message' => 'Firebase notification failed.',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'response' => $responseData,
            ];
        } catch (ValidationException $e) {
            $log?->markFailed('VALIDATION_ERROR', json_encode($e->errors()));

            throw $e;
        } catch (Throwable $e) {
            report($e);

            $log?->markFailed('SERVER_ERROR', $e->getMessage());

            return [
                'status' => false,
                'message' => 'Unable to send Firebase notification.',
                'error_code' => 'SERVER_ERROR',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    public function dryRunToToken(
        string $token,
        string $title,
        string $body,
        ?string $imageUrl = null,
        array $data = [],
        ?string $platform = null
    ): array {
        $message = $this->buildMessage(
            token: $token,
            title: $title,
            body: $body,
            imageUrl: $imageUrl,
            data: $data,
            platform: $platform
        );

        $response = Http::withToken($this->configService->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->configService->messagingUrl(), [
                'validate_only' => true,
                'message' => $message,
            ]);

        return [
            'status' => $response->successful(),
            'response' => $response->json() ?: [],
            'http_status' => $response->status(),
        ];
    }

    private function buildMessage(
        string $token,
        string $title,
        string $body,
        ?string $imageUrl = null,
        array $data = [],
        ?string $platform = null
    ): array {
        $message = [
            'token' => $token,

            'notification' => array_filter([
                'title' => $title,
                'body' => $body,
                'image' => $imageUrl,
            ]),

            'data' => $this->normalizeData($data),
        ];

        $platform = strtolower((string) $platform);

        if ($platform === NotificationDevice::PLATFORM_ANDROID) {
            $message['android'] = $this->androidConfig($imageUrl);
        }

        if ($platform === NotificationDevice::PLATFORM_IOS) {
            $message['apns'] = $this->apnsConfig($imageUrl);
        }

        if ($platform === NotificationDevice::PLATFORM_WEB) {
            $message['webpush'] = $this->webPushConfig($title, $body, $imageUrl, $data);
        }

        if (! in_array($platform, NotificationDevice::PLATFORMS, true)) {
            $message['android'] = $this->androidConfig($imageUrl);
            $message['apns'] = $this->apnsConfig($imageUrl);
            $message['webpush'] = $this->webPushConfig($title, $body, $imageUrl, $data);
        }

        return $message;
    }

    private function androidConfig(?string $imageUrl = null): array
    {
        return [
            'priority' => 'HIGH',
            'notification' => array_filter([
                'sound' => 'default',
                'image' => $imageUrl,
                'channel_id' => 'default',
            ]),
        ];
    }

    private function apnsConfig(?string $imageUrl = null): array
    {
        return [
            'headers' => [
                'apns-priority' => '10',
            ],
            'payload' => [
                'aps' => [
                    'sound' => 'default',
                    'mutable-content' => $imageUrl ? 1 : 0,
                ],
            ],
            'fcm_options' => array_filter([
                'image' => $imageUrl,
            ]),
        ];
    }

    private function webPushConfig(
        string $title,
        string $body,
        ?string $imageUrl = null,
        array $data = []
    ): array {
        return [
            'notification' => array_filter([
                'title' => $title,
                'body' => $body,
                'icon' => $data['icon'] ?? null,
                'image' => $imageUrl,
            ]),
            'fcm_options' => array_filter([
                'link' => $data['url'] ?? $data['link'] ?? null,
            ]),
        ];
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $normalized[$key] = json_encode($value);
                continue;
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }

    private function extractFirebaseErrorCode(array $response): ?string
    {
        $error = $response['error'] ?? [];

        if (! empty($error['status'])) {
            return (string) $error['status'];
        }

        if (! empty($error['details']) && is_array($error['details'])) {
            foreach ($error['details'] as $detail) {
                if (! empty($detail['errorCode'])) {
                    return (string) $detail['errorCode'];
                }
            }
        }

        return null;
    }

    private function extractFirebaseErrorMessage(array $response): ?string
    {
        $error = $response['error'] ?? [];

        if (! empty($error['message'])) {
            return (string) $error['message'];
        }

        return null;
    }

    private function isInvalidTokenError(?string $errorCode, ?string $errorMessage): bool
    {
        $errorCode = strtoupper((string) $errorCode);
        $errorMessage = strtoupper((string) $errorMessage);

        return in_array($errorCode, [
            'UNREGISTERED',
            'INVALID_ARGUMENT',
            'NOT_FOUND',
        ], true)
            || str_contains($errorMessage, 'REGISTRATION TOKEN')
            || str_contains($errorMessage, 'UNREGISTERED')
            || str_contains($errorMessage, 'INVALID REGISTRATION');
    }
}