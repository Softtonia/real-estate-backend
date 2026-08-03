<?php

namespace App\Jobs\Notification;

use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationDevice;
use App\Models\Notification\NotificationLog;
use App\Services\Notification\FirebaseMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendFirebaseNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 10;

    public function __construct(
        private readonly int $notificationLogId
    ) {
        $this->onQueue('notifications');
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping('notification-log:' . $this->notificationLogId),
        ];
    }

    public function handle(FirebaseMessagingService $messagingService): void
    {
        /** @var NotificationLog $log */
        $log = NotificationLog::query()
            ->with([
                'device',
                'batch',
            ])
            ->findOrFail($this->notificationLogId);

        if ($log->status === NotificationLog::STATUS_SENT) {
            return;
        }

        $batch = $log->batch;

        try {
            $result = null;

            if ($log->device instanceof NotificationDevice) {
                $result = $messagingService->sendToDevice(
                    device: $log->device,
                    title: $log->title,
                    body: (string) $log->body,
                    imageUrl: $log->payload['image_url'] ?? null,
                    data: $log->payload['data'] ?? [],
                    log: $log
                );
            } elseif (! empty($log->fcm_token)) {
                $result = $messagingService->sendToToken(
                    token: $log->fcm_token,
                    title: $log->title,
                    body: (string) $log->body,
                    imageUrl: $log->payload['image_url'] ?? null,
                    data: $log->payload['data'] ?? [],
                    platform: $log->platform,
                    log: $log
                );
            } else {
                $log->markSkipped('Device and FCM token are missing.');
                $this->incrementBatchFailed($batch);

                return;
            }

            if (($result['status'] ?? false) === true) {
                $this->incrementBatchSuccess($batch);
                return;
            }

            $this->incrementBatchFailed($batch);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $log->markFailed('JOB_ERROR', $e->getMessage());

            $this->incrementBatchFailed($batch);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $log = NotificationLog::query()->find($this->notificationLogId);

        if (! $log) {
            return;
        }

        if ($log->status !== NotificationLog::STATUS_SENT) {
            $log->markFailed('JOB_FAILED', $e->getMessage());
        }

        $batch = $log->batch;

        $this->incrementBatchFailed($batch);
    }

    private function incrementBatchSuccess(?NotificationBatch $batch): void
    {
        if (! $batch) {
            return;
        }

        $batch->increment('success_count');
    }

    private function incrementBatchFailed(?NotificationBatch $batch): void
    {
        if (! $batch) {
            return;
        }

        $batch->increment('failed_count');
    }
}