<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessUserDocumentUploadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $userId,
        public string $uploadId,
        public string $field,
        public string $tempPath,
        public string $extension
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            throw new \Exception('User not found.');
        }

        $this->updateProgress(function (array $progress) {
            $progress['status'] = 'processing';

            $progress['files'][$this->field]['status'] = 'processing';
            $progress['files'][$this->field]['percent'] = 40;
            $progress['files'][$this->field]['error'] = null;

            $progress['updated_at'] = now()->toDateTimeString();

            return $progress;
        });

        if (!Storage::disk('local')->exists($this->tempPath)) {
            throw new \Exception('Temporary uploaded file not found.');
        }

        $directories = [
            'aadhaar_front' => 'uploads/kyc/aadhaarFront',
            'aadhaar_back' => 'uploads/kyc/aadhaarBack',
            'business_proof' => 'uploads/kyc/businessProof',
        ];

        if (!array_key_exists($this->field, $directories)) {
            throw new \Exception('Invalid document field.');
        }

        if (!Schema::hasColumn('user_details', $this->field)) {
            throw new \Exception($this->field . ' column not found in user_details table.');
        }

        $directory = $directories[$this->field];

        /*
        |--------------------------------------------------------------------------
        | Short Filename
        |--------------------------------------------------------------------------
        | Database columns are string, so do not use original long file names.
        |--------------------------------------------------------------------------
        */
        $fileName = 'u'
            . $this->userId
            . '_'
            . time()
            . '_'
            . $this->field
            . '_'
            . uniqid()
            . '.'
            . strtolower($this->extension);

        $finalPath = $directory . '/' . $fileName;

        /*
        |--------------------------------------------------------------------------
        | Move From Local Temp To Public Storage
        |--------------------------------------------------------------------------
        */
        $fileContent = Storage::disk('local')->get($this->tempPath);

        Storage::disk('public')->put($finalPath, $fileContent);

        Storage::disk('local')->delete($this->tempPath);

        /*
        |--------------------------------------------------------------------------
        | Save DB Path
        |--------------------------------------------------------------------------
        | Example:
        | storage/uploads/kyc/aadhaarFront/u8_xxx.png
        |--------------------------------------------------------------------------
        */
        $dbPath = 'storage/' . $finalPath;

        UserDetail::updateOrCreate(
            ['user_id' => $this->userId],
            [
                'user_id' => $this->userId,
                $this->field => $dbPath,
            ]
        );

        if (Schema::hasColumn('users', 'kyc')) {
            $user->update([
                'kyc' => 1,
            ]);
        }

        $this->updateProgress(function (array $progress) use ($dbPath) {
            $progress['files'][$this->field]['status'] = 'completed';
            $progress['files'][$this->field]['percent'] = 100;
            $progress['files'][$this->field]['url'] = url($dbPath);
            $progress['files'][$this->field]['error'] = null;

            $completed = collect($progress['files'] ?? [])
                ->filter(fn ($file) => ($file['status'] ?? null) === 'completed')
                ->count();

            $failed = collect($progress['files'] ?? [])
                ->filter(fn ($file) => ($file['status'] ?? null) === 'failed')
                ->count();

            $total = max((int) ($progress['total_files'] ?? 3), 1);

            $progress['processed_files'] = $completed;
            $progress['failed_files'] = $failed;
            $progress['percent'] = min(100, (int) round(($completed / $total) * 100));

            if ($completed >= $total) {
                $progress['status'] = 'completed';
                $progress['percent'] = 100;
            } elseif ($failed > 0) {
                $progress['status'] = 'processing_with_errors';
            } else {
                $progress['status'] = 'processing';
            }

            $progress['updated_at'] = now()->toDateTimeString();

            return $progress;
        });
    }

    public function failed(Throwable $exception): void
    {
        $this->updateProgress(function (array $progress) use ($exception) {
            $progress['files'][$this->field]['status'] = 'failed';
            $progress['files'][$this->field]['percent'] = 0;
            $progress['files'][$this->field]['url'] = null;
            $progress['files'][$this->field]['error'] = $exception->getMessage();

            $completed = collect($progress['files'] ?? [])
                ->filter(fn ($file) => ($file['status'] ?? null) === 'completed')
                ->count();

            $failed = collect($progress['files'] ?? [])
                ->filter(fn ($file) => ($file['status'] ?? null) === 'failed')
                ->count();

            $total = max((int) ($progress['total_files'] ?? 3), 1);

            $progress['processed_files'] = $completed;
            $progress['failed_files'] = $failed;
            $progress['percent'] = min(100, (int) round(($completed / $total) * 100));
            $progress['status'] = 'failed';
            $progress['updated_at'] = now()->toDateTimeString();

            return $progress;
        });
    }

    private function progressKey(): string
    {
        return 'user_document_upload:' . $this->uploadId;
    }

    private function updateProgress(callable $callback): array
    {
        $key = $this->progressKey();
        $lockKey = $key . ':lock';

        return Cache::store('redis')->lock($lockKey, 10)->block(5, function () use ($key, $callback) {
            $progress = Cache::store('redis')->get($key, []);

            $progress = $callback($progress);

            Cache::store('redis')->put($key, $progress, now()->addHours(2));

            return $progress;
        });
    }
}