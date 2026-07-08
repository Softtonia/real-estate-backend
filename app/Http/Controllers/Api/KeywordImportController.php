<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportKeywordsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class KeywordImportController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'file_key' => ['nullable', 'string', 'max:150'],
        ]);

        $extension = strtolower($request->file('file')->getClientOriginalExtension());

        if ($extension !== 'csv') {
            return response()->json([
                'status' => false,
                'message' => 'Only CSV file import is allowed.',
            ], 422);
        }

        $batchId = (string) Str::uuid();
        $fileKey = $request->input('file_key', 'default');

        $storedPath = $request->file('file')->storeAs(
            'keyword-imports',
            $batchId . '.csv',
            'local'
        );

        Cache::store('redis')->put($this->progressKey($batchId), [
            'batch_id' => $batchId,
            'status' => 'queued',
            'file_key' => $fileKey,
            'total' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'percent' => 0,
            'errors' => [],
        ], now()->addDay());

        ImportKeywordsJob::dispatch(
            batchId: $batchId,
            storedPath: $storedPath,
            originalFileName: $request->file('file')->getClientOriginalName(),
            fileKey: $fileKey,
            userId: auth()->id()
        );

        return response()->json([
            'status' => true,
            'message' => 'Keyword CSV import started successfully.',
            'data' => [
                'batch_id' => $batchId,
                'file_key' => $fileKey,
                'progress_url' => url('/api/keywords/import-progress/' . $batchId),
            ],
        ], 202);
    }

    public function progress(string $batchId): JsonResponse
    {
        $progress = Cache::store('redis')->get($this->progressKey($batchId));

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

    private function progressKey(string $batchId): string
    {
        return 'keyword_import:' . $batchId;
    }
}