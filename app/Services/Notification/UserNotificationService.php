<?php

namespace App\Services\Notification;

use App\Models\Notification\UserNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserNotificationService
{
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->select([
                'id',
                'user_id',
                'batch_id',
                'title',
                'body',
                'image_url',
                'data',
                'type',
                'read_at',
                'created_at',
                'updated_at',
            ]);

        if (! empty($filters['type'])) {
            $query->where('type', trim((string) $filters['type']));
        }

        /*
        |--------------------------------------------------------------------------
        | Read / Unread filter
        |--------------------------------------------------------------------------
        | Supported:
        | ?is_read=true
        | ?is_read=false
        | ?is_read=1
        | ?is_read=0
        | ?status=read
        | ?status=unread
        */
        $readFilter = $this->resolveReadFilter($filters);

        if ($readFilter === true) {
            $query->whereNotNull('read_at');
        }

        if ($readFilter === false) {
            $query->whereNull('read_at');
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query
            ->latest('id')
            ->paginate($perPage);
    }

    public function show(User $user, UserNotification $notification): UserNotification
    {
        $this->ensureOwner($user, $notification);

        return $notification;
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markAsRead(User $user, UserNotification $notification): UserNotification
    {
        $this->ensureOwner($user, $notification);

        if ($notification->read_at) {
            return $notification;
        }

        $notification->forceFill([
            'read_at' => now(),
        ])->save();

        return $notification->refresh();
    }

    public function markAllAsRead(User $user): int
    {
        return DB::transaction(function () use ($user) {
            $query = UserNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at');

            $count = (clone $query)->count();

            if ($count === 0) {
                return 0;
            }

            $query->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

            return $count;
        });
    }

    public function delete(User $user, UserNotification $notification): void
    {
        $this->ensureOwner($user, $notification);

        $notification->delete();
    }

    private function resolveReadFilter(array $filters): ?bool
    {
        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = strtolower(trim((string) $filters['status']));

            if (in_array($status, ['read', 'seen', 'opened'], true)) {
                return true;
            }

            if (in_array($status, ['unread', 'unseen', 'new'], true)) {
                return false;
            }
        }

        if (! array_key_exists('is_read', $filters)) {
            return null;
        }

        $value = $filters['is_read'];

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, ['1', 'true', 'yes', 'y', 'read'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'n', 'unread'], true)) {
            return false;
        }

        return null;
    }

    private function ensureOwner(User $user, UserNotification $notification): void
    {
        if ((int) $notification->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'notification' => ['Notification not found for authenticated user.'],
            ]);
        }
    }
}