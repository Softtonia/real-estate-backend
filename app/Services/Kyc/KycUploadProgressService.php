<?php

namespace App\Services\Kyc;

use App\Models\KycRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class KycUploadProgressService
{
    private int $ttlMinutes = 120;

    public function create(string $uploadId, User $user, KycRequest $kycRequest, array $files): array
    {
        $progressFiles = [];

        foreach ($files as $fileKey => $file) {
            $progressFiles[$fileKey] = [
                'document_type' => $file['document_type'],
                'status' => 'queued',
                'percent' => 0,
                'document_id' => null,
                'error' => null,
            ];
        }

        $progress = [
            'upload_id' => $uploadId,
            'batch_id' => null,
            'user_id' => (int) $user->id,
            'kyc_request_id' => (int) $kycRequest->id,
            'status' => 'queued',
            'total_files' => count($progressFiles),
            'queued_files' => count($progressFiles),
            'processing_files' => 0,
            'processed_files' => 0,
            'failed_files' => 0,
            'percent' => 0,
            'files' => $progressFiles,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        $this->put($uploadId, $progress);

        return $progress;
    }

    public function attachBatchId(string $uploadId, string $batchId): array
    {
        return $this->update($uploadId, function (array $progress) use ($batchId) {
            $progress['batch_id'] = $batchId;
            return $progress;
        });
    }

    public function markProcessing(string $uploadId, string $fileKey): array
    {
        return $this->mark($uploadId, $fileKey, [
            'status' => 'processing',
            'percent' => 50,
            'error' => null,
        ]);
    }

    public function markCompleted(string $uploadId, string $fileKey, int $documentId): array
    {
        return $this->mark($uploadId, $fileKey, [
            'status' => 'completed',
            'percent' => 100,
            'document_id' => $documentId,
            'error' => null,
        ]);
    }

    public function markFailed(string $uploadId, string $fileKey, string $error): array
    {
        return $this->mark($uploadId, $fileKey, [
            'status' => 'failed',
            'percent' => 0,
            'error' => $error,
        ]);
    }

    public function get(string $uploadId): ?array
    {
        $progress = Cache::store('redis')->get($this->key($uploadId));

        return is_array($progress) ? $progress : null;
    }

    private function mark(string $uploadId, string $fileKey, array $payload): array
    {
        return $this->update($uploadId, function (array $progress) use ($fileKey, $payload) {
            if (!isset($progress['files'][$fileKey])) {
                return $progress;
            }

            $progress['files'][$fileKey] = array_merge(
                $progress['files'][$fileKey],
                $payload
            );

            return $progress;
        });
    }

    private function update(string $uploadId, callable $callback): array
    {
        $lockKey = $this->key($uploadId) . ':lock';

        return Cache::store('redis')->lock($lockKey, 10)->block(5, function () use ($uploadId, $callback) {
            $progress = $this->get($uploadId) ?? [];
            $progress = $callback($progress);
            $progress = $this->syncCounters($progress);
            $progress['updated_at'] = now()->toDateTimeString();

            $this->put($uploadId, $progress);

            return $progress;
        });
    }

    private function syncCounters(array $progress): array
    {
        $files = $progress['files'] ?? [];

        $queued = 0;
        $processing = 0;
        $processed = 0;
        $failed = 0;

        foreach ($files as $file) {
            $status = $file['status'] ?? 'queued';

            if ($status === 'queued') {
                $queued++;
            }

            if ($status === 'processing') {
                $processing++;
            }

            if ($status === 'completed') {
                $processed++;
            }

            if ($status === 'failed') {
                $failed++;
            }
        }

        $total = max(1, (int) ($progress['total_files'] ?? count($files) ?: 1));
        $done = $processed + $failed;

        $progress['queued_files'] = $queued;
        $progress['processing_files'] = $processing;
        $progress['processed_files'] = $processed;
        $progress['failed_files'] = $failed;
        $progress['percent'] = min(100, (int) round(($done / $total) * 100));

        if ($done >= $total) {
            $progress['status'] = $failed > 0 ? 'completed_with_errors' : 'completed';
        } elseif ($processing > 0) {
            $progress['status'] = 'processing';
        } else {
            $progress['status'] = 'queued';
        }

        return $progress;
    }

    private function put(string $uploadId, array $progress): void
    {
        Cache::store('redis')->put(
            $this->key($uploadId),
            $progress,
            now()->addMinutes($this->ttlMinutes)
        );
    }

    private function key(string $uploadId): string
    {
        return 'kyc:upload:' . $uploadId;
    }
}