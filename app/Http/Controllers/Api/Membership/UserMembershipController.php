<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\CreateMembershipOrderRequest;
use App\Http\Requests\Membership\VerifyRazorpayPaymentRequest;
use App\Http\Resources\Membership\MembershipOrderResource;
use App\Http\Resources\Membership\MembershipPlanResource;
use App\Http\Resources\Membership\MembershipPlanPriceResource;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipPlan;
use App\Models\User;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipActivationService;
use App\Services\Membership\MembershipCreditService;
use App\Services\Membership\MembershipOrderService;
use App\Services\Membership\MembershipPlanService;
use App\Services\Membership\MembershipPlanPriceService;
use App\Services\Membership\RazorpayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserMembershipController extends Controller
{
    public function plans(
        Request $request,
        MembershipPlanService $planService,
        MembershipPlanPriceService $priceService
    ): JsonResponse {
        try {
            $user = $this->resolveCurrentUser($request);

            $plans = $planService->activePlans($user);

            $couponCode = $request->query('coupon_code');

            return response()->json([
                'status' => true,
                'message' => 'Membership plans fetched successfully.',
                'data' => $plans->map(function ($plan) use ($priceService, $user, $couponCode) {
                    return [
                        'plan' => new MembershipPlanResource($plan),
                        'price_summary' => new MembershipPlanPriceResource(
                            $priceService->calculate(
                                plan: $plan,
                                user: $user,
                                couponCode: $couponCode
                            )
                        ),
                    ];
                })->values(),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership plans.', $e);
        }
    }

    public function showPlan(
        Request $request,
        string|int $plan,
        MembershipPlanService $planService,
        MembershipPlanPriceService $priceService
    ): JsonResponse {
        try {
            $user = $this->resolveCurrentUser($request);

            $membershipPlan = $this->findPlan($plan);

            if (!$membershipPlan || !$membershipPlan->status) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership plan not found.',
                ], 404);
            }

            $membershipPlan = $planService->planDetail($membershipPlan, $user);

            $priceSummary = $priceService->calculate(
                plan: $membershipPlan,
                user: $user,
                couponCode: $request->query('coupon_code')
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership plan fetched successfully.',
                'data' => [
                    'plan' => new MembershipPlanResource($membershipPlan),
                    'price_summary' => new MembershipPlanPriceResource($priceSummary),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership plan.', $e);
        }
    }
    public function planPricePreview(
        Request $request,
        string|int $plan,
        MembershipPlanPriceService $priceService
    ): JsonResponse {
        try {
            $user = $this->resolveCurrentUser($request);

            $membershipPlan = $this->findPlan($plan);

            if (!$membershipPlan || !$membershipPlan->status) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership plan not found.',
                ], 404);
            }

            $priceSummary = $priceService->calculate(
                plan: $membershipPlan,
                user: $user,
                couponCode: $request->query('coupon_code')
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership plan price calculated successfully.',
                'data' => [
                    'plan_id' => (int) $membershipPlan->id,
                    'price_summary' => new MembershipPlanPriceResource($priceSummary),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to calculate membership plan price.', $e);
        }
    }
    public function myStatus(
        Request $request,
        MembershipAccessService $accessService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            return response()->json([
                'status' => true,
                'message' => 'Membership status fetched successfully.',
                'data' => $accessService->userMembershipStatus($user),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership status.', $e);
        }
    }

    public function myCredits(
        Request $request,
        MembershipCreditService $creditService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            return response()->json([
                'status' => true,
                'message' => 'Membership credits fetched successfully.',
                'data' => $creditService->userCreditSummary($user),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership credits.', $e);
        }
    }

    public function orders(
        Request $request,
        MembershipOrderService $orderService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $orders = $orderService->userOrders($user, $request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership orders fetched successfully.',
                'data' => MembershipOrderResource::collection($orders),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership orders.', $e);
        }
    }

    public function showOrder(
        Request $request,
        string|int $order,
        MembershipOrderService $orderService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $membershipOrder = $this->findUserOrder($order, $user);

            if (!$membershipOrder) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership order not found.',
                ], 404);
            }

            $membershipOrder = $orderService->orderDetail($membershipOrder, $user);

            return response()->json([
                'status' => true,
                'message' => 'Membership order fetched successfully.',
                'data' => new MembershipOrderResource($membershipOrder),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership order.', $e);
        }
    }

    public function createOrder(
        CreateMembershipOrderRequest $request,
        MembershipOrderService $orderService,
        MembershipActivationService $activationService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $order = $orderService->createOrder($user, $request->validated());

            /*
             * Free plan / zero amount order activation.
             * Paid Razorpay order activation happens after payment verification or webhook.
             */
            if (
                $order->payment_status === MembershipOrder::PAYMENT_PAID
                && (float) $order->total_amount <= 0
            ) {
                $activationService->activateFromOrder($order);
                $order = $order->fresh([
                    'plan.category',
                    'coupon',
                    'payments',
                    'membership',
                    'invoice',
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Membership order created successfully.',
                'data' => new MembershipOrderResource($order),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership order.', $e);
        }
    }

    public function createRazorpayOrder(
        Request $request,
        string|int $order,
        RazorpayPaymentService $razorpayPaymentService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $membershipOrder = $this->findUserOrder($order, $user);

            if (!$membershipOrder) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership order not found.',
                ], 404);
            }

            $checkout = $razorpayPaymentService->createRazorpayOrder($membershipOrder, $user);

            return response()->json([
                'status' => true,
                'message' => 'Razorpay order created successfully.',
                'data' => $checkout,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create Razorpay order.', $e);
        }
    }

    public function verifyPayment(
        VerifyRazorpayPaymentRequest $request,
        RazorpayPaymentService $razorpayPaymentService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $payment = $razorpayPaymentService->verifyMembershipPayment(
                user: $user,
                data: $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => 'Payment verified successfully.',
                'data' => [
                    'payment' => [
                        'id' => (int) $payment->id,
                        'membership_order_id' => (int) $payment->membership_order_id,
                        'razorpay_order_id' => $payment->razorpay_order_id,
                        'razorpay_payment_id' => $payment->razorpay_payment_id,
                        'amount' => (float) $payment->amount,
                        'currency' => $payment->currency,
                        'payment_status' => $payment->payment_status,
                        'payment_method' => $payment->payment_method,
                        'verified_at' => optional($payment->verified_at)->toDateTimeString(),
                    ],
                    'order' => $payment->membershipOrder
                        ? new MembershipOrderResource($payment->membershipOrder)
                        : null,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to verify payment.', $e);
        }
    }

    private function findPlan(string|int $plan): ?MembershipPlan
    {
        return MembershipPlan::query()
            ->with([
                'category:id,name,slug,description',
                'planFeatures.feature:id,name,slug,description,feature_type,status,sort_order',
                'roleRules.role',
            ])
            ->where(function ($query) use ($plan) {
                if (is_numeric($plan)) {
                    $query->where('id', (int) $plan);
                }

                $query->orWhere('slug', (string) $plan);
            })
            ->first();
    }

    private function findUserOrder(string|int $order, User $user): ?MembershipOrder
    {
        return MembershipOrder::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($order) {
                if (is_numeric($order)) {
                    $query->where('id', (int) $order);
                }

                $query->orWhere('order_number', (string) $order)
                    ->orWhere('razorpay_order_id', (string) $order);
            })
            ->first();
    }

    private function authenticatedUserOrFail(Request $request): User
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user.'],
            ]);
        }

        if ($this->isAdminUser($user)) {
            throw ValidationException::withMessages([
                'auth' => ['Admin token is not allowed for frontend membership API.'],
            ]);
        }

        return $user;
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

    private function isAdminUser(User $user): bool
    {
        if ((int) $user->id === 1 || (string) $user->role_id === '1') {
            return true;
        }

        if (!Schema::hasTable('roles') || !$user->role_id || !is_numeric($user->role_id)) {
            return false;
        }

        $role = \App\Models\Role::query()->find((int) $user->role_id);

        if (!$role) {
            return false;
        }

        $roleName = null;

        foreach (['name', 'role_name', 'title'] as $column) {
            if (Schema::hasColumn('roles', $column) && isset($role->{$column})) {
                $roleName = strtolower(str_replace([' ', '_', '-'], '', (string) $role->{$column}));
                break;
            }
        }

        return in_array($roleName, [
            'admin',
            'administrator',
            'superadmin',
            'superadministrator',
        ], true);
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
