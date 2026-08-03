<?php

namespace App\Services\Notification;

use App\Jobs\Notification\SendFirebaseNotificationJob;
use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationDevice;
use App\Models\Notification\NotificationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationRetryService
{
    public function retryBatchFailed(
        NotificationBatch $batch,
        array $filters = []
    ): array {
        $query = NotificationLog::query()
            ->where('batch_id', $batch->id);

        return $this->retryQuery($query, $batch, $filters);
    }

    public function retrySpecificLogs(array $filters = []): array
    {
        $logIds = $filters['log_ids'] ?? [];

        if (empty($logIds)) {
            throw ValidationException::withMessages([
                'log_ids' => ['At least one notification log id is required.'],
            ]);
        }

        $query = NotificationLog::query()
            ->whereIn('id', $logIds);

        return $this->retryQuery($query, null, $filters);
    }

    private function retryQuery($query, ?NotificationBatch $batch, array $filters): array
    {
        $includeSkipped = (bool) ($filters['include_skipped'] ?? false);
        $includeInactiveDevices = (bool) ($filters['include_inactive_devices'] ?? false);

        $statuses = [
            NotificationLog::STATUS_FAILED,
        ];

        if ($includeSkipped) {
            $statuses[] = NotificationLog::STATUS_SKIPPED;
        }

        $logs = $query
            ->whereIn('status', $statuses)
            ->with('device')
            ->select([
                'id',
                'batch_id',
                'device_id',
                'fcm_token',
                'status',
            ])
            ->limit(5000)
            ->get();

        if ($logs->isEmpty()) {
            return [
                'queued_count' => 0,
                'skipped_count' => 0,
                'message' => 'No failed notification logs found for retry.',
            ];
        }

        $queuedCount = 0;
        $skippedCount = 0;
        $batchRetryCounts = [];

        DB::transaction(function () use (
            $logs,
            $includeInactiveDevices,
            &$queuedCount,
            &$skippedCount,
            &$batchRetryCounts
        ) {
            foreach ($logs as $log) {
                if ($log->device_id && $log->device instanceof NotificationDevice) {
                    $deviceInactive = ! $log->device->status || $log->device->revoked_at;

                    if ($deviceInactive && ! $includeInactiveDevices) {
                        $skippedCount++;
                        continue;
                    }
                }

                if (! $log->device_id && ! $log->fcm_token) {
                    $skippedCount++;
                    continue;
                }

                NotificationLog::query()
                    ->where('id', $log->id)
                    ->update([
                        'status' => NotificationLog::STATUS_PENDING,
                        'error_code' => null,
                        'error_message' => null,
                        'firebase_message_id' => null,
                        'sent_at' => null,
                        'updated_at' => now(),
                    ]);

                if ($log->batch_id) {
                    $batchRetryCounts[$log->batch_id] = ($batchRetryCounts[$log->batch_id] ?? 0) + 1;
                }

                DB::afterCommit(function () use ($log) {
                    SendFirebaseNotificationJob::dispatch($log->id);
                });

                $queuedCount++;
            }

            foreach ($batchRetryCounts as $batchId => $count) {
                NotificationBatch::query()
                    ->where('id', $batchId)
                    ->update([
                        'failed_count' => DB::raw('GREATEST(failed_count - ' . (int) $count . ', 0)'),
                        'status' => NotificationBatch::STATUS_PROCESSING,
                        'finished_at' => null,
                        'updated_at' => now(),
                    ]);
            }
        });

        return [
            'queued_count' => $queuedCount,
            'skipped_count' => $skippedCount,
            'message' => 'Failed notification retry jobs queued successfully.',
        ];
    }
}