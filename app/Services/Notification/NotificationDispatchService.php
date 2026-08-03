<?php

namespace App\Services\Notification;

use App\Jobs\Notification\ProcessNotificationBatchJob;
use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NotificationDispatchService
{
    public function send(array $data, ?User $admin = null): NotificationBatch
    {
        return DB::transaction(function () use ($data, $admin) {
            $template = $this->resolveTemplate($data['template_id'] ?? null);

            $title = $data['title'] ?? $template?->title;
            $body = $data['body'] ?? $template?->body;
            $imageUrl = array_key_exists('image_url', $data)
                ? $data['image_url']
                : $template?->image_url;

            $channel = $data['channel']
                ?? $template?->channel
                ?? NotificationTemplate::CHANNEL_PUSH_DATABASE;

            if (! $title || ! $body) {
                throw ValidationException::withMessages([
                    'notification' => ['Notification title and body are required.'],
                ]);
            }

            $scheduledAt = ! empty($data['scheduled_at'])
                ? Carbon::parse($data['scheduled_at'])
                : null;

            $batch = NotificationBatch::query()->create([
                'batch_uuid' => (string) Str::uuid(),
                'template_id' => $template?->id,

                'title' => $title,
                'body' => $body,
                'image_url' => $imageUrl,

                'target_type' => $data['target_type'],
                'target_value' => $this->targetValue($data),

                'payload' => [
                    'channel' => $channel,
                    'data' => $data['data'] ?? [],
                ],

                'total_count' => 0,
                'success_count' => 0,
                'failed_count' => 0,

                'status' => $scheduledAt
                    ? NotificationBatch::STATUS_SCHEDULED
                    : NotificationBatch::STATUS_PENDING,

                'scheduled_at' => $scheduledAt,
                'created_by' => $admin?->id,
            ]);

            DB::afterCommit(function () use ($batch, $scheduledAt) {
                $job = ProcessNotificationBatchJob::dispatch($batch->id);

                if ($scheduledAt && $scheduledAt->isFuture()) {
                    $job->delay($scheduledAt);
                }
            });

            return $batch->load([
                'createdBy:id,first_name,last_name,email',
            ]);
        });
    }

    private function resolveTemplate(?int $templateId): ?NotificationTemplate
    {
        if (! $templateId) {
            return null;
        }

        $template = NotificationTemplate::query()->find($templateId);

        if (! $template) {
            throw ValidationException::withMessages([
                'template_id' => ['Notification template not found.'],
            ]);
        }

        if (! $template->status) {
            throw ValidationException::withMessages([
                'template_id' => ['Selected notification template is inactive.'],
            ]);
        }

        return $template;
    }

    private function targetValue(array $data): ?string
    {
        $targetType = $data['target_type'];

        $value = match ($targetType) {
            NotificationBatch::TARGET_ALL => null,

            NotificationBatch::TARGET_USER => [
                'user_id' => (int) $data['user_id'],
            ],

            NotificationBatch::TARGET_USERS => [
                'user_ids' => collect($data['user_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            ],

            NotificationBatch::TARGET_ROLE => [
                'role_ids' => collect($data['role_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            ],

            NotificationBatch::TARGET_TOPIC => [
                'topic_id' => (int) $data['topic_id'],
            ],

            NotificationBatch::TARGET_TOKEN => [
                'token' => $data['fcm_token'],
                'platform' => $data['platform'] ?? null,
            ],

            default => null,
        };

        return $value === null ? null : json_encode($value);
    }
}