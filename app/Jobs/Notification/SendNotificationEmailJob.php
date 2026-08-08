<?php

namespace App\Jobs\Notification;

use App\Mail\Notification\GenericNotificationMail;
use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationEmailJob implements ShouldQueue
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
        $this->onQueue('emails');
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping('notification-email-log:' . $this->notificationLogId),
        ];
    }

    public function handle(): void
    {
        /** @var NotificationLog $log */
        $log = NotificationLog::query()
            ->with('batch')
            ->findOrFail($this->notificationLogId);

        if ($log->status === NotificationLog::STATUS_SENT) {
            return;
        }

        $payload = is_array($log->payload) ? $log->payload : [];

        $email = $payload['email'] ?? null;
        $name = $payload['name'] ?? null;

        if (! $email) {
            $log->markSkipped('Email address is missing.');
            $this->incrementBatchFailed($log->batch);
            return;
        }

        try {
            Mail::to($email, $name ?: null)->send(
                new GenericNotificationMail(
                    title: $log->title,
                    body: (string) $log->body,
                    imageUrl: $payload['image_url'] ?? null,
                    data: $payload['data'] ?? [],
                    userName: $name
                )
            );

            $log->forceFill([
                'status' => NotificationLog::STATUS_SENT,
                'error_code' => null,
                'error_message' => null,
                'sent_at' => now(),
            ])->save();

            $this->incrementBatchSuccess($log->batch);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $log->markFailed('MAIL_ERROR', $e->getMessage());

            $this->incrementBatchFailed($log->batch);

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
            $log->markFailed('MAIL_JOB_FAILED', $e->getMessage());
        }

        $this->incrementBatchFailed($log->batch);
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