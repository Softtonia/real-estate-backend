<?php

namespace App\Http\Controllers\IpLog;

use App\Http\Controllers\Controller;
use App\Models\UserIpLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class IpLogController extends Controller
{
    protected $cacheTTL = 300; // 5 minutes TTL

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $currentPage = (int) $request->get('page', 1);
        $cacheKey = "iplogs_page_{$currentPage}_perpage_{$perPage}";

        $paginator = Cache::store('redis')->remember($cacheKey, $this->cacheTTL, function () use ($perPage, $currentPage) {
            // Use groupBy to avoid only_full_group_by SQL error
            $iplogs = UserIpLog::select('id','user_id','ip_address','country','city','region','country_code','region_code','lat','lon','timezone','isp','org','as','query','status','blocked_at','blocked_reason','created_at','updated_at')
                ->with(['user:id,user_name,first_name,last_name,email'])
                ->groupBy('user_id', 'ip_address', 'id','country','city','region','country_code','region_code','lat','lon','timezone','isp','org','as','status','blocked_at','blocked_reason','created_at','updated_at')
                ->orderByDesc('id')
                ->get();

            $currentPageItems = $iplogs->forPage($currentPage, $perPage);

            return new LengthAwarePaginator(
                $currentPageItems,
                $iplogs->count(),
                $perPage,
                $currentPage,
                ['path' => url()->current(), 'query' => request()->query()]
            );
        });

        return response()->json($paginator);
    }

    public function updateIpStatus(Request $request)
    {
        $id = $request->input('id');
        $action = $request->input('status');

        $ip = UserIpLog::find($id);
        if (!$ip) return response()->json(['error' => 'IP not found'], 200);

        $ip->status = $action === 'block' ? 'blocked' : 'active';
        $ip->save();

        // Invalidate cache
        Cache::store('redis')->flush();

        return response()->json(['message' => "IP {$ip->status} successfully."]);
    }

    public function getByIpAddress(Request $request)
    {
        $ip = $request->input('ip');
        $cacheKey = "iplogs_ip_{$ip}";

        $iplogs = Cache::store('redis')->remember($cacheKey, $this->cacheTTL, function () use ($ip) {
            return UserIpLog::where('ip_address', $ip)
                ->with(['user:id,user_name,first_name,last_name,email'])
                ->orderByDesc('id')
                ->get();
        });

        return response()->json($iplogs);
    }

    public function getByUserId(Request $request)
    {
        $userId = $request->input('user_id');
        $cacheKey = "iplogs_user_{$userId}";

        $iplogs = Cache::store('redis')->remember($cacheKey, $this->cacheTTL, function () use ($userId) {
            return UserIpLog::where('user_id', $userId)
                ->with(['user:id,user_name,first_name,last_name,email'])
                ->orderByDesc('id')
                ->get();
        });

        return response()->json($iplogs);
    }

    public function getById(Request $request)
    {
        $id = $request->input('id');
        $cacheKey = "iplogs_id_{$id}";

        $iplog = Cache::store('redis')->remember($cacheKey, $this->cacheTTL, function () use ($id) {
            return UserIpLog::with(['user:id,user_name,first_name,last_name,email'])->find($id);
        });

        if (!$iplog) return response()->json(['error' => 'IP log not found.'], 200);

        return response()->json($iplog);
    }

    public function updateStatusByIp(Request $request)
    {
        $ipAddress = $request->input('ip');
        $action = $request->input('status');

        $ipLog = UserIpLog::where('ip_address', $ipAddress)->first();
        if (!$ipLog) return response()->json(['error' => 'IP not found.'], 200);

        $ipLog->status = $action === 'block' ? 'blocked' : 'active';
        $ipLog->save();

        // Invalidate cache
        Cache::store('redis')->flush();

        return response()->json(['message' => "IP {$ipLog->status} successfully."]);
    }
}