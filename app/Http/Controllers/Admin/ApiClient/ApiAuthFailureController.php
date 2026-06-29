<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiAuthFailureResource;
use App\Models\ApiAuthFailure;
use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApiAuthFailureController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ApiClient::class);

        $query = ApiAuthFailure::query()
            ->latest('created_at');

        if ($request->filled('reason')) {
            $query->where('reason', $request->reason);
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', 'like', '%' . $request->ip . '%');
        }

        if ($request->filled('api_client_id')) {
            $query->where('api_client_id', $request->api_client_id);
        }

        if ($request->filled('token_prefix')) {
            $query->where('token_prefix', 'like', '%' . $request->token_prefix . '%');
        }

        if ($request->filled('origin')) {
            $query->where('origin', 'like', '%' . $request->origin . '%');
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        return ApiAuthFailureResource::collection(
            $query->paginate((int) $request->get('per_page', 50))
        );
    }

    public function reasons(Request $request)
    {
        Gate::authorize('viewAny', ApiClient::class);

        $reasons = ApiAuthFailure::query()
            ->select('reason')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reasons,
        ]);
    }

    public function topIps(Request $request)
    {
        Gate::authorize('viewAny', ApiClient::class);

        $limit = (int) $request->get('limit', 20);

        $ips = ApiAuthFailure::query()
            ->select('ip_address')
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull('ip_address')
            ->when($request->filled('from_date'), function ($query) use ($request) {
                $query->whereDate('created_at', '>=', $request->from_date);
            })
            ->when($request->filled('to_date'), function ($query) use ($request) {
                $query->whereDate('created_at', '<=', $request->to_date);
            })
            ->groupBy('ip_address')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $ips,
        ]);
    }
}