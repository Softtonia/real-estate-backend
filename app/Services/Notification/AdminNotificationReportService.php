<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationDevice;
use App\Models\Notification\NotificationLog;
use App\Models\Notification\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AdminNotificationReportService
{
    public function dashboard(array $filters = []): array
    {
        [$from, $to] = $this->dateRange($filters);

        $batchQuery = NotificationBatch::query();
        $logQuery = NotificationLog::query();
        $deviceQuery = NotificationDevice::query();
        $inboxQuery = UserNotification::query();

        $this->applyDateRange($batchQuery, $from, $to, 'created_at');
        $this->applyDateRange($logQuery, $from, $to, 'created_at');
        $this->applyDateRange($deviceQuery, $from, $to, 'created_at');
        $this->applyDateRange($inboxQuery, $from, $to, 'created_at');

        return [
            'date_range' => [
                'from' => $from?->toDateTimeString(),
                'to' => $to?->toDateTimeString(),
            ],

            'batches' => [
                'total' => (clone $batchQuery)->count(),
                'pending' => (clone $batchQuery)->where('status', NotificationBatch::STATUS_PENDING)->count(),
                'scheduled' => (clone $batchQuery)->where('status', NotificationBatch::STATUS_SCHEDULED)->count(),
                'processing' => (clone $batchQuery)->where('status', NotificationBatch::STATUS_PROCESSING)->count(),
                'completed' => (clone $batchQuery)->where('status', NotificationBatch::STATUS_COMPLETED)->count(),
                'failed' => (clone $batchQuery)->where('status', NotificationBatch::STATUS_FAILED)->count(),
                'cancelled' => (clone $batchQuery)->where('status', NotificationBatch::STATUS_CANCELLED)->count(),
            ],

            'push_logs' => [
                'total' => (clone $logQuery)->count(),
                'pending' => (clone $logQuery)->where('status', NotificationLog::STATUS_PENDING)->count(),
                'sent' => (clone $logQuery)->where('status', NotificationLog::STATUS_SENT)->count(),
                'failed' => (clone $logQuery)->where('status', NotificationLog::STATUS_FAILED)->count(),
                'skipped' => (clone $logQuery)->where('status', NotificationLog::STATUS_SKIPPED)->count(),
            ],

            'in_app_notifications' => [
                'total' => (clone $inboxQuery)->count(),
                'read' => (clone $inboxQuery)->whereNotNull('read_at')->count(),
                'unread' => (clone $inboxQuery)->whereNull('read_at')->count(),
            ],

            'devices' => [
                'total' => (clone $deviceQuery)->count(),
                'active' => (clone $deviceQuery)->where('status', true)->whereNull('revoked_at')->count(),
                'inactive' => (clone $deviceQuery)->where(function ($query) {
                    $query->where('status', false)->orWhereNotNull('revoked_at');
                })->count(),
                'android' => (clone $deviceQuery)->where('platform', 'android')->count(),
                'ios' => (clone $deviceQuery)->where('platform', 'ios')->count(),
                'web' => (clone $deviceQuery)->where('platform', 'web')->count(),
            ],

            'latest_batches' => NotificationBatch::query()
                ->select([
                    'id',
                    'batch_uuid',
                    'title',
                    'target_type',
                    'total_count',
                    'success_count',
                    'failed_count',
                    'status',
                    'created_at',
                ])
                ->latest('id')
                ->limit(10)
                ->get(),

            'latest_failures' => NotificationLog::query()
                ->select([
                    'id',
                    'batch_id',
                    'user_id',
                    'platform',
                    'status',
                    'error_code',
                    'error_message',
                    'created_at',
                ])
                ->where('status', NotificationLog::STATUS_FAILED)
                ->latest('id')
                ->limit(10)
                ->get(),
        ];
    }

    public function batches(array $filters = []): LengthAwarePaginator
    {
        $query = NotificationBatch::query()
            ->with([
                'template:id,template_key,title,channel,status',
                'createdBy:id,first_name,last_name,email',
            ])
            ->select([
                'id',
                'batch_uuid',
                'template_id',
                'title',
                'body',
                'image_url',
                'target_type',
                'target_value',
                'payload',
                'total_count',
                'success_count',
                'failed_count',
                'status',
                'scheduled_at',
                'started_at',
                'finished_at',
                'created_by',
                'created_at',
                'updated_at',
            ])
            ->latest('id');

        $this->applyBatchFilters($query, $filters);

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    public function batchDetail(NotificationBatch $batch): array
    {
        $batch->load([
            'template:id,template_key,title,channel,status',
            'createdBy:id,first_name,last_name,email',
        ]);

        $logsQuery = NotificationLog::query()
            ->where('batch_id', $batch->id);

        $inboxQuery = UserNotification::query()
            ->where('batch_id', $batch->id);

        return [
            'batch' => $batch,
            'live_stats' => [
                'logs_total' => (clone $logsQuery)->count(),
                'logs_pending' => (clone $logsQuery)->where('status', NotificationLog::STATUS_PENDING)->count(),
                'logs_sent' => (clone $logsQuery)->where('status', NotificationLog::STATUS_SENT)->count(),
                'logs_failed' => (clone $logsQuery)->where('status', NotificationLog::STATUS_FAILED)->count(),
                'logs_skipped' => (clone $logsQuery)->where('status', NotificationLog::STATUS_SKIPPED)->count(),

                'inbox_total' => (clone $inboxQuery)->count(),
                'inbox_read' => (clone $inboxQuery)->whereNotNull('read_at')->count(),
                'inbox_unread' => (clone $inboxQuery)->whereNull('read_at')->count(),
            ],
        ];
    }

    public function logs(array $filters = []): LengthAwarePaginator
    {
        $query = NotificationLog::query()
            ->with([
                'user:id,first_name,last_name,email,phone',
                'device:id,platform,app_type,device_id,device_name,status,last_used_at,revoked_at',
                'batch:id,batch_uuid,target_type,status',
            ])
            ->select([
                'id',
                'batch_id',
                'user_id',
                'device_id',
                'fcm_token',
                'platform',
                'title',
                'body',
                'payload',
                'firebase_message_id',
                'status',
                'error_code',
                'error_message',
                'sent_at',
                'created_at',
                'updated_at',
            ])
            ->latest('id');

        $this->applyLogFilters($query, $filters);

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    public function batchLogs(NotificationBatch $batch, array $filters = []): LengthAwarePaginator
    {
        $filters['batch_id'] = $batch->id;

        return $this->logs($filters);
    }

    private function applyBatchFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($query) use ($search) {
                $query->where('batch_uuid', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['target_type'])) {
            $query->where('target_type', (string) $filters['target_type']);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', (int) $filters['created_by']);
        }

        [$from, $to] = $this->dateRange($filters);
        $this->applyDateRange($query, $from, $to, 'created_at');
    }

    private function applyLogFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['batch_id'])) {
            $query->where('batch_id', (int) $filters['batch_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['device_id'])) {
            $query->where('device_id', (int) $filters['device_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['platform'])) {
            $query->where('platform', strtolower((string) $filters['platform']));
        }

        if (! empty($filters['error_code'])) {
            $query->where('error_code', (string) $filters['error_code']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%")
                    ->orWhere('firebase_message_id', 'like', "%{$search}%");
            });
        }

        [$from, $to] = $this->dateRange($filters);
        $this->applyDateRange($query, $from, $to, 'created_at');
    }

    private function dateRange(array $filters): array
    {
        $from = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : null;

        $to = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : null;

        return [$from, $to];
    }

    private function applyDateRange(Builder $query, ?Carbon $from, ?Carbon $to, string $column): void
    {
        if ($from) {
            $query->where($column, '>=', $from);
        }

        if ($to) {
            $query->where($column, '<=', $to);
        }
    }
}