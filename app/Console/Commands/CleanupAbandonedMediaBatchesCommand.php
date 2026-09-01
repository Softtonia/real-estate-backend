<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\MediaBatchService;
use Illuminate\Console\Command;

class CleanupAbandonedMediaBatchesCommand extends Command
{
    protected $signature = 'media:cleanup-abandoned-batches {--hours=24 : Retention period in hours}';

    protected $description = 'Clean up abandoned or expired media upload batches and orphaned files from local storage';

    public function handle(MediaBatchService $mediaBatchService): int
    {
        $hours = (int) $this->option('hours');

        $this->info("Scanning for abandoned upload batches older than {$hours} hours...");

        $cleanedCount = $mediaBatchService->cleanupAbandonedBatches($hours);

        $this->info("Successfully cleaned up {$cleanedCount} abandoned batch(es) and associated files.");

        return Command::SUCCESS;
    }
}
