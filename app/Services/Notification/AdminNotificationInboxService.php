<?php

namespace App\Services\Notification;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminNotificationInboxService
{
    public function index(
        User $admin,
        array $filters = []
    ): LengthAwarePaginator {
        $this->assertValidAdmin($admin);

        $query = $this->baseQuery($admin);

        $readStatus = strtolower(
            trim(
                (string) ($filters['read_status'] ?? 'all')
            )
        );

        if ($readStatus === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($readStatus === 'unread') {
            $query->whereNull('read_at');
        }

        if (!empty($filters['type'])) {
            $type = trim(
                (string) $filters['type']
            );

            $query->where(
                'type',
                'like',
                '%' . $type . '%'
            );
        }

        if (!empty($filters['search'])) {
            $search = trim(
                (string) $filters['search']
            );

            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';

                $q->where(
                    'type',
                    'like',
                    $like
                )->orWhere(
                    'data',
                    'like',
                    $like
                );
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where(
                'created_at',
                '>=',
                Carbon::parse(
                    $filters['date_from']
                )->startOfDay()
            );
        }

        if (!empty($filters['date_to'])) {
            $query->where(
                'created_at',
                '<=',
                Carbon::parse(
                    $filters['date_to']
                )->endOfDay()
            );
        }

        $perPage = min(
            100,
            max(
                1,
                (int) ($filters['per_page'] ?? 20)
            )
        );

        $page = max(
            1,
            (int) ($filters['page'] ?? 1)
        );

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                $perPage,
                ['*'],
                'page',
                $page
            );
    }

    public function unreadCount(
        User $admin
    ): int {
        $this->assertValidAdmin($admin);

        return $this->baseQuery($admin)
            ->whereNull('read_at')
            ->count();
    }

    public function findForAdmin(
        User $admin,
        string $notificationId
    ): object {
        $this->assertValidAdmin($admin);

        $notification = $this
            ->baseQuery($admin)
            ->where(
                'id',
                $notificationId
            )
            ->first();

        if (!$notification) {
            throw ValidationException::withMessages([
                'notification' => [
                    'Notification not found.',
                ],
            ]);
        }

        return $notification;
    }

    public function markAsRead(
        User $admin,
        string $notificationId
    ): object {
        $notification = $this->findForAdmin(
            $admin,
            $notificationId
        );

        if (empty($notification->read_at)) {
            DB::table('notifications')
                ->where(
                    'id',
                    $notification->id
                )
                ->where(
                    'notifiable_type',
                    $admin->getMorphClass()
                )
                ->where(
                    'notifiable_id',
                    $admin->getKey()
                )
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $this->findForAdmin(
            $admin,
            $notificationId
        );
    }

    public function markAllAsRead(
        User $admin
    ): int {
        $this->assertValidAdmin($admin);

        return DB::table('notifications')
            ->where(
                'notifiable_type',
                $admin->getMorphClass()
            )
            ->where(
                'notifiable_id',
                $admin->getKey()
            )
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function baseQuery(
        User $admin
    ) {
        return DB::table('notifications')
            ->where(
                'notifiable_type',
                $admin->getMorphClass()
            )
            ->where(
                'notifiable_id',
                $admin->getKey()
            )
            ->select([
                'id',
                'type',
                'notifiable_type',
                'notifiable_id',
                'data',
                'read_at',
                'created_at',
                'updated_at',
            ]);
    }

    private function assertValidAdmin(
        User $admin
    ): void {
        if (
            !$admin->exists
            || (int) $admin->getKey() <= 0
        ) {
            throw ValidationException::withMessages([
                'auth' => [
                    'Authenticated administrator is required.',
                ],
            ]);
        }
    }
}