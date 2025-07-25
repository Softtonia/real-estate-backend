<?php

namespace App\Http\Controllers\IpLog;

use App\Http\Controllers\Controller;
use App\Models\UserIpLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class IpLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $currentPage = $request->get('page', 1);

        // Get and filter unique IP logs
        $iplogs = UserIpLog::with(['user:id,user_name,first_name,last_name,email'])
            ->get()
            ->unique(fn($item) => $item->user_id . '_' . $item->ip_address)
            ->values(); // Reset keys after unique

        // Paginate manually
        $currentPageItems = $iplogs->forPage($currentPage, $perPage);
        $paginator = new LengthAwarePaginator(
            $currentPageItems,
            $iplogs->count(),
            $perPage,
            $currentPage,
            ['path' => url()->current(), 'query' => $request->query()]
        );

        return response()->json($paginator);
    }




    public function updateIpStatus(Request $request)
    {
        $id = $request->input('id');
        $action = $request->input(key: 'status'); // 'block' or 'unblock'

        $ip = UserIpLog::find($id);

        if (!$ip) {
            return response()->json(['error' => 'IP not found'], 200);
        }

        switch ($action) {
            case 'block':
                $ip->status = 'blocked';
                $message = 'IP blocked successfully.';
                break;
            case 'unblock':
                $ip->status = 'active';
                $message = 'IP unblocked successfully.';
                break;
            default:
                return response()->json(['error' => 'Invalid action. Use block or unblock.'], 422);
        }

        $ip->save();

        return response()->json(['message' => $message]);
    }


    public function getByIpAddress(Request $request)
    {
        $ip = $request->input('ip');
        $iplogs = UserIpLog::where('ip_address', $ip)
            ->with(['user:id,user_name,first_name,last_name,email'])
            ->get()
            ->unique(function ($item) {
                return $item->user_id . '_' . $item->ip_address;
            })
            ->values();

        return response()->json($iplogs);
    }

    public function getByUserId(Request $request)
    {
        $userId = $request->input('user_id');
        $iplogs = UserIpLog::where('user_id', $userId)
            ->with(['user:id,user_name,first_name,last_name,email'])
            ->get()
            ->unique(function ($item) {
                return $item->user_id . '_' . $item->ip_address;
            })
            ->values();

        return response()->json($iplogs);
    }


    public function getById(Request $request)
    {
        $id = $request->input('id');
        $iplog = UserIpLog::with(['user:id,user_name,first_name,last_name,email'])->find($id);

        if (!$iplog) {
            return response()->json(['error' => 'IP log not found.'], 200);
        }

        return response()->json($iplog);
    }

    public function updateStatusByIp(Request $request)
    {
        $ipAddress = $request->input('ip');
        $action = $request->input('status'); // 'block' or 'unblock'

        $ipLog = UserIpLog::where('ip_address', $ipAddress)->first();

        if (!$ipLog) {
            return response()->json(['error' => 'IP not found.'], 200);
        }

        switch ($action) {
            case 'block':
                $ipLog->status = 'blocked';
                $message = 'IP blocked successfully.';
                break;
            case 'unblock':
                $ipLog->status = 'active';
                $message = 'IP unblocked successfully.';
                break;
            default:
                return response()->json(['error' => 'Invalid action. Use block or unblock.'], 422);
        }

        $ipLog->save();

        return response()->json(['message' => $message]);
    }



}
