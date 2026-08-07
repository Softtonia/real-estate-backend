<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\MembershipReportRequest;
use App\Services\Membership\MembershipReportService;
use Illuminate\Http\JsonResponse;
use Throwable;

class AdminMembershipReportController extends Controller
{
    public function dashboard(
        MembershipReportRequest $request,
        MembershipReportService $reportService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership dashboard report fetched successfully.',
                'data' => $reportService->dashboard($request->validated()),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership dashboard report.', $e);
        }
    }

    public function revenue(
        MembershipReportRequest $request,
        MembershipReportService $reportService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership revenue report fetched successfully.',
                'data' => [
                    'period' => [
                        'date_from' => $request->validated()['date_from'] ?? now()->subDays(30)->toDateString(),
                        'date_to' => $request->validated()['date_to'] ?? now()->toDateString(),
                        'group_by' => $request->validated()['group_by'] ?? 'day',
                    ],
                    'summary' => $reportService->summary($request->validated()),
                    'trend' => $reportService->revenueTrend($request->validated()),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership revenue report.', $e);
        }
    }

    public function credits(
        MembershipReportRequest $request,
        MembershipReportService $reportService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership credit usage report fetched successfully.',
                'data' => $reportService->creditUsage($request->validated()),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership credit report.', $e);
        }
    }

    public function topPlans(
        MembershipReportRequest $request,
        MembershipReportService $reportService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Top membership plans report fetched successfully.',
                'data' => $reportService->topPlans($request->validated()),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch top membership plans report.', $e);
        }
    }

    public function topAddons(
        MembershipReportRequest $request,
        MembershipReportService $reportService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Top membership add-ons report fetched successfully.',
                'data' => $reportService->topAddons($request->validated()),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch top membership add-ons report.', $e);
        }
    }


    private function serverError(string $message, Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}
