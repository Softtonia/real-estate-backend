<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\MembershipTaxSettingRequest;
use App\Models\User;
use App\Services\Membership\MembershipTaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AdminMembershipTaxSettingController extends Controller
{
    public function show(MembershipTaxService $service): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership tax settings fetched successfully.',
                'data' => $service->config(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch membership tax settings.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function update(
        MembershipTaxSettingRequest $request,
        MembershipTaxService $service
    ): JsonResponse {
        try {
            $config = $service->updateConfig(
                data: $request->validated(),
                admin: $this->currentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership tax settings updated successfully.',
                'data' => $config,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to update membership tax settings.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function calculatePreview(Request $request, MembershipTaxService $service): JsonResponse
    {
        try {
            $request->validate([
                'amount' => ['required', 'numeric', 'min:0'],
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Membership tax preview calculated successfully.',
                'data' => $service->calculate((float) $request->input('amount')),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to calculate membership tax preview.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    private function currentUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if ($token) {
            $user = User::query()
                ->where('api_token', $token)
                ->first();

            if ($user) {
                return $user;
            }
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}