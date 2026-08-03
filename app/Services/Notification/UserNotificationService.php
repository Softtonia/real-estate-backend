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
            ])
            ->latest('id');

        if (! empty($filters['type'])) {
            $query->where('type', (string) $filters['type']);
        }

        if (array_key_exists('is_read', $filters) && $filters['is_read'] !== null && $filters['is_read'] !== '') {
            $isRead = filter_var($filters['is_read'], FILTER_VALIDATE_BOOLEAN);

            $isRead
                ? $query->whereNotNull('read_at')
                : $query->whereNull('read_at');
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
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

    private function ensureOwner(User $user, UserNotification $notification): void
    {
        if ((int) $notification->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'notification' => ['Notification not found for authenticated user.'],
            ]);
        }
    }
}