<?php

namespace App\Console\Commands;

use App\Models\MediaUploadBatch;
use App\Services\Media\MediaBatchService;
use Illuminate\Console\Command;

class CleanupAbandonedMediaBatchesCommand extends Command
{
    protected $signature = 'media:cleanup-abandoned-batches {--hours=24 : Hours threshold to treat batch as abandoned}';
    protected $description = 'Clean up abandoned upload batches older than given hours';

    public function handle(MediaBatchService $batchService): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $abandonedBatches = MediaUploadBatch::where('status', '!=', 'completed')
            ->where('created_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($abandonedBatches as $batch) {
            $batchService->cancelBatch($batch);
            $count++;
        }

        $this->info("Cleaned up {$count} abandoned media batches older than {$hours} hours.");

        return self::SUCCESS;
    }
}
