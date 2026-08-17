<?php

namespace App\Http\Resources\Notification;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminNotificationInboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;

        $data = $this->decodeData(
            $row['data'] ?? null
        );

        return [
            'id' => (string) ($row['id'] ?? ''),

            'type' => $row['type'] ?? null,

            'type_name' => !empty($row['type'])
                ? class_basename(
                    (string) $row['type']
                )
                : null,

            'title' => $data['title']
                ?? $data['subject']
                ?? $data['property_title']
                ?? (!empty($data['event']) ? ucwords(str_replace('_', ' ', (string) $data['event'])) : null)
                ?? 'Notification',

            'message' => $data['message']
                ?? $data['body']
                ?? $data['text']
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Navigation payload
            |--------------------------------------------------------------------------
            |
            | Supports current notification payload:
            |
            | type   = membership
            | screen = my_membership
            | route  = /auth/user/my-current-plan
            |
            */
            'navigation' => [
                'type' => $data['type']
                    ?? data_get($data, 'data.type'),

                'screen' => $data['screen']
                    ?? data_get($data, 'data.screen'),

                'route' => $data['route']
                    ?? data_get($data, 'data.route'),
            ],

            'data' => $data,

            'is_read' => !empty(
                $row['read_at']
            ),

            'read_at' => $this->formatDate(
                $row['read_at'] ?? null
            ),

            'created_at' => $this->formatDate(
                $row['created_at'] ?? null
            ),

            'updated_at' => $this->formatDate(
                $row['updated_at'] ?? null
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