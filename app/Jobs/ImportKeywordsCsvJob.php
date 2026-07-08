<?php

namespace App\Jobs;

use App\Services\KeywordCsvImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportKeywordsCsvJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public string $batchId,
        public string $uploadId,
        public string $storedPath,
        public array $mapping,
        public bool $ignoreInvalid = false,
        public ?int $userId = null
    ) {
    }

    public function handle(KeywordCsvImportService $keywordCsvImportService): void
    {
        try {
            $this->updateProgress([
                'status' => 'processing',
            ]);

            $fullPath = Storage::disk('local')->path($this->storedPath);

            $headers = $keywordCsvImportService->readHeaders($fullPath);
            $headerMap = $keywordCsvImportService->mapHeaders($headers);
            $total = $keywordCsvImportService->countDataRows($fullPath);

            $this->updateProgress([
                'total' => $total,
            ]);

            $handle = fopen($fullPath, 'r');

            if (! $handle) {
                throw new \RuntimeException('Unable to open CSV file.');
            }

            fgetcsv($handle);

            $processed = 0;
            $created = 0;
            $updated = 0;
            $failed = 0;
            $rowNumber = 1;
            $naturalKeysSeen = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($keywordCsvImportService->isEmptyRow($row)) {
                    continue;
                }

                try {
                    $prepared = $keywordCsvImportService->prepareRow(
                        row: $row,
                        headerMap: $headerMap,
                        mapping: $this->mapping
                    );

                    $naturalKey = $prepared['natural_key'] ?? null;

                    if ($naturalKey && isset($naturalKeysSeen[$naturalKey])) {
                        throw new \RuntimeException(
                            'Duplicate keyword + post_type + listing inside CSV. Already used on row ' . $naturalKeysSeen[$naturalKey] . '.'
                        );
                    }

                    if ($naturalKey) {
                        $naturalKeysSeen[$naturalKey] = $rowNumber;
                    }

                    if (! $prepared['valid']) {
                        if (! $this->ignoreInvalid) {
                            throw new \RuntimeException($prepared['errors'][0]['message'] ?? 'Invalid row.');
                        }

                        $failed++;

                        $this->pushError([
                            'row' => $rowNumber,
                            'message' => $prepared['errors'][0]['message'] ?? 'Invalid row skipped.',
                        ]);

                        $processed++;
                        $this->updateProgressNumbers($processed, $created, $updated, $failed, $total);

                        continue;
                    }

                    $result = $keywordCsvImportService->upsertPreparedRow($prepared);

                    if ($result === 'created') {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (Throwable $e) {
                    $failed++;

                    $this->pushError([
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                    ]);
                }

                $processed++;

                $this->updateProgressNumbers($processed, $created, $updated, $failed, $total);
            }

            fclose($handle);

            $this->updateProgress([
                'status' => 'completed',
                'percent' => 100,
            ]);
        } catch (Throwable $e) {
            $this->updateProgress([
                'status' => 'failed',
                'errors' => [
                    [
                        'row' => null,
                        'message' => $e->getMessage(),
                    ],
                ],
            ]);
        }
    }

    private function updateProgressNumbers(
        int $processed,
        int $created,
        int $updated,
        int $failed,
        int $total
    ): void {
        $percent = $total > 0
            ? (int) floor(($processed / $total) * 100)
            : 100;

        $this->updateProgress([
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'percent' => min($percent, 100),
        ]);
    }

    private function updateProgress(array $data): void
    {
        $key = $this->progressKey();

        $current = Cache::get($key, []);

        Cache::put($key, array_merge($current, $data), now()->addDay());
    }

    private function pushError(array $error): void
    {
        $key = $this->progressKey();

        $current = Cache::get($key, []);

        $errors = $current['errors'] ?? [];

        if (count($errors) < 200) {
            $errors[] = $error;
        }

        $current['errors'] = $errors;

        Cache::put($key, $current, now()->addDay());
    }

    private function progressKey(): string
    {
        return 'keyword_import:' . $this->batchId;
    }
}