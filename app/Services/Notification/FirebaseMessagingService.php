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

            $log?->markFailed($errorCode ?: 'FIREBASE_ERROR', $errorMessage ?: 'Firebase notification failed.');

            if ($this->isInvalidTokenError($errorCode, $errorMessage)) {
                $this->deviceService->markInvalidToken($token);
            }

            return [
                'status' => false,
                'message' => 'Firebase notification failed.',
                'http_status' => $response->status(),
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
                ->post($this->configService->messagingUrl(), [
                    'validate_only' => true,
                    'message' => $message,
                ]);

            return [
                'status' => $response->successful(),
                'http_status' => $response->status(),
                'response' => $response->json() ?: [],
                'payload' => config('app.debug') ? ['message' => $message] : null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => false,
                'http_status' => null,
                'response' => [],
                'error_message' => $e->getMessage(),
            ];
        }
    }

    private function buildMessage(
        string $token,
        string $title,
        string $body,
        ?string $imageUrl = null,
        array $data = [],
        ?string $platform = null
    ): array {
        $token = trim($token);
        $title = trim($title);
        $body = trim($body);

        if ($token === '') {
            throw ValidationException::withMessages([
                'fcm_token' => ['FCM token is required.'],
            ]);
        }

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => ['Notification title is required.'],
            ]);
        }

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['Notification body is required.'],
            ]);
        }

        $normalizedData = $this->normalizeData($data);

        /*
        |--------------------------------------------------------------------------
        | Correct FCM HTTP v1 message object
        |--------------------------------------------------------------------------
        | Do not put fcm_options directly inside notification.
        | Do not put webpush.fcm_options inside webpush.notification.
        | Correct web click link path is: message.webpush.fcm_options.link
        */
        $message = [
            'token' => $token,

            'notification' => $this->removeEmptyValues([
                'title' => $title,
                'body' => $body,
                'image' => $this->validUrlOrNull($imageUrl),
            ]),

            'data' => $normalizedData,
        ];

        $platform = $this->normalizePlatform($platform);

        /*
        |--------------------------------------------------------------------------
        | Very important:
        |--------------------------------------------------------------------------
        | If platform is null/unknown, send minimal common payload only.
        | Earlier issue happened because extra platform config/fcm_options was being
        | sent incorrectly for test-send.
        */
        if ($platform === NotificationDevice::PLATFORM_ANDROID) {
            $message['android'] = $this->androidConfig(
                title: $title,
                body: $body,
                imageUrl: $imageUrl
            );
        }

        if ($platform === NotificationDevice::PLATFORM_IOS) {
            $message['apns'] = $this->apnsConfig(
                title: $title,
                body: $body,
                imageUrl: $imageUrl
            );
        }

        if ($platform === NotificationDevice::PLATFORM_WEB) {
            $message['webpush'] = $this->webPushConfig(
                title: $title,
                body: $body,
                imageUrl: $imageUrl,
                data: $data
            );
        }

        return $this->removeEmptyValues($message);
    }

    private function androidConfig(
        string $title,
        string $body,
        ?string $imageUrl = null
    ): array {
        return $this->removeEmptyValues([
            'priority' => 'HIGH',

            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'image' => $this->validUrlOrNull($imageUrl),
                'channel_id' => 'default',
            ],

            'fcm_options' => [
                'analytics_label' => 'holiplaces_notification',
            ],
        ]);
    }

    private function apnsConfig(
        string $title,
        string $body,
        ?string $imageUrl = null
    ): array {
        return $this->removeEmptyValues([
            'headers' => [
                'apns-priority' => '10',
            ],

            'payload' => [
                'aps' => [
                    'alert' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'sound' => 'default',
                    'mutable-content' => $imageUrl ? 1 : null,
                ],
            ],

            'fcm_options' => [
                'image' => $this->validUrlOrNull($imageUrl),
                'analytics_label' => 'holiplaces_notification',
            ],
        ]);
    }

    private function webPushConfig(
        string $title,
        string $body,
        ?string $imageUrl = null,
        array $data = []
    ): array {
        $link = $data['url']
            ?? $data['link']
            ?? $data['click_url']
            ?? null;

        $icon = $data['icon']
            ?? $data['icon_url']
            ?? null;

        $badge = $data['badge']
            ?? null;

        return $this->removeEmptyValues([
            'headers' => [
                'Urgency' => 'high',
            ],

            'notification' => [
                'title' => $title,
                'body' => $body,
                'icon' => $this->validUrlOrNull($icon) ?: '/favicon.ico',
                'badge' => $this->validUrlOrNull($badge),
                'image' => $this->validUrlOrNull($imageUrl),
            ],

            /*
             * Correct location for web click URL:
             * message.webpush.fcm_options.link
             */
            'fcm_options' => [
                'link' => $this->validHttpsUrlOrNull($link),
                'analytics_label' => 'holiplaces_notification',
            ],
        ]);
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $key = trim((string) $key);

            if ($key === '') {
                continue;
            }

            /*
             * FCM data payload values must be strings.
             */
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

    private function normalizePlatform(?string $platform): ?string
    {
        $platform = strtolower(trim((string) $platform));

        if ($platform === '') {
            return null;
        }

        $allowedPlatforms = defined(NotificationDevice::class . '::PLATFORMS')
            ? NotificationDevice::PLATFORMS
            : [
                NotificationDevice::PLATFORM_ANDROID,
                NotificationDevice::PLATFORM_IOS,
                NotificationDevice::PLATFORM_WEB,
            ];

        return in_array($platform, $allowedPlatforms, true)
            ? $platform
            : null;
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

                if (! empty($detail['error_code'])) {
                    return (string) $detail['error_code'];
                }
            }
        }

        if (! empty($error['code'])) {
            return (string) $error['code'];
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
            '404',
            '400',
        ], true)
            || str_contains($errorMessage, 'REGISTRATION TOKEN')
            || str_contains($errorMessage, 'UNREGISTERED')
            || str_contains($errorMessage, 'INVALID REGISTRATION')
            || str_contains($errorMessage, 'REQUESTED ENTITY WAS NOT FOUND');
    }

    private function validUrlOrNull(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL)
            ? $url
            : null;
    }

    private function validHttpsUrlOrNull(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return str_starts_with(strtolower($url), 'https://')
            ? $url
            : null;
    }

    private function removeEmptyValues(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = $this->removeEmptyValues($value);

                if ($value === []) {
                    unset($array[$key]);
                    continue;
                }

                $array[$key] = $value;
                continue;
            }

            if ($value === null || $value === '') {
                unset($array[$key]);
            }
        }

        return $array;
    }
}