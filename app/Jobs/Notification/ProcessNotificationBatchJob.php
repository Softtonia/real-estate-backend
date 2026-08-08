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

            if ($batch->target_type === NotificationBatch::TARGET_TOKEN) {
                $total = $this->processTokenTarget($batch, $channel, $data);
            } else {
                $total = 0;

                if (NotificationTemplate::shouldCreateInbox($channel) || NotificationTemplate::shouldSendEmail($channel)) {
                    $total += $this->processUsersForInboxOrEmail($batch, $channel, $data);
                }

                if (NotificationTemplate::shouldSendPush($channel)) {
                    $total += $this->processPushDevices($batch, $channel, $data);
                }
            }

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
        if (! NotificationTemplate::shouldSendPush($channel)) {
            return 0;
        }

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
                'channel' => NotificationTemplate::CHANNEL_PUSH,
                'image_url' => $batch->image_url,
                'data' => $data,
            ],
            'status' => NotificationLog::STATUS_PENDING,
        ]);

        SendFirebaseNotificationJob::dispatch($log->id)
            ->onQueue('notifications');

        return 1;
    }

    private function processUsersForInboxOrEmail(NotificationBatch $batch, string $channel, array $data): int
    {
        $total = 0;

        $this->targetUsersQuery($batch)
            ->select(['id', 'first_name', 'last_name', 'email', 'role_id'])
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($users) use ($batch, $channel, $data, &$total) {
                foreach ($users as $user) {
                    if (NotificationTemplate::shouldCreateInbox($channel)) {
                        $this->createInboxNotification($batch, (int) $user->id, $data);
                        $batch->increment('success_count');
                        $total++;
                    }

                    if (NotificationTemplate::shouldSendEmail($channel)) {
                        if (empty($user->email)) {
                            $batch->increment('failed_count');
                            continue;
                        }

                        $log = NotificationLog::query()->create([
                            'batch_id' => $batch->id,
                            'user_id' => $user->id,
                            'device_id' => null,
                            'fcm_token' => null,
                            'platform' => null,
                            'title' => $batch->title,
                            'body' => $batch->body,
                            'payload' => [
                                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                                'email' => $user->email,
                                'name' => $this->userName($user),
                                'image_url' => $batch->image_url,
                                'data' => $data,
                            ],
                            'status' => NotificationLog::STATUS_PENDING,
                        ]);

                        SendNotificationEmailJob::dispatch($log->id)
                            ->onQueue('emails');

                        $total++;
                    }
                }
            });

        return $total;
    }

    private function processPushDevices(NotificationBatch $batch, string $channel, array $data): int
    {
        $total = 0;

        $this->targetDevicesQuery($batch)
            ->select([
                'id',
                'user_id',
                'fcm_token',
                'platform',
                'status',
                'revoked_at',
            ])
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($devices) use ($batch, $data, &$total) {
                foreach ($devices as $device) {
                    if (empty($device->fcm_token)) {
                        $batch->increment('failed_count');
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
                            'channel' => NotificationTemplate::CHANNEL_PUSH,
                            'image_url' => $batch->image_url,
                            'data' => $data,
                        ],
                        'status' => NotificationLog::STATUS_PENDING,
                    ]);

                    SendFirebaseNotificationJob::dispatch($log->id)
                        ->onQueue('notifications');

                    $total++;
                }
            });

        return $total;
    }

    private function targetUsersQuery(NotificationBatch $batch): Builder
    {
        $targetValue = $this->decodeTargetValue($batch->target_value);

        $query = User::query();

        return match ($batch->target_type) {
            NotificationBatch::TARGET_USER => $query->where('id', $this->singleUserId($targetValue)),

            NotificationBatch::TARGET_USERS => $query->whereIn('id', $this->userIds($targetValue)),

            NotificationBatch::TARGET_ROLE => $query->whereIn('role_id', $this->resolveRoleIds($targetValue)),

            NotificationBatch::TARGET_TOPIC => $query->whereIn('id', function ($subQuery) use ($targetValue) {
                $subQuery->from('notification_topic_subscribers')
                    ->select('user_id')
                    ->where('topic_id', $this->topicId($targetValue))
                    ->where('status', true)
                    ->whereNotNull('user_id');
            }),

            NotificationBatch::TARGET_ALL => $query,

            default => $query->whereRaw('1 = 0'),
        };
    }

    private function targetDevicesQuery(NotificationBatch $batch): Builder
    {
        $targetValue = $this->decodeTargetValue($batch->target_value);

        $query = NotificationDevice::query()->active();

        return match ($batch->target_type) {
            NotificationBatch::TARGET_USER => $query->where('user_id', $this->singleUserId($targetValue)),

            NotificationBatch::TARGET_USERS => $query->whereIn('user_id', $this->userIds($targetValue)),

            NotificationBatch::TARGET_ROLE => $query->whereIn('user_id', function ($subQuery) use ($targetValue) {
                $subQuery->from('users')
                    ->select('id')
                    ->whereIn('role_id', $this->resolveRoleIds($targetValue));
            }),

            NotificationBatch::TARGET_TOPIC => $query->where(function ($deviceQuery) use ($targetValue) {
                $topicId = $this->topicId($targetValue);

                $deviceQuery
                    ->whereIn('id', function ($subQuery) use ($topicId) {
                        $subQuery->from('notification_topic_subscribers')
                            ->select('device_id')
                            ->where('topic_id', $topicId)
                            ->where('status', true)
                            ->whereNotNull('device_id');
                    })
                    ->orWhereIn('user_id', function ($subQuery) use ($topicId) {
                        $subQuery->from('notification_topic_subscribers')
                            ->select('user_id')
                            ->where('topic_id', $topicId)
                            ->where('status', true)
                            ->whereNotNull('user_id');
                    });
            }),

            NotificationBatch::TARGET_ALL => $query,

            default => $query->whereRaw('1 = 0'),
        };
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
            return NotificationTemplate::normalizeChannel($payload['channel']);
        }

        if ($batch->template instanceof NotificationTemplate) {
            return NotificationTemplate::normalizeChannel($batch->template->channel);
        }

        return NotificationTemplate::CHANNEL_PUSH_IN_APP;
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

        $data['type'] = $data['type'] ?? 'general';
        $data['screen'] = $data['screen'] ?? 'home';
        $data['batch_id'] = (string) $batch->id;
        $data['batch_uuid'] = (string) $batch->batch_uuid;

        return $data;
    }

    private function decodeTargetValue(?string $targetValue): mixed
    {
        if ($targetValue === null || $targetValue === '') {
            return null;
        }

        $decoded = json_decode($targetValue, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $targetValue;
    }

    private function singleUserId(mixed $targetValue): int
    {
        if (is_numeric($targetValue)) {
            return (int) $targetValue;
        }

        if (is_array($targetValue)) {
            return (int) ($targetValue['user_id'] ?? $targetValue['id'] ?? 0);
        }

        return 0;
    }

    private function userIds(mixed $targetValue): array
    {
        $userIds = is_array($targetValue)
            ? ($targetValue['user_ids'] ?? $targetValue)
            : [];

        return collect($userIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function topicId(mixed $targetValue): int
    {
        if (is_numeric($targetValue)) {
            return (int) $targetValue;
        }

        if (is_array($targetValue)) {
            return (int) ($targetValue['topic_id'] ?? 0);
        }

        return 0;
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

    private function userName(object $user): string
    {
        return trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
    }
}