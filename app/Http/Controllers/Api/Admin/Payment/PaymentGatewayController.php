<?php

namespace App\Http\Controllers\Api\Admin\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Payment\RazorpayConfigRequest;
use App\Models\User;
use App\Services\Payment\PaymentGatewayConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class PaymentGatewayController extends Controller
{
    public function razorpay(PaymentGatewayConfigService $service): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Razorpay config fetched successfully.',
            'data' => $service->razorpayConfig(masked: true),
        ]);
    }

    public function updateRazorpay(
        RazorpayConfigRequest $request,
        PaymentGatewayConfigService $service
    ): JsonResponse {
        try {
            $config = $service->updateRazorpayConfig(
                $request->validated(),
                $this->currentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Razorpay config updated successfully.',
                'data' => $config,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to update Razorpay config.',
                'error' => 'Server error',
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

        return Auth::user();
    }
}