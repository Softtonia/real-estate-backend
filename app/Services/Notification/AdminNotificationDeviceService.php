<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationDevice;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class AdminNotificationDeviceService
{
    public function devices(
        array $filters = []
    ): LengthAwarePaginator {
        $model = new NotificationDevice();

        $table = $model->getTable();

        $deviceColumns = collect([
            'id',
            'user_id',
            'device_id',
            'device_uuid',
            'fcm_token',
            'device_token',
            'token',
            'platform',
            'status',
            'device_name',
            'device_model',
            'app_version',
            'os_version',
            'ip_address',
            'user_agent',
            'last_seen_at',
            'last_used_at',
            'revoked_at',
            'created_at',
            'updated_at',
        ])
            ->filter(
                fn (string $column) =>
                    Schema::hasColumn(
                        $table,
                        $column
                    )
            )
            ->map(
                fn (string $column) =>
                    $table . '.' . $column
            )
            ->values()
            ->all();

        $query = NotificationDevice::query()
            ->select($deviceColumns);

        if (
            Schema::hasColumn(
                $table,
                'user_id'
            )
            && Schema::hasTable('users')
        ) {
            $query
                ->leftJoin(
                    'users as notification_device_users',
                    'notification_device_users.id',
                    '=',
                    $table . '.user_id'
                );

            if (
                Schema::hasColumn(
                    'users',
                    'first_name'
                )
            ) {
                $query->addSelect([
                    'notification_device_users.first_name as notification_user_first_name',
                ]);
            }

            if (
                Schema::hasColumn(
                    'users',
                    'last_name'
                )
            ) {
                $query->addSelect([
                    'notification_device_users.last_name as notification_user_last_name',
                ]);
            }

            if (
                Schema::hasColumn(
                    'users',
                    'email'
                )
            ) {
                $query->addSelect([
                    'notification_device_users.email as notification_user_email',
                ]);
            }

            if (
                Schema::hasColumn(
                    'users',
                    'phone'
                )
            ) {
                $query->addSelect([
                    'notification_device_users.phone as notification_user_phone',
                ]);
            }
        }

        if (
            !empty($filters['user_id'])
            && Schema::hasColumn(
                $table,
                'user_id'
            )
        ) {
            $query->where(
                $table . '.user_id',
                (int) $filters['user_id']
            );
        }

        if (
            !empty($filters['status'])
            && !in_array(
                $filters['status'],
                ['all', '*'],
                true
            )
            && Schema::hasColumn(
                $table,
                'status'
            )
        ) {
            $query->where(
                $table . '.status',
                $filters['status']
            );
        }

        if (
            !empty($filters['platform'])
            && !in_array(
                $filters['platform'],
                ['all', '*'],
                true
            )
            && Schema::hasColumn(
                $table,
                'platform'
            )
        ) {
            $query->where(
                $table . '.platform',
                $filters['platform']
            );
        }

        if (
            !empty($filters['date_from'])
            && Schema::hasColumn(
                $table,
                'created_at'
            )
        ) {
            $query->whereDate(
                $table . '.created_at',
                '>=',
                $filters['date_from']
            );
        }

        if (
            !empty($filters['date_to'])
            && Schema::hasColumn(
                $table,
                'created_at'
            )
        ) {
            $query->whereDate(
                $table . '.created_at',
                '<=',
                $filters['date_to']
            );
        }

        if (
            !empty($filters['search'])
        ) {
            $search = trim(
                (string) $filters['search']
            );

            if ($search !== '') {
                $query->where(
                    function ($searchQuery) use (
                        $search,
                        $table
                    ) {
                        $hasCondition = false;

                        foreach (
                            [
                                'device_id',
                                'device_uuid',
                                'device_name',
                                'device_model',
                                'fcm_token',
                                'device_token',
                                'token',
                            ] as $column
                        ) {
                            if (
                                !Schema::hasColumn(
                                    $table,
                                    $column
                                )
                            ) {
                                continue;
                            }

                            if (!$hasCondition) {
                                $searchQuery->where(
                                    $table . '.' . $column,
                                    'like',
                                    "%{$search}%"
                                );

                                $hasCondition = true;
                            } else {
                                $searchQuery->orWhere(
                                    $table . '.' . $column,
                                    'like',
                                    "%{$search}%"
                                );
                            }
                        }

                        if (
                            Schema::hasTable('users')
                        ) {
                            foreach (
                                [
                                    'first_name',
                                    'last_name',
                                    'email',
                                    'phone',
                                ] as $userColumn
                            ) {
                                if (
                                    !Schema::hasColumn(
                                        'users',
                                        $userColumn
                                    )
                                ) {
                                    continue;
                                }

                                if (!$hasCondition) {
                                    $searchQuery->where(
                                        'notification_device_users.'
                                        . $userColumn,
                                        'like',
                                        "%{$search}%"
                                    );

                                    $hasCondition = true;
                                } else {
                                    $searchQuery->orWhere(
                                        'notification_device_users.'
                                        . $userColumn,
                                        'like',
                                        "%{$search}%"
                                    );
                                }
                            }
                        }
                    }
                );
            }
        }

        $perPage = min(
            100,
            max(
                1,
                (int) (
                    $filters['per_page']
                    ?? 20
                )
            )
        );

        return $query
            ->orderByDesc(
                $table . '.id'
            )
            ->paginate($perPage);
    }
}