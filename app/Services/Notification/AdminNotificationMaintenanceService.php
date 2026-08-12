<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminNotificationMaintenanceService
{
    private const DELETE_CHUNK_SIZE = 2000;

    public function clearLogs(
        array $filters = []
    ): int {
        $baseQuery =
            $this->logQuery($filters);

        $deleted = 0;

        while (true) {
            $ids = (clone $baseQuery)
                ->orderBy('id')
                ->limit(
                    self::DELETE_CHUNK_SIZE
                )
                ->pluck('id')
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->all();

            if (empty($ids)) {
                break;
            }

            $deleted +=
                NotificationLog::query()
                    ->whereIn(
                        'id',
                        $ids
                    )
                    ->delete();
        }

        return $deleted;
    }

    public function clearBatch(
        NotificationBatch $batch
    ): array {
        return DB::transaction(
            function () use ($batch) {
                $lockedBatch =
                    NotificationBatch::query()
                        ->whereKey(
                            $batch->getKey()
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $this->assertBatchCanBeCleared(
                    $lockedBatch
                );

                $batchId =
                    (int) $lockedBatch->id;

                $logModel =
                    new NotificationLog();

                $logTable =
                    $logModel->getTable();

                $batchForeignKey =
                    $this->resolveBatchForeignKey(
                        $logTable
                    );

                $deletedLogs = 0;

                if ($batchForeignKey !== null) {
                    $deletedLogs =
                        NotificationLog::query()
                            ->where(
                                $batchForeignKey,
                                $batchId
                            )
                            ->delete();
                }

                $lockedBatch->delete();

                return [
                    'batch_id' =>
                        $batchId,

                    'deleted_logs' =>
                        (int) $deletedLogs,

                    'batch_deleted' =>
                        true,
                ];
            },
            3
        );
    }

    private function logQuery(
        array $filters
    ): Builder {
        $model =
            new NotificationLog();

        $table =
            $model->getTable();

        $query =
            NotificationLog::query();

        if (
            !empty($filters['batch_id'])
        ) {
            $batchForeignKey =
                $this->resolveBatchForeignKey(
                    $table
                );

            if ($batchForeignKey !== null) {
                $query->where(
                    $batchForeignKey,
                    (int) $filters['batch_id']
                );
            }
        }

        if (
            !empty($filters['user_id'])
            && Schema::hasColumn(
                $table,
                'user_id'
            )
        ) {
            $query->where(
                'user_id',
                (int) $filters['user_id']
            );
        }

        if (
            !empty($filters['device_id'])
        ) {
            $deviceForeignKey =
                $this->resolveDeviceForeignKey(
                    $table
                );

            if ($deviceForeignKey !== null) {
                $query->where(
                    $deviceForeignKey,
                    (int) $filters['device_id']
                );
            }
        }

        if (
            !empty($filters['status'])
            && Schema::hasColumn(
                $table,
                'status'
            )
        ) {
            $query->where(
                'status',
                $filters['status']
            );
        }

        if (
            !empty($filters['platform'])
            && Schema::hasColumn(
                $table,
                'platform'
            )
        ) {
            $query->where(
                'platform',
                $filters['platform']
            );
        }

        if (
            !empty($filters['error_code'])
            && Schema::hasColumn(
                $table,
                'error_code'
            )
        ) {
            $query->where(
                'error_code',
                $filters['error_code']
            );
        }

        if (
            !empty($filters['date_from'])
            && Schema::hasColumn(
                $table,
                'created_at'
            )
        ) {
            $query->whereDate(
                'created_at',
                '>=',
                $filters['date_from']
            );
        }

        if (
            !empty($filters['date_to'])
            && Schema::hasColumn(
                $table,
                'created_at'
            )
        ) {
            $query->whereDate(
                'created_at',
                '<=',
                $filters['date_to']
            );
        }

        return $query;
    }

    private function resolveBatchForeignKey(
        string $table
    ): ?string {
        foreach (
            [
                'batch_id',
                'notification_batch_id',
            ] as $column
        ) {
            if (
                Schema::hasColumn(
                    $table,
                    $column
                )
            ) {
                return $column;
            }
        }

        return null;
    }

    private function resolveDeviceForeignKey(
        string $table
    ): ?string {
        foreach (
            [
                'device_id',
                'notification_device_id',
            ] as $column
        ) {
            if (
                Schema::hasColumn(
                    $table,
                    $column
                )
            ) {
                return $column;
            }
        }

        return null;
    }

    private function assertBatchCanBeCleared(
        NotificationBatch $batch
    ): void {
        $status = strtolower(
            trim(
                (string) (
                    $batch->status
                    ?? ''
                )
            )
        );

        if (
            in_array(
                $status,
                [
                    'pending',
                    'queued',
                    'processing',
                    'sending',
                    'running',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'batch' => [
                    'A notification batch cannot be cleared while it is still processing.',
                ],
            ]);
        }
    }
}