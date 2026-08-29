<?php

namespace App\Http\Controllers\IpLog;

use App\Http\Controllers\Controller;
use App\Models\UserIpLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Throwable;

class IpLogController extends Controller
{
    protected int $cacheTTL = 300; // 5 minutes TTL

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $currentPage = (int) $request->get('page', 1);
        $userId = $request->input('user_id');
        $fromDate = $request->input('from_date') ?? $request->input('start_date') ?? $request->input('from');
        $toDate = $request->input('to_date') ?? $request->input('end_date') ?? $request->input('to');
        $search = $request->input('search');

        $query = UserIpLog::query()
            ->with(['user:id,user_name,first_name,last_name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($userId)) {
            $query->where('user_id', $userId);
        }

        if (!empty($fromDate)) {
            try {
                $query->whereDate('created_at', '>=', Carbon::parse($fromDate)->toDateString());
            } catch (Throwable) {}
        }

        if (!empty($toDate)) {
            try {
                $query->whereDate('created_at', '<=', Carbon::parse($toDate)->toDateString());
            } catch (Throwable) {}
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%")
                    ->orWhere('device', 'like', "%{$search}%")
                    ->orWhere('browser', 'like', "%{$search}%")
                    ->orWhere('os', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $currentPage);

        return response()->json($paginator);
    }

    public function updateIpStatus(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $action = $request->input('status');

        $ip = UserIpLog::find($id);
        if (!$ip) {
            return response()->json(['error' => 'IP not found'], 404);
        }

        $ip->status = $action === 'block' ? 'blocked' : 'active';
        $ip->blocked_at = $ip->status === 'blocked' ? now() : null;
        $ip->blocked_reason = $ip->status === 'blocked' ? ($request->input('reason') ?? 'Blocked by admin') : null;
        $ip->save();

        $this->flushIpLogsCache();

        return response()->json([
            'status' => true,
            'message' => "IP {$ip->status} successfully.",
            'data' => $ip,
        ]);
    }

    public function getByIpAddress(Request $request): JsonResponse
    {
        $ip = $request->input('ip');

        $iplogs = UserIpLog::where('ip_address', $ip)
            ->with(['user:id,user_name,first_name,last_name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($iplogs);
    }

    public function getByUserId(Request $request, ?int $routeUserId = null): JsonResponse
    {
        $userId = $routeUserId ?? $request->input('user_id') ?? $request->input('id');

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'User ID is required.',
                'data' => [],
            ], 422);
        }

        $fromDate = $request->input('from_date') ?? $request->input('start_date') ?? $request->input('from');
        $toDate = $request->input('to_date') ?? $request->input('end_date') ?? $request->input('to');
        $search = $request->input('search');

        $query = UserIpLog::query()
            ->where('user_id', $userId)
            ->with(['user:id,user_name,first_name,last_name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($fromDate)) {
            try {
                $query->whereDate('created_at', '>=', Carbon::parse($fromDate)->toDateString());
            } catch (Throwable) {}
        }

        if (!empty($toDate)) {
            try {
                $query->whereDate('created_at', '<=', Carbon::parse($toDate)->toDateString());
            } catch (Throwable) {}
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%")
                    ->orWhere('device', 'like', "%{$search}%")
                    ->orWhere('browser', 'like', "%{$search}%")
                    ->orWhere('os', 'like', "%{$search}%")
                    ->orWhere('login_method', 'like', "%{$search}%");
            });
        }

        if ($request->has('per_page') || $request->has('page')) {
            $perPage = (int) $request->get('per_page', 10);
            $paginator = $query->paginate($perPage);
            return response()->json($paginator);
        }

        $iplogs = $query->get();

        return response()->json($iplogs);
    }

    public function getById(Request $request): JsonResponse
    {
        $id = $request->input('id');

        $iplog = UserIpLog::with(['user:id,user_name,first_name,last_name,email'])->find($id);

        if (!$iplog) {
            return response()->json(['error' => 'IP log not found.'], 404);
        }

        return response()->json($iplog);
    }

    public function updateStatusByIp(Request $request): JsonResponse
    {
        $ipAddress = $request->input('ip');
        $action = $request->input('status');

        $ipLogs = UserIpLog::where('ip_address', $ipAddress)->get();
        if ($ipLogs->isEmpty()) {
            return response()->json(['error' => 'IP not found.'], 404);
        }

        $status = $action === 'block' ? 'blocked' : 'active';
        $blockedAt = $status === 'blocked' ? now() : null;
        $reason = $status === 'blocked' ? ($request->input('reason') ?? 'Blocked by admin') : null;

        UserIpLog::where('ip_address', $ipAddress)->update([
            'status' => $status,
            'blocked_at' => $blockedAt,
            'blocked_reason' => $reason,
        ]);

        $this->flushIpLogsCache();

        return response()->json(['message' => "IP {$status} successfully."]);
    }

    private function flushIpLogsCache(): void
    {
        try {
            Cache::flush();
        } catch (Throwable) {}

        try {
            Cache::store('redis')->flush();
        } catch (Throwable) {}
    }
}