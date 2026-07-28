<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Resources\Membership\MembershipAuditLogResource;
use App\Models\Membership\MembershipAuditLog;
use App\Services\Membership\MembershipAuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminMembershipAuditLogController extends Controller
{
    public function index(
        Request $request,
        MembershipAuditLogService $auditLogService
    ): JsonResponse {
        try {
            $logs = $auditLogService->adminLogs($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership audit logs fetched successfully.',
                'data' => MembershipAuditLogResource::collection($logs),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership audit logs.', $e);
        }
    }

    public function show(
        MembershipAuditLog $auditLog,
        MembershipAuditLogService $auditLogService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership audit log fetched successfully.',
                'data' => new MembershipAuditLogResource(
                    $auditLogService->detail($auditLog)
                ),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership audit log.', $e);
        }
    }

    private function serverError(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}