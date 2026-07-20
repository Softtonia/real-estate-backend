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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessUserDocumentUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
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
        $newPath = null;

        try {
            $user = User::find($this->userId);

            if (!$user) {
                throw new \Exception('User not found.');
            }

            $allowedFields = $this->documentUploadFieldsForUser($user);

            if (!array_key_exists($this->field, $allowedFields)) {
                throw new \Exception('Document field is not allowed for this user.');
            }

            if (!Schema::hasTable('user_details') || !Schema::hasColumn('user_details', $this->field)) {
                throw new \Exception('Column does not exist in user_details: ' . $this->field);
            }

            if (!Storage::disk('document_temp')->exists($this->tempPath)) {
                throw new \Exception('Temporary uploaded file not found.');
            }

            $this->updateProgress(function (array $progress) {
                $progress = $this->ensureProgressStructure($progress);

                $progress['files'][$this->field]['status'] = 'processing';
                $progress['files'][$this->field]['percent'] = 50;
                $progress['files'][$this->field]['error'] = null;
                $progress['updated_at'] = now()->toDateTimeString();

                return $this->syncProgressCounters($progress);
            });

            $folders = [
                'aadhaar_front' => 'kyc/aadhaarFront',
                'aadhaar_back' => 'kyc/aadhaarBack',
                'business_proof' => 'kyc/businessProof',
            ];

            $folder = $folders[$this->field];

            $extension = strtolower($this->extension ?: 'bin');

            $fileName = 'u'
                . $this->userId
                . '_'
                . now()->format('YmdHis')
                . '_'
                . $this->field
                . '_'
                . Str::random(8)
                . '.'
                . $extension;

            $relativePath = $folder . '/' . $fileName;

            $contents = Storage::disk('document_temp')->get($this->tempPath);

            Storage::disk('public_uploads')->put($relativePath, $contents);

            if (!Storage::disk('public_uploads')->exists($relativePath)) {
                throw new \Exception('Final document could not be saved.');
            }

            $newPath = 'uploads/' . $relativePath;

            $oldPath = null;

            DB::transaction(function () use ($user, $newPath, &$oldPath) {
                $detail = UserDetail::query()
                    ->where('user_id', $this->userId)
                    ->first();

                $oldPath = $detail?->{$this->field};

                UserDetail::updateOrCreate(
                    ['user_id' => $this->userId],
                    [
                        'user_id' => $this->userId,
                        $this->field => $newPath,
                    ]
                );

                if (Schema::hasColumn('users', 'kyc')) {
                    $user->update([
                        'kyc' => 1,
                    ]);
                }
            });

            Storage::disk('document_temp')->delete($this->tempPath);

            $this->deletePublicUpload($oldPath);

            $this->updateProgress(function (array $progress) use ($newPath) {
                $progress = $this->ensureProgressStructure($progress);

                $progress['files'][$this->field]['status'] = 'completed';
                $progress['files'][$this->field]['percent'] = 100;
                $progress['files'][$this->field]['url'] = $this->fileUrl($newPath);
                $progress['files'][$this->field]['error'] = null;
                $progress['updated_at'] = now()->toDateTimeString();

                return $this->syncProgressCounters($progress);
            });
        } catch (Throwable $e) {
            if ($newPath) {
                $this->deletePublicUpload($newPath);
            }

            Storage::disk('document_temp')->delete($this->tempPath);

            $this->updateProgress(function (array $progress) use ($e) {
                $progress = $this->ensureProgressStructure($progress);

                $progress['files'][$this->field]['status'] = 'failed';
                $progress['files'][$this->field]['percent'] = 0;
                $progress['files'][$this->field]['url'] = null;
                $progress['files'][$this->field]['error'] = $e->getMessage();
                $progress['updated_at'] = now()->toDateTimeString();

                return $this->syncProgressCounters($progress);
            });

            report($e);
        }
    }

    private function documentUploadFieldsForUser(User $user): array
    {
        $fields = [
            'aadhaar_front' => 'Aadhaar Front',
            'aadhaar_back' => 'Aadhaar Back',
            'business_proof' => 'Business Proof',
        ];

        if ($this->isOwnerUser($user)) {
            unset($fields['business_proof']);
        }

        return $fields;
    }

    private function isOwnerUser(User $user): bool
    {
        $directRole = strtolower(trim((string) ($user->role_id ?? '')));
        $directRole = str_replace([' ', '_', '-'], '', $directRole);

        if (in_array($directRole, ['owner', 'propertyowner', 'landowner'], true)) {
            return true;
        }

        if (!Schema::hasTable('roles') || empty($user->role_id)) {
            return false;
        }

        $role = DB::table('roles')
            ->where('id', $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleText = strtolower(trim((string) (
            $role->slug
            ?? $role->name
            ?? $role->role_name
            ?? ''
        )));

        $roleText = str_replace([' ', '_', '-'], '', $roleText);

        return in_array($roleText, ['owner', 'propertyowner', 'landowner'], true);
    }

    private function fileUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $path = trim($path);
        $path = str_replace('\\/', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/uploads/')) {
            $path = str_replace('storage/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'public/uploads/')) {
            $path = str_replace('public/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'uploads/')) {
            $relativePath = substr($path, strlen('uploads/'));

            return Storage::disk('public_uploads')->url($relativePath);
        }

        return url($path);
    }

    private function deletePublicUpload(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        $path = trim($path);
        $path = str_replace('\\/', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $path = ltrim((string) $parsedPath, '/');
        }

        if (str_starts_with($path, 'storage/uploads/')) {
            $path = str_replace('storage/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'public/uploads/')) {
            $path = str_replace('public/uploads/', 'uploads/', $path);
        }

        if (!str_starts_with($path, 'uploads/')) {
            return;
        }

        $relativePath = substr($path, strlen('uploads/'));

        if (!empty($relativePath)) {
            Storage::disk('public_uploads')->delete($relativePath);
        }
    }

    private function cacheStore()
    {
        try {
            return Cache::store(env('DOCUMENT_UPLOAD_CACHE_STORE', 'redis'));
        } catch (Throwable $e) {
            return Cache::store(config('cache.default'));
        }
    }

    private function progressKey(): string
    {
        return 'user_document_upload:' . $this->uploadId;
    }

    private function updateProgress(callable $callback): void
    {
        $store = $this->cacheStore();
        $key = $this->progressKey();

        $progress = $store->get($key, []);
        $progress = $callback(is_array($progress) ? $progress : []);

        $store->put($key, $progress, now()->addHours(2));
    }

    private function ensureProgressStructure(array $progress): array
    {
        $progress['upload_id'] = $progress['upload_id'] ?? $this->uploadId;
        $progress['user_id'] = $progress['user_id'] ?? $this->userId;
        $progress['status'] = $progress['status'] ?? 'started';
        $progress['total_files'] = max(1, (int) ($progress['total_files'] ?? 1));
        $progress['files'] = $progress['files'] ?? [];

        $progress['files'][$this->field] = $progress['files'][$this->field] ?? [
            'status' => 'pending',
            'percent' => 0,
            'url' => null,
            'error' => null,
        ];

        return $progress;
    }

    private function syncProgressCounters(array $progress): array
    {
        $files = $progress['files'] ?? [];

        $queued = 0;
        $processed = 0;
        $failed = 0;

        foreach ($files as $file) {
            $status = $file['status'] ?? 'pending';

            if (in_array($status, ['queued', 'uploading', 'processing'], true)) {
                $queued++;
            }

            if ($status === 'completed') {
                $processed++;
            }

            if ($status === 'failed') {
                $failed++;
            }
        }

        $total = max(1, (int) ($progress['total_files'] ?? count($files) ?: 1));
        $done = min($total, $processed + $failed);

        $progress['queued_files'] = $queued;
        $progress['processed_files'] = $processed;
        $progress['failed_files'] = $failed;
        $progress['percent'] = min(100, (int) round(($done / $total) * 100));

        if ($done >= $total) {
            $progress['status'] = $failed > 0 ? 'completed_with_errors' : 'completed';
        } elseif ($queued > 0) {
            $progress['status'] = 'processing';
        } else {
            $progress['status'] = 'started';
        }

        return $progress;
    }
}