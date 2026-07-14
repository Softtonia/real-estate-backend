<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DynamicPostCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DynamicPostCsvController extends Controller
{
    public function __construct(
        protected DynamicPostCsvService $csvService
    ) {}

    public function template(): StreamedResponse
    {
        return $this->csvService->downloadTemplate();
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->csvService->export($request);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        try {
            $result = $this->csvService->import($request->file('file'));

            return response()->json([
                'status' => true,
                'message' => 'Dynamic posts imported successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Dynamic post import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}