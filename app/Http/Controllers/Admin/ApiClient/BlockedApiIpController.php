<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManualBlockApiIpRequest;
use App\Http\Resources\BlockedApiIpResource;
use App\Models\ApiClient;
use App\Models\BlockedApiIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BlockedApiIpController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ApiClient::class);

        $query = BlockedApiIp::query()
            ->latest();

        if ($request->filled('active')) {
            if ($request->boolean('active')) {
                $query->where(function ($q) {
                    $q->where('permanent', true)
                        ->orWhere('blocked_until', '>', now());
                });
            }
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', 'like', '%' . $request->ip . '%');
        }

        return BlockedApiIpResource::collection(
            $query->paginate((int) $request->get('per_page', 20))
        );
    }

    public function store(ManualBlockApiIpRequest $request): JsonResponse
    {
        Gate::authorize('create', ApiClient::class);

        $data = $request->validated();

        $blockedIp = BlockedApiIp::updateOrCreate(
            [
                'ip_address' => $data['ip_address'],
            ],
            [
                'reason' => $data['reason'] ?? 'Manually blocked by admin.',
                'permanent' => (bool) ($data['permanent'] ?? false),
                'blocked_until' => ($data['permanent'] ?? false)
                    ? null
                    : ($data['blocked_until'] ?? now()->addHours(24)),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'IP blocked successfully.',
            'data' => new BlockedApiIpResource($blockedIp),
        ], 201);
    }

    public function destroy(BlockedApiIp $blockedApiIp): JsonResponse
    {
        Gate::authorize('delete', ApiClient::class);

        $blockedApiIp->delete();

        return response()->json([
            'success' => true,
            'message' => 'IP unblocked successfully.',
        ]);
    }
}