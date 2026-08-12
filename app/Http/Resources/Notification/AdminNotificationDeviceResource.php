<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminNotificationDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $token =
            $this->getAttribute('fcm_token')
            ?? $this->getAttribute('device_token')
            ?? $this->getAttribute('token');

        $firstName =
            $this->getAttribute('notification_user_first_name');

        $lastName =
            $this->getAttribute('notification_user_last_name');

        $userName = trim(
            (string) $firstName
            . ' '
            . (string) $lastName
        );

        if ($userName === '') {
            $userName =
                $this->getAttribute(
                    'notification_user_email'
                );
        }

        return [
            'id' =>
                (int) $this->id,

            'user_id' =>
                !empty($this->user_id)
                    ? (int) $this->user_id
                    : null,

            'user' =>
                !empty($this->user_id)
                    ? [
                        'id' =>
                            (int) $this->user_id,

                        'name' =>
                            $userName ?: null,

                        'email' =>
                            $this->getAttribute(
                                'notification_user_email'
                            ),

                        'phone' =>
                            $this->getAttribute(
                                'notification_user_phone'
                            ),
                    ]
                    : null,

            'platform' =>
                $this->getAttribute(
                    'platform'
                ),

            'status' =>
                $this->getAttribute(
                    'status'
                ),

            'device_id' =>
                $this->getAttribute('device_id')
                ?? $this->getAttribute('device_uuid'),

            'device_name' =>
                $this->getAttribute(
                    'device_name'
                ),

            'device_model' =>
                $this->getAttribute(
                    'device_model'
                ),

            'app_version' =>
                $this->getAttribute(
                    'app_version'
                ),

            'os_version' =>
                $this->getAttribute(
                    'os_version'
                ),

            'token_preview' =>
                $this->maskToken($token),

            'ip_address' =>
                $this->getAttribute(
                    'ip_address'
                ),

            'last_seen_at' =>
                $this->formatDate(
                    $this->getAttribute(
                        'last_seen_at'
                    )
                ),

            'last_used_at' =>
                $this->formatDate(
                    $this->getAttribute(
                        'last_used_at'
                    )
                ),

            'revoked_at' =>
                $this->formatDate(
                    $this->getAttribute(
                        'revoked_at'
                    )
                ),

            'created_at' =>
                $this->formatDate(
                    $this->created_at
                ),

            'updated_at' =>
                $this->formatDate(
                    $this->updated_at
                ),
        ];
    }

    private function maskToken(
        mixed $token
    ): ?string {
        if (
            !is_string($token)
            || trim($token) === ''
        ) {
            return null;
        }

        $token = trim($token);

        if (strlen($token) <= 16) {
            return substr($token, 0, 4)
                . '****'
                . substr($token, -4);
        }

        return substr($token, 0, 8)
            . '********'
            . substr($token, -8);
    }

    private function formatDate(
        mixed $value
    ): mixed {
        if (!$value) {
            return null;
        }

        if (
            is_object($value)
            && method_exists(
                $value,
                'toISOString'
            )
        ) {
            return $value->toISOString();
        }

        return $value;
    }
}