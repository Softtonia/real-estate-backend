<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipSetting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipOrderService
{
    public function __construct(
        private readonly MembershipPlanService $planService,
        private readonly MembershipCouponService $couponService,
        private readonly MembershipAccessService $accessService
    ) {}

    public function createOrder(User $user, array $data): MembershipOrder
    {
        $plan = MembershipPlan::query()
            ->with(['category', 'planFeatures.feature', 'roleRules'])
            ->active()
            ->findOrFail((int) $data['plan_id']);

        if (! $this->planService->isPlanAllowedForUser($plan, $user)) {
            throw ValidationException::withMessages([
                'plan_id' => ['This membership plan is not available for your role.'],
            ]);
        }

        $subtotal = round((float) $plan->payableAmount(), 2);

        $couponResult = $this->couponService->validateForPlan(
            couponCode: $data['coupon_code'] ?? null,
            user: $user,
            plan: $plan,
            subtotal: $subtotal
        );

        $gstPercentage = $this->gstPercentage();

        $amounts = $this->calculateAmounts(
            subtotal: $subtotal,
            discountAmount: (float) $couponResult['discount_amount'],
            gstPercentage: $gstPercentage
        );

        return DB::transaction(function () use ($user, $plan, $data, $couponResult, $amounts) {
            $this->cancelOldPendingOrders($user);

            $isFreeOrder = (float) $amounts['total_amount'] <= 0;

            $order = MembershipOrder::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'coupon_id' => $couponResult['coupon_id'],
                'order_number' => $this->generateOrderNumber(),

                'gateway_name' => 'razorpay',
                'razorpay_order_id' => null,

                'currency' => $plan->currency ?: 'INR',
                'subtotal' => $amounts['subtotal'],
                'discount_amount' => $amounts['discount_amount'],
                'taxable_amount' => $amounts['taxable_amount'],
                'gst_percentage' => $amounts['gst_percentage'],
                'gst_amount' => $amounts['gst_amount'],
                'total_amount' => $amounts['total_amount'],

                'payment_status' => $isFreeOrder
                    ? MembershipOrder::PAYMENT_PAID
                    : MembershipOrder::PAYMENT_PENDING,

                'order_status' => $isFreeOrder
                    ? MembershipOrder::STATUS_COMPLETED
                    : MembershipOrder::STATUS_PENDING,

                'payment_method' => $isFreeOrder ? 'free' : null,
                'expires_at' => $isFreeOrder ? null : now()->addMinutes($this->orderExpiryMinutes()),
                'paid_at' => $isFreeOrder ? now() : null,
                'cancelled_at' => null,

                'created_by' => $data['created_by'] ?? $user->id,
                'notes' => $data['notes'] ?? null,
                'metadata' => [
                    'source' => $data['source'] ?? 'frontend',
                    'plan_snapshot' => $this->planSnapshot($plan),
                    'coupon_snapshot' => $couponResult['coupon']
                        ? $this->couponSnapshot($couponResult['coupon'])
                        : null,
                    'billing' => $data['billing'] ?? [],
                    'user_role_id' => $user->role_id ?? null,
                ],
            ]);

            $this->audit(
                action: 'order_created',
                user: $user,
                auditable: $order,
                performedBy: $user,
                oldValues: null,
                newValues: $order->toArray()
            );

            $this->clearOrderCaches($user);

            return $this->freshOrder($order);
        });
    }

    public function userOrders(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 50);

        return MembershipOrder::query()
            ->select([
                'id',
                'user_id',
                'plan_id',
                'coupon_id',
                'order_number',
                'gateway_name',
                'razorpay_order_id',
                'currency',
                'subtotal',
                'discount_amount',
                'taxable_amount',
                'gst_percentage',
                'gst_amount',
                'total_amount',
                'payment_status',
                'order_status',
                'payment_method',
                'expires_at',
                'paid_at',
                'cancelled_at',
                'created_by',
                'created_at',
            ])
            ->with([
                'user:id,first_name,last_name,email,phone,role_id',
                'createdBy:id,first_name,last_name,email,phone,role_id',
                'plan:id,category_id,name,slug,currency,price,sale_price,duration,duration_type',
                'plan.category:id,name,slug',
                'coupon:id,code,title',
            ])
            ->where('user_id', $user->id)
            ->when(! empty($filters['payment_status']), function ($query) use ($filters) {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when(! empty($filters['order_status']), function ($query) use ($filters) {
                $query->where('order_status', $filters['order_status']);
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function adminOrders(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return MembershipOrder::query()
            ->select([
                'id',
                'user_id',
                'plan_id',
                'coupon_id',
                'order_number',
                'gateway_name',
                'razorpay_order_id',
                'currency',
                'subtotal',
                'discount_amount',
                'taxable_amount',
                'gst_percentage',
                'gst_amount',
                'total_amount',
                'payment_status',
                'order_status',
                'payment_method',
                'expires_at',
                'paid_at',
                'cancelled_at',
                'created_by',
                'created_at',
            ])
            ->with([
                'user:id,first_name,last_name,email,phone,role_id',
                'createdBy:id,first_name,last_name,email,phone,role_id',
                'plan:id,category_id,name,slug,currency,price,sale_price,duration,duration_type',
                'plan.category:id,name,slug',
                'coupon:id,code,title',
            ])
            ->when(! empty($filters['user_id']), function ($query) use ($filters) {
                $query->where('user_id', (int) $filters['user_id']);
            })
            ->when(! empty($filters['created_by']), function ($query) use ($filters) {
                $query->where('created_by', (int) $filters['created_by']);
            })
            ->when(! empty($filters['plan_id']), function ($query) use ($filters) {
                $query->where('plan_id', (int) $filters['plan_id']);
            })
            ->when(! empty($filters['payment_status']), function ($query) use ($filters) {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when(! empty($filters['order_status']), function ($query) use ($filters) {
                $query->where('order_status', $filters['order_status']);
            })
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('razorpay_order_id', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('createdBy', function ($userQuery) use ($search) {
                            $userQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function orderDetail(MembershipOrder $order, ?User $user = null): MembershipOrder
    {
        if ($user && (int) $order->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'order_id' => ['Order not found.'],
            ]);
        }

        return $this->freshOrder($order);
    }

    public function cancelOrder(MembershipOrder $order, ?User $performedBy = null): MembershipOrder
    {
        if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['Paid order cannot be cancelled from this action.'],
            ]);
        }

        if ($order->order_status === MembershipOrder::STATUS_CANCELLED) {
            return $this->freshOrder($order);
        }

        return DB::transaction(function () use ($order, $performedBy) {
            $order = MembershipOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
                throw ValidationException::withMessages([
                    'order_id' => ['Paid order cannot be cancelled from this action.'],
                ]);
            }

            if ($order->order_status === MembershipOrder::STATUS_CANCELLED) {
                return $this->freshOrder($order);
            }

            $oldValues = $order->toArray();

            $order->update([
                'order_status' => MembershipOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $this->audit(
                action: 'order_cancelled',
                user: $order->user,
                auditable: $order,
                performedBy: $performedBy,
                oldValues: $oldValues,
                newValues: $order->fresh()->toArray()
            );

            $this->clearOrderCaches($order->user);

            return $this->freshOrder($order);
        });
    }

    public function markOrderAsPaid(
        MembershipOrder $order,
        array $paymentData = [],
        ?User $performedBy = null
    ): MembershipOrder {
        if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
            return $this->freshOrder($order);
        }

        return DB::transaction(function () use ($order, $paymentData, $performedBy) {
            $order = MembershipOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
                return $this->freshOrder($order);
            }

            $oldValues = $order->toArray();

            $order->update([
                'payment_status' => MembershipOrder::PAYMENT_PAID,
                'order_status' => MembershipOrder::STATUS_COMPLETED,
                'payment_method' => $paymentData['payment_method'] ?? $order->payment_method,
                'paid_at' => now(),
                'metadata' => array_merge($order->metadata ?? [], [
                    'paid_payload' => $paymentData,
                ]),
            ]);

            if ($order->coupon_id) {
                $this->couponService->recordUsageForMembershipOrder($order->fresh());
            }

            $this->audit(
                action: 'order_marked_paid',
                user: $order->user,
                auditable: $order,
                performedBy: $performedBy,
                oldValues: $oldValues,
                newValues: $order->fresh()->toArray()
            );

            $this->clearOrderCaches($order->user);

            return $this->freshOrder($order);
        });
    }

    public function markOrderAsFailed(
        MembershipOrder $order,
        string $reason,
        array $metadata = []
    ): MembershipOrder {
        if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
            return $this->freshOrder($order);
        }

        return DB::transaction(function () use ($order, $reason, $metadata) {
            $order = MembershipOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
                return $this->freshOrder($order);
            }

            $oldValues = $order->toArray();

            $order->update([
                'payment_status' => MembershipOrder::PAYMENT_FAILED,
                'order_status' => MembershipOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'notes' => trim(($order->notes ? $order->notes . "\n" : '') . $reason),
                'metadata' => array_merge($order->metadata ?? [], [
                    'failed_reason' => $reason,
                    'failed_payload' => $metadata,
                ]),
            ]);

            $this->audit(
                action: 'order_payment_failed',
                user: $order->user,
                auditable: $order,
                performedBy: null,
                oldValues: $oldValues,
                newValues: $order->fresh()->toArray()
            );

            $this->clearOrderCaches($order->user);

            return $this->freshOrder($order);
        });
    }

    public function expirePendingOrders(): int
    {
        return DB::transaction(function () {
            $orders = MembershipOrder::query()
                ->where('payment_status', MembershipOrder::PAYMENT_PENDING)
                ->where('order_status', MembershipOrder::STATUS_PENDING)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                $oldValues = $order->toArray();

                $order->update([
                    'order_status' => MembershipOrder::STATUS_EXPIRED,
                    'cancelled_at' => now(),
                ]);

                $this->audit(
                    action: 'order_expired',
                    user: $order->user,
                    auditable: $order,
                    performedBy: null,
                    oldValues: $oldValues,
                    newValues: $order->fresh()->toArray()
                );

                $this->clearOrderCaches($order->user);
            }

            return $orders->count();
        });
    }

    public function calculateAmounts(
        float $subtotal,
        float $discountAmount,
        float $gstPercentage
    ): array {
        $subtotal = round(max(0, $subtotal), 2);
        $discountAmount = round(min(max(0, $discountAmount), $subtotal), 2);

        $taxableAmount = round($subtotal - $discountAmount, 2);
        $gstAmount = round(($taxableAmount * $gstPercentage) / 100, 2);
        $totalAmount = round($taxableAmount + $gstAmount, 2);

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'taxable_amount' => $taxableAmount,
            'gst_percentage' => round($gstPercentage, 2),
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
        ];
    }

    private function cancelOldPendingOrders(User $user): void
    {
        MembershipOrder::query()
            ->where('user_id', $user->id)
            ->where('payment_status', MembershipOrder::PAYMENT_PENDING)
            ->where('order_status', MembershipOrder::STATUS_PENDING)
            ->whereNull('paid_at')
            ->update([
                'order_status' => MembershipOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function freshOrder(MembershipOrder $order): MembershipOrder
    {
        return $order->fresh([
            'user:id,first_name,last_name,email,phone,role_id',
            'createdBy:id,first_name,last_name,email,phone,role_id',
            'plan.category',
            'plan.planFeatures.feature',
            'coupon:id,code,title,discount_type,discount_value',
            'payments',
            'membership',
            'invoice',
        ]);
    }

    private function generateOrderNumber(): string
    {
        $prefix = $this->settingValue('order_prefix', 'HPMORD');

        do {
            $number = $prefix
                . now()->format('YmdHis')
                . strtoupper(Str::random(6));
        } while (MembershipOrder::query()->where('order_number', $number)->exists());

        return $number;
    }

    private function gstPercentage(): float
    {
        return (float) $this->settingValue('gst_percentage', 18);
    }

    private function orderExpiryMinutes(): int
    {
        return (int) $this->settingValue('order_expiry_minutes', 30);
    }

    private function settingValue(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable('membership_settings')) {
            return $default;
        }

        return Cache::store('redis')->remember(
            "membership:setting:{$key}",
            600,
            function () use ($key, $default) {
                $setting = MembershipSetting::query()
                    ->where('key', $key)
                    ->first();

                return $setting ? $setting->formattedValue() : $default;
            }
        );
    }

    private function planSnapshot(MembershipPlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'category_id' => (int) $plan->category_id,
            'category_name' => $plan->category?->name,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'currency' => $plan->currency,
            'price' => (float) $plan->price,
            'sale_price' => $plan->sale_price !== null ? (float) $plan->sale_price : null,
            'payable_amount' => $plan->payableAmount(),
            'duration' => (int) $plan->duration,
            'duration_type' => $plan->duration_type,
        ];
    }

    private function couponSnapshot(mixed $coupon): array
    {
        return [
            'id' => (int) $coupon->id,
            'code' => $coupon->code,
            'title' => $coupon->title,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'maximum_discount_amount' => $coupon->maximum_discount_amount !== null
                ? (float) $coupon->maximum_discount_amount
                : null,
        ];
    }

    private function clearOrderCaches(?User $user): void
    {
        if ($user) {
            $this->accessService->forgetUserCache($user);
        }

        Cache::store('redis')->forget('membership:admin:stats');
    }

    private function audit(
        string $action,
        ?User $user,
        ?object $auditable,
        ?User $performedBy,
        ?array $oldValues,
        ?array $newValues
    ): void {
        if (! Schema::hasTable('membership_audit_logs')) {
            return;
        }

        MembershipAuditLog::query()->create([
            'user_id' => $user?->id,
            'performed_by' => $performedBy?->id,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id ?? null,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}