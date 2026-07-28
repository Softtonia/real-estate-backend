<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\MembershipCouponRequest;
use App\Models\Membership\MembershipCoupon;
use App\Models\User;
use App\Services\Membership\MembershipCouponAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminMembershipCouponController extends Controller
{
    public function index(
        Request $request,
        MembershipCouponAdminService $couponService
    ): JsonResponse {
        try {
            $coupons = $couponService->paginatedCoupons($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership coupons fetched successfully.',
                'data' => $coupons,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership coupons.', $e);
        }
    }

    public function show(
        MembershipCoupon $coupon,
        MembershipCouponAdminService $couponService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership coupon fetched successfully.',
                'data' => $couponService->couponDetail($coupon),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership coupon.', $e);
        }
    }

    public function store(
        MembershipCouponRequest $request,
        MembershipCouponAdminService $couponService
    ): JsonResponse {
        try {
            $coupon = $couponService->createCoupon(
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership coupon created successfully.',
                'data' => $coupon,
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership coupon.', $e);
        }
    }

    public function update(
        MembershipCouponRequest $request,
        MembershipCoupon $coupon,
        MembershipCouponAdminService $couponService
    ): JsonResponse {
        try {
            $coupon = $couponService->updateCoupon(
                coupon: $coupon,
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership coupon updated successfully.',
                'data' => $coupon,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to update membership coupon.', $e);
        }
    }

    public function destroy(
        Request $request,
        MembershipCoupon $coupon,
        MembershipCouponAdminService $couponService
    ): JsonResponse {
        try {
            $couponService->deleteCoupon(
                coupon: $coupon,
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership coupon deleted or deactivated successfully.',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to delete membership coupon.', $e);
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