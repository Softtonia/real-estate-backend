<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\CreateMembershipAddonOrderRequest;
use App\Http\Requests\Membership\VerifyRazorpayPaymentRequest;
use App\Http\Resources\Membership\MembershipAddonOrderResource;
use App\Http\Resources\Membership\MembershipAddonResource;
use App\Models\Membership\MembershipAddon;
use App\Models\Membership\MembershipAddonOrder;
use App\Models\User;
use App\Services\Membership\MembershipAddonOrderService;
use App\Services\Membership\RazorpayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserMembershipAddonController extends Controller
{
    public function addons(
        Request $request,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $addons = $addonOrderService->activeAddons($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership add-ons fetched successfully.',
                'data' => MembershipAddonResource::collection($addons),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership add-ons.', $e);
        }
    }

    public function showAddon(
        string|int $addon,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $membershipAddon = $this->findAddon($addon);

            if (!$membershipAddon) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership add-on not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on fetched successfully.',
                'data' => new MembershipAddonResource($addonOrderService->addonDetail($membershipAddon)),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership add-on.', $e);
        }
    }

    public function createAddonOrder(
        CreateMembershipAddonOrderRequest $request,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $order = $addonOrderService->createOrder($user, $request->validated());

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on order created successfully.',
                'data' => new MembershipAddonOrderResource($order),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership add-on order.', $e);
        }
    }

    public function addonOrders(
        Request $request,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $orders = $addonOrderService->userOrders($user, $request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on orders fetched successfully.',
                'data' => MembershipAddonOrderResource::collection($orders),
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
            return $this->serverError('Unable to fetch membership add-on orders.', $e);
        }
    }

    public function showAddonOrder(
        Request $request,
        string|int $order,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $addonOrder = $this->findUserAddonOrder($order, $user);

            if (!$addonOrder) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership add-on order not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on order fetched successfully.',
                'data' => new MembershipAddonOrderResource(
                    $addonOrderService->orderDetail($addonOrder, $user)
                ),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership add-on order.', $e);
        }
    }

    public function createRazorpayAddonOrder(
        Request $request,
        string|int $order,
        RazorpayPaymentService $razorpayPaymentService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $addonOrder = $this->findUserAddonOrder($order, $user);

            if (!$addonOrder) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership add-on order not found.',
                ], 404);
            }

            $checkout = $razorpayPaymentService->createRazorpayAddonOrder($addonOrder, $user);

            return response()->json([
                'status' => true,
                'message' => 'Razorpay add-on order created successfully.',
                'data' => $checkout,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create Razorpay add-on order.', $e);
        }
    }

    public function verifyAddonPayment(
        VerifyRazorpayPaymentRequest $request,
        RazorpayPaymentService $razorpayPaymentService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $payment = $razorpayPaymentService->verifyAddonPayment(
                user: $user,
                data: $request->validated()
            );

            return response()->json([
                'status' => true,
                'message' => 'Add-on payment verified successfully.',
                'data' => [
                    'payment' => [
                        'id' => (int) $payment->id,
                        'addon_order_id' => (int) $payment->addon_order_id,
                        'razorpay_order_id' => $payment->razorpay_order_id,
                        'razorpay_payment_id' => $payment->razorpay_payment_id,
                        'amount' => (float) $payment->amount,
                        'currency' => $payment->currency,
                        'payment_status' => $payment->payment_status,
                        'payment_method' => $payment->payment_method,
                        'verified_at' => optional($payment->verified_at)->toDateTimeString(),
                    ],
                    'order' => $payment->addonOrder
                        ? new MembershipAddonOrderResource($payment->addonOrder)
                        : null,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to verify add-on payment.', $e);
        }
    }

    private function findAddon(string|int $addon): ?MembershipAddon
    {
        return MembershipAddon::query()
            ->where(function ($query) use ($addon) {
                if (is_numeric($addon)) {
                    $query->where('id', (int) $addon);
                }

                $query->orWhere('slug', (string) $addon);
            })
            ->first();
    }

    private function findUserAddonOrder(string|int $order, User $user): ?MembershipAddonOrder
    {
        return MembershipAddonOrder::query()
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
                'auth' => ['Admin token is not allowed for frontend membership add-on API.'],
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
            $user = User::query()->where('api_token', $token)->first();

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

        foreach (['name', 'role_name', 'title'] as $column) {
            if (Schema::hasColumn('roles', $column) && isset($role->{$column})) {
                $roleName = strtolower(str_replace([' ', '_', '-'], '', (string) $role->{$column}));

                return in_array($roleName, [
                    'admin',
                    'administrator',
                    'superadmin',
                    'superadministrator',
                ], true);
            }
        }

        return false;
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