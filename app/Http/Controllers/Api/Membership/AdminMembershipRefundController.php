<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\MembershipRefundRequest;
use App\Models\Membership\MembershipRefund;
use App\Models\User;
use App\Services\Membership\MembershipRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminMembershipRefundController extends Controller
{
    public function index(
        Request $request,
        MembershipRefundService $refundService
    ): JsonResponse {
        try {
            $refunds = $refundService->adminRefunds($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership refunds fetched successfully.',
                'data' => $refunds,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership refunds.', $e);
        }
    }

    public function show(
        MembershipRefund $refund,
        MembershipRefundService $refundService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership refund fetched successfully.',
                'data' => $refundService->refundDetail($refund),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership refund.', $e);
        }
    }

    public function store(
        MembershipRefundRequest $request,
        MembershipRefundService $refundService
    ): JsonResponse {
        try {
            $refund = $refundService->createManualRefund(
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership refund created successfully.',
                'data' => $refund,
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership refund.', $e);
        }
    }

    public function markProcessed(
        Request $request,
        MembershipRefund $refund,
        MembershipRefundService $refundService
    ): JsonResponse {
        try {
            $refund = $refundService->markProcessed(
                refund: $refund,
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership refund marked as processed successfully.',
                'data' => $refund,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to mark refund as processed.', $e);
        }
    }

    public function markFailed(
        Request $request,
        MembershipRefund $refund,
        MembershipRefundService $refundService
    ): JsonResponse {
        try {
            $request->validate([
                'reason' => ['required', 'string', 'max:1000'],
            ]);

            $refund = $refundService->markFailed(
                refund: $refund,
                reason: $request->input('reason'),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership refund marked as failed successfully.',
                'data' => $refund,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to mark refund as failed.', $e);
        }
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if ($token && Schema::hasColumn('users', 'api_token')) {
            $user = User::query()
                ->where('api_token', $token)
                ->first();

            if ($user) {
                return $user;
            }
        }

        $authUser = $request->user() ?: Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => $e->errors(),
        ], 422);
    }

    private function serverError(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}