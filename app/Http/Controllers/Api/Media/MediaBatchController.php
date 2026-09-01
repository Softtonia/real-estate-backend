<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Media;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaBatchController extends Controller
{
    public function __construct(
        protected MediaBatchService $mediaBatchService
    ) {
    }

    /**
     * Upload single or batch files.
     * POST /api/media/batch-upload
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $result = $this->mediaBatchService->handleBatchUpload($request, $request->user());

            return response()->json([
                'status' => true,
                'message' => 'Media uploaded successfully.',
                'data' => $result,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Media upload failed.',
                'errors' => [
                    'error' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get real-time status and progress of a batch.
     * GET /api/media/batch-status/{batch_uuid}
     */
    public function status(string $batchUuid): JsonResponse
    {
        try {
            $status = $this->mediaBatchService->getBatchStatus($batchUuid);

            if (!$status) {
                return response()->json([
                    'status' => false,
                    'message' => 'Upload batch not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Batch status fetched successfully.',
                'data' => $status,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch batch status.',
                'errors' => [
                    'error' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
