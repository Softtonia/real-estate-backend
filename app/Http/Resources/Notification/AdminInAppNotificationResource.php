<?php

namespace App\Http\Resources\Notification;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInAppNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;

        $data = $this->decodeData(
            $row['data'] ?? null
        );

        $firstName = trim(
            (string) ($row['first_name'] ?? '')
        );

        $lastName = trim(
            (string) ($row['last_name'] ?? '')
        );

        $name = trim(
            $firstName . ' ' . $lastName
        );

        return [
            'id' =>
                (string) ($row['id'] ?? ''),

            'user_id' =>
                !empty($row['user_id'])
                    ? (int) $row['user_id']
                    : null,

            'user' =>
                !empty($row['user_id'])
                    ? [
                        'id' =>
                            (int) $row['user_id'],

                        'name' =>
                            $name !== ''
                                ? $name
                                : (
                                    $row['email']
                                    ?? null
                                ),

                        'email' =>
                            $row['email']
                            ?? null,
                    ]
                    : null,

            'type' =>
                $row['type']
                ?? null,

            'type_name' =>
                !empty($row['type'])
                    ? class_basename(
                        (string) $row['type']
                    )
                    : null,

            'title' =>
                $data['title']
                ?? $data['subject']
                ?? null,

            'message' =>
                $data['message']
                ?? $data['body']
                ?? $data['text']
                ?? null,

            'data' =>
                $data,

            'is_read' =>
                !empty($row['read_at']),

            'read_at' =>
                $this->formatDate(
                    $row['read_at']
                    ?? null
                ),

            'created_at' =>
                $this->formatDate(
                    $row['created_at']
                    ?? null
                ),

            'updated_at' =>
                $this->formatDate(
                    $row['updated_at']
                    ?? null
                ),
        ];
    }

    private function decodeData(
        mixed $value
    ): array {
        if (is_array($value)) {
            return $value;
        }

        if (
            !is_string($value)
            || trim($value) === ''
        ) {
            return [];
        }

        $decoded = json_decode(
            $value,
            true
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    private function formatDate(
        mixed $value
    ): ?string {
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

        return Carbon::parse(
            $value
        )->toISOString();
    }
}