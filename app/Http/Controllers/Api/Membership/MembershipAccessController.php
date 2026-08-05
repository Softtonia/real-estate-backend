<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Services\Membership\MembershipAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MembershipAccessController extends Controller
{
    public function me(
        Request $request,
        MembershipAccessService $accessService
    ): JsonResponse {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated.',
                    'error' => 'User token is required.',
                ], 401);
            }

            return response()->json([
                'status' => true,
                'message' => 'Membership access fetched successfully.',
                'data' => $accessService->frontendAccess($user),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch membership access.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }
}