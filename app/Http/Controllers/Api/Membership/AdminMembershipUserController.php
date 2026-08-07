<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\AdjustMembershipCreditRequest;
use App\Http\Requests\Membership\Admin\CancelMembershipRequest;
use App\Http\Requests\Membership\Admin\ManualActivateMembershipRequest;
use App\Http\Resources\Membership\MembershipOrderResource;
use App\Http\Resources\Membership\UserMembershipResource;
use App\Models\Membership\MembershipCreditBalance;
use App\Models\Membership\MembershipCreditTransaction;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipPayment;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\User;
use App\Services\Membership\MembershipActivationService;
use App\Services\Membership\MembershipCreditService;
use App\Services\Membership\MembershipOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminMembershipUserController extends Controller
{
    public function orders(
        Request $request,
        MembershipOrderService $orderService
    ): JsonResponse {
        try {
            $orders = $orderService->adminOrders($request->all());

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
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership orders.', $e);
        }
    }

    public function showOrder(
        MembershipOrder $order,
        MembershipOrderService $orderService
    ): JsonResponse {
        try {
            $order = $orderService->orderDetail($order);

            return response()->json([
                'status' => true,
                'message' => 'Membership order fetched successfully.',
                'data' => new MembershipOrderResource($order),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership order.', $e);
        }
    }

    public function payments(Request $request): JsonResponse
    {
        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $payments = MembershipPayment::query()
                ->select([
                    'id',
                    'membership_order_id',
                    'addon_order_id',
                    'user_id',
                    'payment_gateway',
                    'razorpay_order_id',
                    'razorpay_payment_id',
                    'currency',
                    'amount',
                    'payment_status',
                    'payment_method',
                    'payment_date',
                    'verified_at',
                    'failure_reason',
                    'created_at',
                ])
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'membershipOrder:id,order_number,plan_id,total_amount,payment_status,order_status',
                    'membershipOrder.plan:id,name,slug',
                ])
                ->when($request->filled('user_id'), function ($query) use ($request) {
                    $query->where('user_id', (int) $request->user_id);
                })
                ->when($request->filled('payment_status'), function ($query) use ($request) {
                    $query->where('payment_status', $request->payment_status);
                })
                ->when($request->filled('razorpay_order_id'), function ($query) use ($request) {
                    $query->where('razorpay_order_id', $request->razorpay_order_id);
                })
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = trim((string) $request->search);

                    $query->where(function ($q) use ($search) {
                        $q->where('razorpay_payment_id', 'like', "%{$search}%")
                            ->orWhere('razorpay_order_id', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%");
                            });
                    });
                })
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Membership payments fetched successfully.',
                'data' => $payments,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership payments.', $e);
        }
    }

    public function memberships(Request $request): JsonResponse
    {
        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $memberships = UserMembership::query()
                ->select([
                    'id',
                    'user_id',
                    'plan_id',
                    'order_id',
                    'parent_membership_id',
                    'start_date',
                    'expiry_date',
                    'status',
                    'auto_renew',
                    'cancelled_at',
                    'expired_at',
                    'source',
                    'created_at',
                ])
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'plan:id,category_id,name,slug,duration,duration_type',
                    'plan.category:id,name,slug',
                    'order:id,order_number,total_amount,payment_status,order_status',
                    'creditBalances',
                ])
                ->when($request->filled('user_id'), function ($query) use ($request) {
                    $query->where('user_id', (int) $request->user_id);
                })
                ->when($request->filled('plan_id'), function ($query) use ($request) {
                    $query->where('plan_id', (int) $request->plan_id);
                })
                ->when($request->filled('status'), function ($query) use ($request) {
                    $query->where('status', $request->status);
                })
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = trim((string) $request->search);

                    $query->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                })
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'User memberships fetched successfully.',
                'data' => UserMembershipResource::collection($memberships),
                'meta' => [
                    'current_page' => $memberships->currentPage(),
                    'last_page' => $memberships->lastPage(),
                    'per_page' => $memberships->perPage(),
                    'total' => $memberships->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch user memberships.', $e);
        }
    }

    public function showMembership(UserMembership $membership): JsonResponse
    {
        try {
            $membership->loadMissing([
                'user:id,first_name,last_name,email,phone,role_id',
                'plan.category',
                'order',
                'creditBalances',
                'creditTransactions' => function ($query) {
                    $query->latest('id')->limit(50);
                },
            ]);

            return response()->json([
                'status' => true,
                'message' => 'User membership fetched successfully.',
                'data' => new UserMembershipResource($membership),
                'recent_transactions' => $membership->creditTransactions,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch user membership.', $e);
        }
    }

    public function manualActivate(
        ManualActivateMembershipRequest $request,
        MembershipActivationService $activationService
    ): JsonResponse {
        try {
            $data = $request->validated();

            $user = User::query()->findOrFail((int) $data['user_id']);
            $plan = MembershipPlan::query()
                ->with(['planFeatures.feature'])
                ->findOrFail((int) $data['plan_id']);

            $membership = $activationService->manualActivate(
                user: $user,
                plan: $plan,
                admin: $this->resolveCurrentUser($request),
                options: [
                    'start_date' => $data['start_date'] ?? null,
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership manually activated successfully.',
                'data' => new UserMembershipResource($membership),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to manually activate membership.', $e);
        }
    }

    public function cancelMembership(
        CancelMembershipRequest $request,
        UserMembership $membership,
        MembershipActivationService $activationService
    ): JsonResponse {
        try {
            $membership = $activationService->cancelMembership(
                membership: $membership,
                performedBy: $this->resolveCurrentUser($request),
                reason: $request->validated()['reason'] ?? null
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership cancelled successfully.',
                'data' => new UserMembershipResource($membership),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to cancel membership.', $e);
        }
    }

    public function expireMembership(
        Request $request,
        UserMembership $membership,
        MembershipActivationService $activationService
    ): JsonResponse {
        try {
            $membership = $activationService->expireMembership(
                membership: $membership,
                performedBy: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership expired successfully.',
                'data' => new UserMembershipResource($membership),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to expire membership.', $e);
        }
    }

    public function userCredits(
        Request $request,
        MembershipCreditService $creditService
    ): JsonResponse {
        try {
            /*
        |--------------------------------------------------------------------------
        | If user_id is passed, return single user credit summary
        |--------------------------------------------------------------------------
        */
            if ($request->filled('user_id') && ! $request->boolean('list')) {
                $request->validate([
                    'user_id' => ['required', 'integer', 'exists:users,id'],
                ]);

                $user = User::query()->findOrFail((int) $request->user_id);

                return response()->json([
                    'status' => true,
                    'message' => 'User membership credits fetched successfully.',
                    'data' => $creditService->userCreditSummary($user),
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Otherwise return admin credit balance list
        |--------------------------------------------------------------------------
        */
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $query = MembershipCreditBalance::query()
                ->select([
                    'id',
                    'user_id',
                    'membership_id',
                    'credit_type',
                    'is_unlimited',
                    'total_credits',
                    'used_credits',
                    'remaining_credits',
                    'status',
                    'expires_at',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'user:id,user_name,first_name,last_name,email,phone,role_id',
                    'membership:id,user_id,plan_id,status,start_date,expiry_date',
                    'membership.plan:id,name,slug',
                ])
                ->when($request->filled('user_id'), function ($query) use ($request) {
                    $query->where('user_id', (int) $request->user_id);
                })
                ->when($request->filled('membership_id'), function ($query) use ($request) {
                    $query->where('membership_id', (int) $request->membership_id);
                })
                ->when($request->filled('credit_type'), function ($query) use ($request) {
                    $query->where('credit_type', $request->credit_type);
                })
                ->when($request->filled('status'), function ($query) use ($request) {
                    $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('is_unlimited'), function ($query) use ($request) {
                    $query->where('is_unlimited', filter_var($request->is_unlimited, FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('date_from'), function ($query) use ($request) {
                    $query->whereDate('created_at', '>=', $request->date_from);
                })
                ->when($request->filled('date_to'), function ($query) use ($request) {
                    $query->whereDate('created_at', '<=', $request->date_to);
                })
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = trim((string) $request->search);

                    $query->where(function ($q) use ($search) {
                        $q->where('credit_type', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('user_name', 'like', "%{$search}%")
                                    ->orWhere('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%");
                            })
                            ->orWhereHas('membership.plan', function ($planQuery) use ($search) {
                                $planQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('slug', 'like', "%{$search}%");
                            });
                    });
                });

            $stats = null;

            if ($request->boolean('include_stats')) {
                $statsQuery = clone $query;

                $stats = [
                    'total_records' => (clone $statsQuery)->count(),
                    'active' => (clone $statsQuery)->where('status', true)->count(),
                    'inactive' => (clone $statsQuery)->where('status', false)->count(),

                    'unlimited' => (clone $statsQuery)->where('is_unlimited', true)->count(),
                    'limited' => (clone $statsQuery)->where('is_unlimited', false)->count(),

                    'unique_users' => (clone $statsQuery)->distinct()->count('user_id'),
                    'unique_memberships' => (clone $statsQuery)->distinct()->count('membership_id'),

                    'total_credits' => round((float) (clone $statsQuery)->where('is_unlimited', false)->sum('total_credits'), 2),
                    'used_credits' => round((float) (clone $statsQuery)->sum('used_credits'), 2),
                    'remaining_credits' => round((float) (clone $statsQuery)->where('is_unlimited', false)->sum('remaining_credits'), 2),

                    'by_credit_type' => (clone $statsQuery)
                        ->selectRaw('credit_type, COUNT(*) as total, SUM(total_credits) as total_credits, SUM(used_credits) as used_credits, SUM(remaining_credits) as remaining_credits')
                        ->groupBy('credit_type')
                        ->get()
                        ->map(function ($row) {
                            return [
                                'credit_type' => $row->credit_type,
                                'total' => (int) $row->total,
                                'total_credits' => (int) $row->total_credits,
                                'used_credits' => (int) $row->used_credits,
                                'remaining_credits' => (int) $row->remaining_credits,
                            ];
                        })
                        ->values(),
                ];
            }

            $balances = $query
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Membership credits fetched successfully.',
                'data' => $balances->getCollection()->map(function (MembershipCreditBalance $balance) {
                    return [
                        'id' => (int) $balance->id,
                        'user_id' => (int) $balance->user_id,
                        'membership_id' => (int) $balance->membership_id,

                        'user' => $balance->user ? [
                            'id' => (int) $balance->user->id,
                            'user_name' => $balance->user->user_name,
                            'first_name' => $balance->user->first_name,
                            'last_name' => $balance->user->last_name,
                            'email' => $balance->user->email,
                            'phone' => $balance->user->phone,
                            'role_id' => $balance->user->role_id,
                        ] : null,

                        'membership' => $balance->membership ? [
                            'id' => (int) $balance->membership->id,
                            'plan_id' => (int) $balance->membership->plan_id,
                            'plan_name' => $balance->membership->plan?->name,
                            'plan_slug' => $balance->membership->plan?->slug,
                            'status' => $balance->membership->status,
                            'start_date' => optional($balance->membership->start_date)->toDateTimeString(),
                            'expiry_date' => optional($balance->membership->expiry_date)->toDateTimeString(),
                        ] : null,

                        'credit_type' => $balance->credit_type,
                        'is_unlimited' => (bool) $balance->is_unlimited,

                        'total_credits' => $balance->total_credits !== null ? (int) $balance->total_credits : null,
                        'used_credits' => (int) $balance->used_credits,
                        'remaining_credits' => $balance->remaining_credits !== null ? (int) $balance->remaining_credits : null,

                        'status' => (bool) $balance->status,
                        'expires_at' => optional($balance->expires_at)->toDateTimeString(),
                        'created_at' => optional($balance->created_at)->toDateTimeString(),
                        'updated_at' => optional($balance->updated_at)->toDateTimeString(),
                    ];
                }),
                'stats' => $stats,
                'meta' => [
                    'current_page' => $balances->currentPage(),
                    'last_page' => $balances->lastPage(),
                    'per_page' => $balances->perPage(),
                    'total' => $balances->total(),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership credits.', $e);
        }
    }

    public function adjustCredit(
        AdjustMembershipCreditRequest $request,
        MembershipCreditService $creditService
    ): JsonResponse {
        try {
            $data = $request->validated();

            $user = User::query()->findOrFail((int) $data['user_id']);

            $transaction = $creditService->adjustCredits(
                user: $user,
                creditType: $data['credit_type'],
                transactionType: $data['transaction_type'] ?? 'credit',
                quantity: isset($data['quantity']) ? (int) $data['quantity'] : null,
                newRemainingCredits: array_key_exists('remaining_credits', $data)
                    ? (int) $data['remaining_credits']
                    : null,
                reason: $data['reason'] ?? null,
                performedBy: $this->resolveCurrentUser($request),
                metadata: $data['metadata'] ?? []
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership credit adjusted successfully.',
                'data' => $transaction,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to adjust membership credit.', $e);
        }
    }

    public function creditTransactions(Request $request): JsonResponse
    {
        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $transactions = MembershipCreditTransaction::query()
                ->select([
                    'id',
                    'user_id',
                    'membership_id',
                    'balance_id',
                    'credit_type',
                    'transaction_type',
                    'quantity',
                    'balance_before',
                    'balance_after',
                    'reference_type',
                    'reference_id',
                    'reason',
                    'performed_by',
                    'created_at',
                ])
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'membership:id,user_id,plan_id,status,start_date,expiry_date',
                    'membership.plan:id,name,slug',
                    'performer:id,first_name,last_name,email',
                ])
                ->when($request->filled('user_id'), function ($query) use ($request) {
                    $query->where('user_id', (int) $request->user_id);
                })
                ->when($request->filled('membership_id'), function ($query) use ($request) {
                    $query->where('membership_id', (int) $request->membership_id);
                })
                ->when($request->filled('credit_type'), function ($query) use ($request) {
                    $query->where('credit_type', $request->credit_type);
                })
                ->when($request->filled('transaction_type'), function ($query) use ($request) {
                    $query->where('transaction_type', $request->transaction_type);
                })
                ->when($request->filled('reference_type'), function ($query) use ($request) {
                    $query->where('reference_type', $request->reference_type);
                })
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Membership credit transactions fetched successfully.',
                'data' => $transactions,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership credit transactions.', $e);
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
    public function cancelOrder(
        Request $request,
        MembershipOrder $order,
        MembershipOrderService $orderService
    ): JsonResponse {
        try {
            $request->validate([
                'reason' => ['nullable', 'string', 'max:1000'],
            ]);

            $order = $orderService->cancelOrder(
                order: $order,
                performedBy: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership order cancelled successfully.',
                'data' => new MembershipOrderResource($order),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to cancel membership order.', $e);
        }
    }

    public function showPayment(MembershipPayment $payment): JsonResponse
    {
        try {
            $payment->loadMissing([
                'user:id,user_name,first_name,last_name,email,phone',
                'membershipOrder:id,order_number,user_id,plan_id,total_amount,payment_status,order_status',
                'membershipOrder.plan:id,name,slug',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Membership payment fetched successfully.',
                'data' => $payment,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership payment.', $e);
        }
    }
}
