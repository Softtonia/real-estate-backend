<?php

namespace App\Jobs;

use App\Models\DynamicPost;
use App\Models\Keyword;
use App\Models\PostType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportKeywordsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public string $batchId,
        public string $storedPath,
        public string $originalFileName,
        public string $fileKey = 'default',
        public ?int $userId = null
    ) {
    }

    public function handle(): void
    {
        try {
            $this->updateProgress([
                'status' => 'processing',
            ]);

            $fullPath = Storage::disk('local')->path($this->storedPath);

            $total = $this->countCsvDataRows($fullPath);

            $this->updateProgress([
                'total' => $total,
            ]);

            $handle = fopen($fullPath, 'r');

            if (! $handle) {
                throw new RuntimeException('Unable to open CSV file.');
            }

            $headerRow = fgetcsv($handle);

            if (! is_array($headerRow) || empty($headerRow)) {
                throw new RuntimeException('CSV header row missing.');
            }

            $headers = $this->mapHeaders($headerRow);

            $requiredHeaders = [
                'slug',
                'keyword_type',
                'post_type_id',
                'post_type_slug',
                'dynamic_post_id',
                'dynamic_post_slug',
                'keyword_list',
            ];

            $hasAnyUsefulHeader = collect($requiredHeaders)
                ->contains(fn ($header) => array_key_exists($header, $headers));

            if (! $hasAnyUsefulHeader) {
                throw new RuntimeException('Invalid CSV headers.');
            }

            $rowNumber = 1;
            $processed = 0;
            $created = 0;
            $updated = 0;
            $failed = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($this->isEmptyRow($row)) {
                    $processed++;
                    $this->updateProgressNumbers($processed, $created, $updated, $failed, $total);
                    continue;
                }

                try {
                    $result = $this->importRow($row, $headers, $rowNumber);

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

    private function importRow(array $row, array $headers, int $rowNumber): string
    {
        return DB::transaction(function () use ($row, $headers, $rowNumber) {
            $data = [
                'id' => $this->cell($row, $headers, 'id'),
                'import_uid' => $this->cell($row, $headers, 'import_uid'),

                'slug' => $this->cell($row, $headers, 'slug'),
                'keyword_type' => $this->cell($row, $headers, 'keyword_type'),

                'post_type_id' => $this->cell($row, $headers, 'post_type_id'),
                'post_type_slug' => $this->cell($row, $headers, 'post_type_slug'),

                'dynamic_post_id' => $this->cell($row, $headers, 'dynamic_post_id'),
                'dynamic_post_slug' => $this->cell($row, $headers, 'dynamic_post_slug'),

                'keyword_list' => $this->cell($row, $headers, 'keyword_list')
                    ?: $this->cell($row, $headers, 'keywords'),
            ];

            $keyword = $this->findExistingKeyword($data, $rowNumber);

            $slug = Str::slug((string) ($data['slug'] ?: $keyword?->slug));

            if ($slug === '') {
                throw new RuntimeException('Slug is required.');
            }

            $duplicateSlugExists = Keyword::query()
                ->where('slug', $slug)
                ->when($keyword, fn ($q) => $q->where('id', '!=', $keyword->id))
                ->exists();

            if ($duplicateSlugExists) {
                throw new RuntimeException("Slug '{$slug}' already exists.");
            }

            $keywordType = strtolower((string) (
                $data['keyword_type']
                ?: $keyword?->keyword_type
                ?: 'post_type'
            ));

            if (! in_array($keywordType, ['post_type', 'dynamic_post'], true)) {
                throw new RuntimeException('keyword_type must be post_type or dynamic_post.');
            }

            $postType = $this->resolvePostType($data, $keyword);

            if (! $postType) {
                throw new RuntimeException('Post type not found.');
            }

            $dynamicPostId = null;

            if ($keywordType === 'dynamic_post') {
                $dynamicPost = $this->resolveDynamicPost($data, $postType, $keyword);

                if (! $dynamicPost) {
                    throw new RuntimeException('Dynamic post not found or does not belong to selected post type.');
                }

                $dynamicPostId = $dynamicPost->id;
            }

            if ($data['keyword_list'] === null && $keyword) {
                $keywordList = $keyword->keyword_list ?? [];
            } else {
                $keywordList = Keyword::normalizeKeywords($data['keyword_list']);
            }

            $payload = [
                'slug' => $slug,
                'keyword_type' => $keywordType,
                'post_type_id' => $postType->id,
                'dynamic_post_id' => $dynamicPostId,
                'keyword_list' => $keywordList,
                'import_uid' => $keyword?->import_uid ?: ($data['import_uid'] ?: (string) Str::uuid()),
                'import_file_key' => $this->fileKey,
                'import_row_number' => $rowNumber,
                'last_import_batch_id' => $this->batchId,
            ];

            if ($keyword) {
                $keyword->update($payload);
                return 'updated';
            }

            Keyword::create($payload);

            return 'created';
        });
    }

    private function findExistingKeyword(array $data, int $rowNumber): ?Keyword
    {
        if (! empty($data['id'])) {
            $keyword = Keyword::find((int) $data['id']);

            if ($keyword) {
                return $keyword;
            }
        }

        if (! empty($data['import_uid'])) {
            $keyword = Keyword::where('import_uid', $data['import_uid'])->first();

            if ($keyword) {
                return $keyword;
            }
        }

        $keyword = Keyword::where('import_file_key', $this->fileKey)
            ->where('import_row_number', $rowNumber)
            ->first();

        if ($keyword) {
            return $keyword;
        }

        if (! empty($data['slug'])) {
            return Keyword::where('slug', Str::slug((string) $data['slug']))->first();
        }

        return null;
    }

    private function resolvePostType(array $data, ?Keyword $keyword = null): ?PostType
    {
        if (! empty($data['post_type_id'])) {
            return PostType::find((int) $data['post_type_id']);
        }

        if (! empty($data['post_type_slug'])) {
            return PostType::where('slug', $data['post_type_slug'])->first();
        }

        if ($keyword?->post_type_id) {
            return PostType::find($keyword->post_type_id);
        }

        return null;
    }

    private function resolveDynamicPost(array $data, PostType $postType, ?Keyword $keyword = null): ?DynamicPost
    {
        if (! empty($data['dynamic_post_id'])) {
            return DynamicPost::where('id', (int) $data['dynamic_post_id'])
                ->where('post_type_id', $postType->id)
                ->first();
        }

        if (! empty($data['dynamic_post_slug'])) {
            return DynamicPost::where('slug', $data['dynamic_post_slug'])
                ->where('post_type_id', $postType->id)
                ->first();
        }

        if ($keyword?->dynamic_post_id) {
            return DynamicPost::where('id', $keyword->dynamic_post_id)
                ->where('post_type_id', $postType->id)
                ->first();
        }

        return null;
    }

    private function countCsvDataRows(string $fullPath): int
    {
        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            return 0;
        }

        $count = 0;
        $rowNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber === 1) {
                continue;
            }

            $count++;
        }

        fclose($handle);

        return $count;
    }

    private function mapHeaders(array $headerRow): array
    {
        $headers = [];

        foreach ($headerRow as $index => $value) {
            $key = $this->headerKey((string) $value);

            if ($key !== '') {
                $headers[$key] = $index;
            }
        }

        return $headers;
    }

    private function headerKey(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim((string) $value, '_');
    }

    private function cell(array $row, array $headers, string $key): mixed
    {
        if (! array_key_exists($key, $headers)) {
            return null;
        }

        $index = $headers[$key];

        $value = $row[$index] ?? null;

        return is_string($value) ? trim($value) : $value;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
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

        $current = Cache::store('redis')->get($key, []);

        Cache::store('redis')->put($key, array_merge($current, $data), now()->addDay());
    }

    private function pushError(array $error): void
    {
        $key = $this->progressKey();

        $current = Cache::store('redis')->get($key, []);

        $errors = $current['errors'] ?? [];

        if (count($errors) < 200) {
            $errors[] = $error;
        }

        $current['errors'] = $errors;

        Cache::store('redis')->put($key, $current, now()->addDay());
    }

    private function progressKey(): string
    {
        return 'keyword_import:' . $this->batchId;
    }
}