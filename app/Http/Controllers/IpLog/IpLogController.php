<?php

namespace App\Http\Controllers\IpLog;

use App\Http\Controllers\Controller;
use App\Models\UserIpLog;
use Illuminate\Http\Request;

class IpLogController extends Controller
{
     public function index()
    {
        return UserIpLog::all();
    }

    public function unblock(Request $request)
    {
        $id = $request->input('id');
        $ip = UserIpLog::findOrFail($id);
        $ip->status = 'active';
        $ip->save();

        return response()->json(['message' => 'IP unblocked successfully.']);
    }

    public function block(Request $request)
    {
        $id = $request->input('id');
        $ip = UserIpLog::findOrFail($id);
        $ip->status = 'blocked';
        $ip->save();

        return response()->json(['message' => 'IP blocked successfully.']);
    }
}
