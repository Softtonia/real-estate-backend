<?php

namespace App\Http\Controllers\Api\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaUploadBatch;
use App\Services\Media\MediaBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaBatchController extends Controller
{
    public function __construct(private MediaBatchService $batchService)
    {
    }

    public function init(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'custom_field_id' => ['nullable', 'integer', 'exists:custom_fields,id'],
            'field_slug' => ['nullable', 'string', 'max:100'],
            'expected_count' => ['required', 'integer', 'min:1', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $batch = $this->batchService->initBatch($validated, Auth::id());

            return response()->json([
                'status' => true,
                'message' => 'Media batch upload session initialized.',
                'data' => [
                    'batch_uuid' => $batch->batch_uuid,
                    'expected_count' => (int) $batch->expected_count,
                    'field_slug' => $batch->field_slug,
                    'status' => $batch->status,
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Could not initialize batch upload.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function upload(Request $request, string $batchUuid): JsonResponse
    {
        $batch = MediaUploadBatch::where('batch_uuid', $batchUuid)->first();

        if (!$batch) {
            return response()->json([
                'status' => false,
                'message' => 'Batch upload session not found.',
            ], 404);
        }

        if (in_array($batch->status, ['completed', 'abandoned'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Batch session is already ' . $batch->status . '.',
            ], 400);
        }

        $request->validate([
            'file' => ['required', 'file'],
            'client_file_id' => ['nullable', 'string', 'max:100'],
            'is_featured' => ['nullable'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        try {
            $file = $request->file('file');
            $clientFileId = $request->input('client_file_id');
            $isFeatured = filter_var($request->input('is_featured', false), FILTER_VALIDATE_BOOLEAN);
            $sortOrder = (int) $request->input('sort_order', 0);

            $result = $this->batchService->uploadFileToBatch(
                batch: $batch,
                file: $file,
                clientFileId: $clientFileId,
                isFeatured: $isFeatured,
                sortOrder: $sortOrder
            );

            return response()->json([
                'status' => true,
                'message' => $result['is_duplicate'] ? 'File already uploaded in this batch.' : 'File uploaded successfully.',
                'data' => [
                    'item_id' => (int) $result['item']->id,
                    'client_file_id' => $result['item']->client_file_id,
                    'media_file_id' => (int) $result['media_file']->id,
                    'url' => $result['media_file']->url,
                    'path' => $result['media_file']->path,
                    'file_name' => $result['media_file']->file_name,
                    'original_name' => $result['media_file']->original_name,
                    'is_featured' => (bool) $result['item']->is_featured,
                    'batch_progress' => $result['batch_progress'],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Upload failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status(string $batchUuid): JsonResponse
    {
        $batch = MediaUploadBatch::where('batch_uuid', $batchUuid)->first();

        if (!$batch) {
            return response()->json([
                'status' => false,
                'message' => 'Batch session not found.',
            ], 404);
        }

        $statusData = $this->batchService->getBatchStatus($batch);

        return response()->json([
            'status' => true,
            'data' => $statusData,
        ]);
    }

    public function complete(string $batchUuid): JsonResponse
    {
        $batch = MediaUploadBatch::where('batch_uuid', $batchUuid)->first();

        if (!$batch) {
            return response()->json([
                'status' => false,
                'message' => 'Batch session not found.',
            ], 404);
        }

        $batch->update(['status' => 'completed']);
        $statusData = $this->batchService->getBatchStatus($batch);

        return response()->json([
            'status' => true,
            'message' => 'Batch marked as completed.',
            'data' => $statusData,
        ]);
    }

    public function cancel(string $batchUuid): JsonResponse
    {
        $batch = MediaUploadBatch::where('batch_uuid', $batchUuid)->first();

        if (!$batch) {
            return response()->json([
                'status' => false,
                'message' => 'Batch session not found.',
            ], 404);
        }

        $this->batchService->cancelBatch($batch);

        return response()->json([
            'status' => true,
            'message' => 'Batch upload cancelled and temporary files removed.',
        ]);
    }
}
