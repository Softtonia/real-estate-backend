<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationDevice;
use App\Models\Notification\NotificationTopic;
use App\Models\Notification\NotificationTopicSubscriber;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationTopicService
{
    public function adminList(array $filters = []): LengthAwarePaginator
    {
        $query = NotificationTopic::query()
            ->withCount(['subscribers', 'activeSubscribers'])
            ->latest('id');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    public function userList(User $user, array $filters = []): LengthAwarePaginator
    {
        $subscribedTopicIds = NotificationTopicSubscriber::query()
            ->where('user_id', $user->id)
            ->where('status', true)
            ->pluck('topic_id')
            ->unique()
            ->values()
            ->all();

        $query = NotificationTopic::query()
            ->where('status', true)
            ->withCount(['activeSubscribers'])
            ->select([
                'id',
                'name',
                'slug',
                'description',
                'status',
                'created_at',
                'updated_at',
            ])
            ->latest('id');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        $topics = $query->paginate($perPage);

        $topics->getCollection()->transform(function (NotificationTopic $topic) use ($subscribedTopicIds) {
            $topic->setAttribute('is_subscribed', in_array($topic->id, $subscribedTopicIds, true));

            return $topic;
        });

        return $topics;
    }

    public function create(array $data): NotificationTopic
    {
        return NotificationTopic::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'status' => array_key_exists('status', $data) ? (bool) $data['status'] : true,
        ])->loadCount(['subscribers', 'activeSubscribers']);
    }

    public function update(NotificationTopic $topic, array $data): NotificationTopic
    {
        $payload = [];

        foreach (['name', 'slug', 'description', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('status', $payload)) {
            $payload['status'] = (bool) $payload['status'];
        }

        $topic->forceFill($payload)->save();

        return $topic->refresh()->loadCount(['subscribers', 'activeSubscribers']);
    }

    public function delete(NotificationTopic $topic): void
    {
        $topic->delete();
    }

    public function subscribers(NotificationTopic $topic, array $filters = []): LengthAwarePaginator
    {
        $query = NotificationTopicSubscriber::query()
            ->where('topic_id', $topic->id)
            ->with([
                'user:id,first_name,last_name,email,phone',
                'device:id,platform,app_type,device_id,device_name,status',
            ])
            ->latest('id');

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['platform'])) {
            $query->whereHas('device', function ($query) use ($filters) {
                $query->where('platform', strtolower((string) $filters['platform']));
            });
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    public function subscribe(User $user, NotificationTopic $topic, array $data = []): array
    {
        if (! $topic->status) {
            throw ValidationException::withMessages([
                'topic' => ['This notification topic is inactive.'],
            ]);
        }

        return DB::transaction(function () use ($user, $topic, $data) {
            $deviceQuery = NotificationDevice::query()
                ->active()
                ->where('user_id', $user->id);

            if (! empty($data['device_id'])) {
                $deviceQuery->where('id', (int) $data['device_id']);
            }

            $devices = $deviceQuery->get(['id', 'user_id']);

            if ($devices->isEmpty()) {
                throw ValidationException::withMessages([
                    'device' => ['No active notification device found for subscription.'],
                ]);
            }

            $subscribedCount = 0;

            foreach ($devices as $device) {
                $subscriber = NotificationTopicSubscriber::query()->firstOrNew([
                    'topic_id' => $topic->id,
                    'user_id' => $user->id,
                    'device_id' => $device->id,
                ]);

                $subscriber->status = true;
                $subscriber->save();

                $subscribedCount++;
            }

            return [
                'topic_id' => $topic->id,
                'subscribed_count' => $subscribedCount,
            ];
        });
    }

    public function unsubscribe(User $user, NotificationTopic $topic, array $data = []): array
    {
        $query = NotificationTopicSubscriber::query()
            ->where('topic_id', $topic->id)
            ->where('user_id', $user->id)
            ->where('status', true);

        if (! empty($data['device_id'])) {
            $query->where('device_id', (int) $data['device_id']);
        }

        $count = (clone $query)->count();

        $query->update([
            'status' => false,
            'updated_at' => now(),
        ]);

        return [
            'topic_id' => $topic->id,
            'unsubscribed_count' => $count,
        ];
    }
}