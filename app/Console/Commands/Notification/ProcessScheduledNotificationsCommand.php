<?php

namespace App\Console\Commands\Notification;

use App\Jobs\Notification\ProcessNotificationBatchJob;
use App\Models\Notification\NotificationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessScheduledNotificationsCommand extends Command
{
    protected $signature = 'notifications:process-scheduled 
        {--limit=100 : Maximum batches to dispatch per run}
        {--dry-run : Show eligible batches without dispatching jobs}';

    protected $description = 'Dispatch due scheduled and stuck pending notification batches.';

    public function handle(): int
    {
        $limit = min((int) $this->option('limit'), 500);
        $dryRun = (bool) $this->option('dry-run');

        $lock = Cache::lock('notifications:process-scheduled-lock', 55);

        if (! $lock->get()) {
            $this->warn('Another notification scheduler process is already running.');
            return self::SUCCESS;
        }

        try {
            $query = NotificationBatch::query()
                ->where(function ($query) {
                    $query->where(function ($query) {
                        $query->where('status', NotificationBatch::STATUS_SCHEDULED)
                            ->whereNotNull('scheduled_at')
                            ->where('scheduled_at', '<=', now());
                    });

                    $query->orWhere(function ($query) {
                        $query->where('status', NotificationBatch::STATUS_PENDING)
                            ->where('created_at', '<=', now()->subMinutes(2));
                    });
                })
                ->orderBy('id')
                ->limit($limit);

            $batches = $query->get([
                'id',
                'batch_uuid',
                'title',
                'status',
                'scheduled_at',
                'created_at',
            ]);

            if ($batches->isEmpty()) {
                $this->info('No notification batches found for processing.');
                return self::SUCCESS;
            }

            if ($dryRun) {
                $this->table(
                    ['ID', 'UUID', 'Title', 'Status', 'Scheduled At', 'Created At'],
                    $batches->map(function ($batch) {
                        return [
                            $batch->id,
                            $batch->batch_uuid,
                            $batch->title,
                            $batch->status,
                            optional($batch->scheduled_at)->toDateTimeString(),
                            optional($batch->created_at)->toDateTimeString(),
                        ];
                    })->toArray()
                );

                return self::SUCCESS;
            }

            $dispatched = 0;

            foreach ($batches as $batch) {
                DB::transaction(function () use ($batch, &$dispatched) {
                    $updated = NotificationBatch::query()
                        ->where('id', $batch->id)
                        ->whereIn('status', [
                            NotificationBatch::STATUS_PENDING,
                            NotificationBatch::STATUS_SCHEDULED,
                        ])
                        ->update([
                            'status' => NotificationBatch::STATUS_PROCESSING,
                            'started_at' => now(),
                            'finished_at' => null,
                            'updated_at' => now(),
                        ]);

                    if ($updated === 0) {
                        return;
                    }

                    DB::afterCommit(function () use ($batch) {
                        ProcessNotificationBatchJob::dispatch($batch->id);
                    });

                    $dispatched++;
                });
            }

            $this->info("Notification batches dispatched: {$dispatched}");

            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }
}