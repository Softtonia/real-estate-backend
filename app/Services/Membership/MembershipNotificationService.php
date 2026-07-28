<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipNotification;
use App\Models\Membership\UserMembership;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class MembershipNotificationService
{
    public function userNotifications(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 50);

        return MembershipNotification::query()
            ->where('user_id', $user->id)
            ->when(!empty($filters['status']) && Schema::hasColumn('membership_notifications', 'status'), function ($query) use ($filters) {
                $query->where('status', $filters['status']);
            })
            ->when(!empty($filters['type']) && Schema::hasColumn('membership_notifications', 'notification_type'), function ($query) use ($filters) {
                $query->where('notification_type', $filters['type']);
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(
        User $user,
        string $type,
        string $title,
        string $message,
        array $metadata = []
    ): ?MembershipNotification {
        if (!Schema::hasTable('membership_notifications')) {
            return null;
        }

        $payload = [
            'user_id' => $user->id,
            'notification_type' => $type,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'channel' => 'in_app',
            'status' => 'unread',
            'metadata' => $metadata,
            'data' => $metadata,
            'read_at' => null,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $payload = $this->filterPayloadByColumns($payload);

        $notification = MembershipNotification::query()->create($payload);

        $this->clearUserCache($user);

        return $notification;
    }

    public function createOnce(
        User $user,
        string $type,
        string $uniqueKey,
        string $title,
        string $message,
        array $metadata = []
    ): ?MembershipNotification {
        if (!Schema::hasTable('membership_notifications')) {
            return null;
        }

        $existingQuery = MembershipNotification::query()
            ->where('user_id', $user->id);

        if (Schema::hasColumn('membership_notifications', 'notification_type')) {
            $existingQuery->where('notification_type', $type);
        } elseif (Schema::hasColumn('membership_notifications', 'type')) {
            $existingQuery->where('type', $type);
        }

        if (Schema::hasColumn('membership_notifications', 'metadata')) {
            $existingQuery->where('metadata', 'like', '%' . $uniqueKey . '%');
        } elseif (Schema::hasColumn('membership_notifications', 'data')) {
            $existingQuery->where('data', 'like', '%' . $uniqueKey . '%');
        } else {
            $existingQuery->where('message', 'like', '%' . $uniqueKey . '%');
        }

        if ($existingQuery->exists()) {
            return null;
        }

        $metadata['unique_key'] = $uniqueKey;

        return $this->create(
            user: $user,
            type: $type,
            title: $title,
            message: $message,
            metadata: $metadata
        );
    }

    public function markAsRead(User $user, MembershipNotification $notification): MembershipNotification
    {
        abort_unless((int) $notification->user_id === (int) $user->id, 404);

        $payload = [];

        if (Schema::hasColumn('membership_notifications', 'read_at')) {
            $payload['read_at'] = now();
        }

        if (Schema::hasColumn('membership_notifications', 'status')) {
            $payload['status'] = 'read';
        }

        if ($payload) {
            $notification->update($payload);
        }

        $this->clearUserCache($user);

        return $notification->fresh();
    }

    public function markAllAsRead(User $user): int
    {
        $query = MembershipNotification::query()
            ->where('user_id', $user->id);

        if (Schema::hasColumn('membership_notifications', 'read_at')) {
            $query->whereNull('read_at');
        } elseif (Schema::hasColumn('membership_notifications', 'status')) {
            $query->where('status', '!=', 'read');
        }

        $payload = [];

        if (Schema::hasColumn('membership_notifications', 'read_at')) {
            $payload['read_at'] = now();
        }

        if (Schema::hasColumn('membership_notifications', 'status')) {
            $payload['status'] = 'read';
        }

        if (!$payload) {
            return 0;
        }

        $count = $query->update($payload);

        $this->clearUserCache($user);

        return $count;
    }

    public function createExpiryReminders(int $daysBeforeExpiry = 7): int
    {
        $count = 0;

        UserMembership::query()
            ->with(['user:id,first_name,last_name,email,phone,role_id', 'plan:id,name,slug'])
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', now()->addDays($daysBeforeExpiry)->toDateString())
            ->select(['id', 'user_id', 'plan_id', 'expiry_date', 'status'])
            ->orderBy('id')
            ->chunkById(200, function ($memberships) use (&$count, $daysBeforeExpiry) {
                foreach ($memberships as $membership) {
                    if (!$membership->user) {
                        continue;
                    }

                    $uniqueKey = "membership_expiry_{$membership->id}_{$daysBeforeExpiry}_days";

                    $notification = $this->createOnce(
                        user: $membership->user,
                        type: 'membership_expiry_reminder',
                        uniqueKey: $uniqueKey,
                        title: 'Membership Expiry Reminder',
                        message: 'Your membership plan ' . ($membership->plan?->name ?? '') . ' will expire in ' . $daysBeforeExpiry . ' days.',
                        metadata: [
                            'membership_id' => $membership->id,
                            'plan_id' => $membership->plan_id,
                            'plan_name' => $membership->plan?->name,
                            'expiry_date' => optional($membership->expiry_date)->toDateTimeString(),
                            'days_before_expiry' => $daysBeforeExpiry,
                        ]
                    );

                    if ($notification) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function createExpiredMembershipNotifications(): int
    {
        $count = 0;

        UserMembership::query()
            ->with(['user:id,first_name,last_name,email,phone,role_id', 'plan:id,name,slug'])
            ->where('status', 'expired')
            ->whereNotNull('expired_at')
            ->whereDate('expired_at', now()->toDateString())
            ->select(['id', 'user_id', 'plan_id', 'expiry_date', 'expired_at', 'status'])
            ->orderBy('id')
            ->chunkById(200, function ($memberships) use (&$count) {
                foreach ($memberships as $membership) {
                    if (!$membership->user) {
                        continue;
                    }

                    $uniqueKey = "membership_expired_{$membership->id}";

                    $notification = $this->createOnce(
                        user: $membership->user,
                        type: 'membership_expired',
                        uniqueKey: $uniqueKey,
                        title: 'Membership Expired',
                        message: 'Your membership plan ' . ($membership->plan?->name ?? '') . ' has expired.',
                        metadata: [
                            'membership_id' => $membership->id,
                            'plan_id' => $membership->plan_id,
                            'plan_name' => $membership->plan?->name,
                            'expired_at' => optional($membership->expired_at)->toDateTimeString(),
                        ]
                    );

                    if ($notification) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function filterPayloadByColumns(array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, $key) => Schema::hasColumn('membership_notifications', $key))
            ->all();
    }

    private function clearUserCache(User $user): void
    {
        Cache::store('redis')->forget("membership:user:{$user->id}:notifications");
        Cache::store('redis')->forget("membership:user:{$user->id}:status");
    }
}