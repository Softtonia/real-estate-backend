<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportKeywordsCsvJob;
use App\Services\KeywordCsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class KeywordImportController extends Controller
{
    public function __construct(
        protected KeywordCsvImportService $keywordCsvImportService
    ) {
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'file_key' => ['nullable', 'string', 'max:150'],
        ]);

        $extension = strtolower($request->file('file')->getClientOriginalExtension());

        if ($extension !== 'csv') {
            return response()->json([
                'status' => false,
                'message' => 'Only CSV file is allowed.',
            ], 422);
        }

        try {
            $uploadId = (string) Str::uuid();
            $fileKey = $request->input('file_key', 'default');

            $storedPath = $request->file('file')->storeAs(
                'keyword-imports/uploads',
                $uploadId . '.csv',
                'local'
            );

            $fullPath = Storage::disk('local')->path($storedPath);

            $preview = $this->keywordCsvImportService->readPreviewRows($fullPath, 10);

            $uploadData = [
                'upload_id' => $uploadId,
                'stored_path' => $storedPath,
                'original_file_name' => $request->file('file')->getClientOriginalName(),
                'file_key' => $fileKey,
                'headers' => $preview['headers'],
                'mapping' => $preview['detected_mapping'],
                'validation' => null,
            ];

            $this->cache()->put($this->uploadKey($uploadId), $uploadData, now()->addDay());

            return response()->json([
                'status' => true,
                'message' => 'CSV uploaded successfully.',
                'data' => [
                    'upload_id' => $uploadId,
                    'file_key' => $fileKey,
                    'headers' => $preview['headers'],
                    'preview_rows' => $preview['preview_rows'],
                    'detected_mapping' => $preview['detected_mapping'],
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to upload CSV.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function map(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
            'mapping' => ['required', 'array'],
        ]);

        $upload = $this->getUpload($validated['upload_id']);

        if (! $upload) {
            return response()->json([
                'status' => false,
                'message' => 'Upload not found or expired.',
            ], 404);
        }

        $errors = $this->keywordCsvImportService->validateMapping($validated['mapping']);

        if (! empty($errors)) {
            return response()->json([
                'status' => false,
                'message' => 'Column mapping is invalid.',
                'errors' => $errors,
            ], 422);
        }

        $upload['mapping'] = $validated['mapping'];
        $upload['validation'] = null;

        $this->cache()->put($this->uploadKey($validated['upload_id']), $upload, now()->addDay());

        return response()->json([
            'status' => true,
            'message' => 'Columns mapped successfully.',
            'data' => [
                'upload_id' => $validated['upload_id'],
                'mapping' => $upload['mapping'],
            ],
        ]);
    }

    public function validateImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        $upload = $this->getUpload($validated['upload_id']);

        if (! $upload) {
            return response()->json([
                'status' => false,
                'message' => 'Upload not found or expired.',
            ], 404);
        }

        try {
            $fullPath = Storage::disk('local')->path($upload['stored_path']);

            $result = $this->keywordCsvImportService->validateUpload(
                fullPath: $fullPath,
                mapping: $upload['mapping'] ?? [],
                fileKey: $upload['file_key'] ?? 'default'
            );

            $upload['validation'] = $result;

            $this->cache()->put($this->uploadKey($validated['upload_id']), $upload, now()->addDay());

            return response()->json([
                'status' => true,
                'message' => 'CSV validated successfully.',
                'data' => array_merge([
                    'upload_id' => $validated['upload_id'],
                ], $result),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to validate CSV.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
            'ignore_invalid' => ['nullable', 'boolean'],
        ]);

        $upload = $this->getUpload($validated['upload_id']);

        if (! $upload) {
            return response()->json([
                'status' => false,
                'message' => 'Upload not found or expired.',
            ], 404);
        }

        $validation = $upload['validation'] ?? null;

        if (! $validation) {
            $fullPath = Storage::disk('local')->path($upload['stored_path']);

            $validation = $this->keywordCsvImportService->validateUpload(
                fullPath: $fullPath,
                mapping: $upload['mapping'] ?? [],
                fileKey: $upload['file_key'] ?? 'default'
            );

            $upload['validation'] = $validation;

            $this->cache()->put($this->uploadKey($validated['upload_id']), $upload, now()->addDay());
        }

        if (($validation['invalid_rows'] ?? 0) > 0 && ! $request->boolean('ignore_invalid')) {
            return response()->json([
                'status' => false,
                'message' => 'CSV has invalid rows. Please fix errors before import.',
                'data' => $validation,
            ], 422);
        }

        $batchId = (string) Str::uuid();

        $this->cache()->put($this->progressKey($batchId), [
            'batch_id' => $batchId,
            'upload_id' => $validated['upload_id'],
            'status' => 'queued',
            'file_key' => $upload['file_key'] ?? 'default',
            'total' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'percent' => 0,
            'errors' => [],
        ], now()->addDay());

        ImportKeywordsCsvJob::dispatch(
            batchId: $batchId,
            uploadId: $validated['upload_id'],
            storedPath: $upload['stored_path'],
            mapping: $upload['mapping'] ?? [],
            fileKey: $upload['file_key'] ?? 'default',
            ignoreInvalid: $request->boolean('ignore_invalid'),
            userId: auth()->id()
        );

        return response()->json([
            'status' => true,
            'message' => 'Keyword import started successfully.',
            'data' => [
                'batch_id' => $batchId,
                'upload_id' => $validated['upload_id'],
                'progress_url' => url('/api/keywords/import-progress/' . $batchId),
            ],
        ], 202);
    }

    public function progress(string $batchId): JsonResponse
    {
        $progress = $this->cache()->get($this->progressKey($batchId));

        if (! $progress) {
            return response()->json([
                'status' => false,
                'message' => 'Import batch not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Import progress fetched successfully.',
            'data' => $progress,
        ]);
    }

    private function getUpload(string $uploadId): ?array
    {
        $upload = $this->cache()->get($this->uploadKey($uploadId));

        return is_array($upload) ? $upload : null;
    }

    private function cache()
    {
        return Cache::store('redis');
    }

    private function uploadKey(string $uploadId): string
    {
        return 'keyword_import_upload:' . $uploadId;
    }

    private function progressKey(string $batchId): string
    {
        return 'keyword_import:' . $batchId;
    }
}