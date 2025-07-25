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




    public function unblock(Request $request)
    {
        $id = $request->input('id');
        $ip = UserIpLog::findOrFail($id);

        if(!$ip){
            return response()->json(['error' => 'Ip not found'], 200);
        }

        $ip->status = 'active';
        $ip->save();

        return response()->json(['message' => 'IP unblocked successfully.']);
    }

    public function block(Request $request)
    {
        $id = $request->input('id');
        $ip = UserIpLog::findOrFail($id);
        if(!$ip){
            return response()->json(['error' => 'Ip not found'], 200);
        }
        $ip->status = 'blocked';
        $ip->save();

        return response()->json(['message' => 'IP blocked successfully.']);
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


}
