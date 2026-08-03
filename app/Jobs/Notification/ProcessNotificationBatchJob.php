<?php

namespace App\Jobs\Notification;

use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationDevice;
use App\Models\Notification\NotificationLog;
use App\Models\Notification\NotificationTemplate;
use App\Models\Notification\NotificationTopicSubscriber;
use App\Models\Notification\UserNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessNotificationBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 15;

    private int $chunkSize = 500;

    public function __construct(
        private readonly int $batchId
    ) {
        $this->onQueue('notifications');
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping('notification-batch:' . $this->batchId),
        ];
    }

    public function handle(): void
    {
        /** @var NotificationBatch $batch */
        $batch = NotificationBatch::query()
            ->with('template')
            ->findOrFail($this->batchId);

        if (in_array($batch->status, [
            NotificationBatch::STATUS_COMPLETED,
            NotificationBatch::STATUS_FAILED,
            NotificationBatch::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        if ($batch->scheduled_at && $batch->scheduled_at->isFuture()) {
            $this->release($batch->scheduled_at->diffInSeconds(now()));

            return;
        }

        $batch->markProcessing();

        try {
            $channel = $this->channel($batch);
            $data = $this->notificationData($batch);

            $total = match ($batch->target_type) {
                NotificationBatch::TARGET_TOKEN => $this->processTokenTarget($batch, $channel, $data),
                NotificationBatch::TARGET_USER => $this->processUserTarget($batch, $channel, $data),
                NotificationBatch::TARGET_USERS => $this->processUsersTarget($batch, $channel, $data),
                NotificationBatch::TARGET_ROLE => $this->processRoleTarget($batch, $channel, $data),
                NotificationBatch::TARGET_TOPIC => $this->processTopicTarget($batch, $channel, $data),
                NotificationBatch::TARGET_ALL => $this->processAllTarget($batch, $channel, $data),
                default => 0,
            };

            $batch->forceFill([
                'total_count' => $total,
            ])->save();

            if ($total === 0) {
                $batch->markFailed();
                return;
            }

            $batch->markCompleted();
        } catch (Throwable $e) {
            report($e);

            $batch->markFailed();

            throw $e;
        }
    }

    private function processTokenTarget(NotificationBatch $batch, string $channel, array $data): int
    {
        $targetValue = $this->decodeTargetValue($batch->target_value);

        $token = is_array($targetValue)
            ? ($targetValue['token'] ?? $targetValue['fcm_token'] ?? null)
            : $targetValue;

        if (! $token) {
            return 0;
        }

        $platform = is_array($targetValue)
            ? ($targetValue['platform'] ?? null)
            : null;

        $log = NotificationLog::query()->create([
            'batch_id' => $batch->id,
            'user_id' => null,
            'device_id' => null,
            'fcm_token' => $token,
            'platform' => $platform,
            'title' => $batch->title,
            'body' => $batch->body,
            'payload' => [
                'image_url' => $batch->image_url,
                'data' => $data,
            ],
            'status' => NotificationLog::STATUS_PENDING,
        ]);

        if ($this->shouldSendPush($channel)) {
            SendFirebaseNotificationJob::dispatch($log->id);
        } else {
            $log->markSkipped('Push channel is disabled for this notification.');
        }

        return 1;
    }

    private function processUserTarget(NotificationBatch $batch, string $channel, array $data): int
    {
        $targetValue = $this->decodeTargetValue($batch->target_value);

        $userId = is_array($targetValue)
            ? ($targetValue['user_id'] ?? $targetValue['id'] ?? null)
            : $targetValue;

        if (! $userId) {
            return 0;
        }

        return $this->processDevicesQuery(
            batch: $batch,
            channel: $channel,
            data: $data,
            query: NotificationDevice::query()
                ->active()
                ->where('user_id', (int) $userId)
        );
    }

    private function processUsersTarget(NotificationBatch $batch, string $channel, array $data): int
    {
        $targetValue = $this->decodeTargetValue($batch->target_value);

        $userIds = is_array($targetValue)
            ? ($targetValue['user_ids'] ?? $targetValue)
            : [];

        $userIds = collect($userIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($userIds)) {
            return 0;
        }

        return $this->processDevicesQuery(
            batch: $batch,
            channel: $channel,
            data: $data,
            query: NotificationDevice::query()
                ->active()
                ->whereIn('user_id', $userIds)
        );
    }

    private function processRoleTarget(NotificationBatch $batch, string $channel, array $data): int
    {
        $targetValue = $this->decodeTargetValue($batch->target_value);

        $roleIds = $this->resolveRoleIds($targetValue);

        if (empty($roleIds)) {
            return 0;
        }

        $userIds = User::query()
            ->whereIn('role_id', $roleIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($userIds)) {
            return 0;
        }

        return $this->processDevicesQuery(
            batch: $batch,
            channel: $channel,
            data: $data,
            query: NotificationDevice::query()
                ->active()
                ->whereIn('user_id', $userIds)
        );
    }

    private function processTopicTarget(NotificationBatch $batch, string $channel, array $data): int
    {
        $targetValue = $this->decodeTargetValue($batch->target_value);

        $topicId = is_array($targetValue)
            ? ($targetValue['topic_id'] ?? null)
            : $targetValue;

        if (! $topicId) {
            return 0;
        }

        $deviceIds = NotificationTopicSubscriber::query()
            ->where('topic_id', (int) $topicId)
            ->where('status', true)
            ->whereNotNull('device_id')
            ->pluck('device_id')
            ->unique()
            ->values()
            ->all();

        if (empty($deviceIds)) {
            return 0;
        }

        return $this->processDevicesQuery(
            batch: $batch,
            channel: $channel,
            data: $data,
            query: NotificationDevice::query()
                ->active()
                ->whereIn('id', $deviceIds)
        );
    }

    private function processAllTarget(NotificationBatch $batch, string $channel, array $data): int
    {
        return $this->processDevicesQuery(
            batch: $batch,
            channel: $channel,
            data: $data,
            query: NotificationDevice::query()->active()
        );
    }

    private function processDevicesQuery(
        NotificationBatch $batch,
        string $channel,
        array $data,
        Builder $query
    ): int {
        $total = 0;

        $query
            ->select([
                'id',
                'user_id',
                'fcm_token',
                'platform',
                'status',
                'revoked_at',
            ])
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($devices) use ($batch, $channel, $data, &$total) {
                foreach ($devices as $device) {
                    $total++;

                    if ($this->shouldCreateInbox($channel) && $device->user_id) {
                        $this->createInboxNotification($batch, (int) $device->user_id, $data);
                    }

                    if (! $this->shouldSendPush($channel)) {
                        continue;
                    }

                    $log = NotificationLog::query()->create([
                        'batch_id' => $batch->id,
                        'user_id' => $device->user_id,
                        'device_id' => $device->id,
                        'fcm_token' => $device->fcm_token,
                        'platform' => $device->platform,
                        'title' => $batch->title,
                        'body' => $batch->body,
                        'payload' => [
                            'image_url' => $batch->image_url,
                            'data' => $data,
                        ],
                        'status' => NotificationLog::STATUS_PENDING,
                    ]);

                    SendFirebaseNotificationJob::dispatch($log->id);
                }
            });

        return $total;
    }

    private function createInboxNotification(NotificationBatch $batch, int $userId, array $data): void
    {
        UserNotification::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'batch_id' => $batch->id,
            ],
            [
                'title' => $batch->title,
                'body' => $batch->body,
                'image_url' => $batch->image_url,
                'data' => $data,
                'type' => $data['type'] ?? 'general',
            ]
        );
    }

    private function channel(NotificationBatch $batch): string
    {
        $payload = $batch->payload ?? [];

        if (! empty($payload['channel'])) {
            return (string) $payload['channel'];
        }

        if ($batch->template instanceof NotificationTemplate) {
            return $batch->template->channel;
        }

        return NotificationTemplate::CHANNEL_PUSH_DATABASE;
    }

    private function notificationData(NotificationBatch $batch): array
    {
        $payload = $batch->payload ?? [];

        $data = [];

        if ($batch->template instanceof NotificationTemplate && is_array($batch->template->data)) {
            $data = $batch->template->data;
        }

        if (! empty($payload['data']) && is_array($payload['data'])) {
            $data = array_merge($data, $payload['data']);
        }

        $data['batch_id'] = (string) $batch->id;
        $data['batch_uuid'] = (string) $batch->batch_uuid;

        return $data;
    }

    private function shouldSendPush(string $channel): bool
    {
        return in_array($channel, [
            NotificationTemplate::CHANNEL_PUSH,
            NotificationTemplate::CHANNEL_PUSH_DATABASE,
        ], true);
    }

    private function shouldCreateInbox(string $channel): bool
    {
        return in_array($channel, [
            NotificationTemplate::CHANNEL_DATABASE,
            NotificationTemplate::CHANNEL_PUSH_DATABASE,
        ], true);
    }

    private function decodeTargetValue(?string $targetValue): mixed
    {
        if ($targetValue === null || $targetValue === '') {
            return null;
        }

        $decoded = json_decode($targetValue, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $targetValue;
    }

    private function resolveRoleIds(mixed $targetValue): array
    {
        $roleIds = [];

        if (is_numeric($targetValue)) {
            $roleIds[] = (string) $targetValue;
        }

        if (is_array($targetValue)) {
            $values = $targetValue['role_ids']
                ?? $targetValue['roles']
                ?? $targetValue['role_id']
                ?? [];

            if (! is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $value) {
                if (is_numeric($value)) {
                    $roleIds[] = (string) $value;
                }
            }
        }

        return array_values(array_unique($roleIds));
    }
}