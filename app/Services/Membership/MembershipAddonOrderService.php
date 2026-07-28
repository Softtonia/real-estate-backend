<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAddon;
use App\Models\Membership\MembershipAddonOrder;
use App\Models\Membership\MembershipAuditLog;
use App\Models\Membership\MembershipSetting;
use App\Models\Membership\UserMembership;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipAddonOrderService
{
    public function __construct(
        private readonly MembershipCouponService $couponService,
        private readonly MembershipCreditService $creditService,
        private readonly MembershipAccessService $accessService
    ) {}

    public function activeAddons(array $filters = [])
    {
        return Cache::store('redis')->remember(
            'membership:addons:active',
            600,
            fn() => MembershipAddon::query()
                ->select([
                    'id',
                    'name',
                    'slug',
                    'description',
                    'addon_type',
                    'currency',
                    'price',
                    'sale_price',
                    'credit_type',
                    'credit_quantity',
                    'duration_days',
                    'status',
                    'sort_order',
                    'metadata',
                ])
                ->active()
                ->orderBy('sort_order')
                ->latest('id')
                ->get()
        );
    }

    public function addonDetail(MembershipAddon $addon): MembershipAddon
    {
        if (!$addon->status) {
            throw ValidationException::withMessages([
                'addon_id' => ['This add-on is not available.'],
            ]);
        }

        return $addon;
    }

    public function createOrder(User $user, array $data): MembershipAddonOrder
    {
        $addon = MembershipAddon::query()
            ->active()
            ->findOrFail((int) $data['addon_id']);

        $membership = $this->activeMembership($user);

        if ($addon->credit_type && !$membership) {
            throw ValidationException::withMessages([
                'membership' => ['Active membership is required to purchase this add-on.'],
            ]);
        }

        $subtotal = round((float) $addon->payableAmount(), 2);

        $couponResult = $this->couponService->validateForAddon(
            couponCode: $data['coupon_code'] ?? null,
            user: $user,
            addon: $addon,
            subtotal: $subtotal
        );

        $amounts = $this->calculateAmounts(
            subtotal: $subtotal,
            discountAmount: (float) $couponResult['discount_amount'],
            gstPercentage: $this->gstPercentage()
        );

        return DB::transaction(function () use ($user, $addon, $membership, $data, $couponResult, $amounts) {
            $isFreeOrder = (float) $amounts['total_amount'] <= 0;

            $order = MembershipAddonOrder::query()->create([
                'user_id' => $user->id,
                'addon_id' => $addon->id,
                'membership_id' => $membership?->id,
                'coupon_id' => $couponResult['coupon_id'],
                'order_number' => $this->generateOrderNumber(),

                'gateway_name' => 'razorpay',
                'razorpay_order_id' => null,

                'currency' => $addon->currency ?: 'INR',
                'subtotal' => $amounts['subtotal'],
                'discount_amount' => $amounts['discount_amount'],
                'taxable_amount' => $amounts['taxable_amount'],
                'gst_percentage' => $amounts['gst_percentage'],
                'gst_amount' => $amounts['gst_amount'],
                'total_amount' => $amounts['total_amount'],

                'payment_status' => $isFreeOrder
                    ? MembershipAddonOrder::PAYMENT_PAID
                    : MembershipAddonOrder::PAYMENT_PENDING,

                'order_status' => $isFreeOrder
                    ? MembershipAddonOrder::STATUS_COMPLETED
                    : MembershipAddonOrder::STATUS_PENDING,

                'payment_method' => $isFreeOrder ? 'free' : null,
                'paid_at' => $isFreeOrder ? now() : null,
                'cancelled_at' => null,

                'metadata' => [
                    'source' => $data['source'] ?? 'frontend',
                    'billing' => $data['billing'] ?? [],
                    'addon_snapshot' => $this->addonSnapshot($addon),
                    'coupon_snapshot' => $couponResult['coupon']
                        ? $this->couponSnapshot($couponResult['coupon'])
                        : null,
                ],
            ]);

            if ($isFreeOrder) {
                $this->applyAddonBenefits($order);
            }

            $this->audit(
                action: 'addon_order_created',
                user: $user,
                auditable: $order,
                performedBy: $user,
                oldValues: null,
                newValues: $order->toArray()
            );

            $this->clearCaches($user);

            return $this->freshOrder($order);
        });
    }

    public function userOrders(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 50);

        return MembershipAddonOrder::query()
            ->with([
                'addon:id,name,slug,addon_type,credit_type,credit_quantity,duration_days',
                'membership:id,user_id,plan_id,status,start_date,expiry_date',
                'coupon:id,code,title',
            ])
            ->where('user_id', $user->id)
            ->when(!empty($filters['payment_status']), fn($q) => $q->where('payment_status', $filters['payment_status']))
            ->when(!empty($filters['order_status']), fn($q) => $q->where('order_status', $filters['order_status']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function orderDetail(MembershipAddonOrder $order, ?User $user = null): MembershipAddonOrder
    {
        if ($user && (int) $order->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'order_id' => ['Order not found.'],
            ]);
        }

        return $this->freshOrder($order);
    }

    public function markOrderAsPaid(
        MembershipAddonOrder $order,
        array $paymentData = [],
        ?User $performedBy = null
    ): MembershipAddonOrder {
        if ($order->payment_status === MembershipAddonOrder::PAYMENT_PAID) {
            return $this->freshOrder($order);
        }

        return DB::transaction(function () use ($order, $paymentData, $performedBy) {
            $oldValues = $order->toArray();

            $order->update([
                'payment_status' => MembershipAddonOrder::PAYMENT_PAID,
                'order_status' => MembershipAddonOrder::STATUS_COMPLETED,
                'payment_method' => $paymentData['payment_method'] ?? $order->payment_method,
                'paid_at' => now(),
                'metadata' => array_merge($order->metadata ?? [], [
                    'paid_payload' => $paymentData,
                ]),
            ]);

            if ($order->coupon_id) {
                $this->couponService->recordUsageForAddonOrder($order->fresh());
            }

            $this->applyAddonBenefits($order->fresh(['addon', 'membership', 'user']));
            $this->dispatchInvoiceJobIfExists($order->fresh());

            $this->audit(
                action: 'addon_order_marked_paid',
                user: $order->user,
                auditable: $order,
                performedBy: $performedBy,
                oldValues: $oldValues,
                newValues: $order->fresh()->toArray()
            );

            $this->clearCaches($order->user);

            return $this->freshOrder($order);
        });
    }
    public function expirePendingOrders(?int $olderThanMinutes = null): int
    {
        $olderThanMinutes = $olderThanMinutes ?: (int) $this->settingValue('order_expiry_minutes', 30);

        $cutoff = now()->subMinutes(max($olderThanMinutes, 1));

        $expiredCount = 0;

        MembershipAddonOrder::query()
            ->where('payment_status', MembershipAddonOrder::PAYMENT_PENDING)
            ->whereIn('order_status', [
                MembershipAddonOrder::STATUS_PENDING,
                MembershipAddonOrder::STATUS_PROCESSING,
            ])
            ->where('created_at', '<=', $cutoff)
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$expiredCount) {
                foreach ($orders as $order) {
                    $freshOrder = MembershipAddonOrder::query()->find($order->id);

                    if (!$freshOrder) {
                        continue;
                    }

                    $this->markOrderAsFailed(
                        order: $freshOrder,
                        reason: 'Add-on order expired before payment completion.',
                        metadata: [
                            'expired_by' => 'scheduler',
                        ]
                    );

                    $expiredCount++;
                }
            });

        return $expiredCount;
    }
    private function dispatchInvoiceJobIfExists(MembershipAddonOrder $order): void
    {
        $jobClass = \App\Jobs\Membership\GenerateMembershipInvoiceJob::class;

        if (!class_exists($jobClass)) {
            return;
        }

        dispatch(new $jobClass(null, $order->id))->onQueue('membership');
    }
    public function markOrderAsFailed(
        MembershipAddonOrder $order,
        string $reason,
        array $metadata = []
    ): MembershipAddonOrder {
        if ($order->payment_status === MembershipAddonOrder::PAYMENT_PAID) {
            return $this->freshOrder($order);
        }

        return DB::transaction(function () use ($order, $reason, $metadata) {
            $oldValues = $order->toArray();

            $order->update([
                'payment_status' => MembershipAddonOrder::PAYMENT_FAILED,
                'order_status' => MembershipAddonOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'metadata' => array_merge($order->metadata ?? [], [
                    'failed_reason' => $reason,
                    'failed_payload' => $metadata,
                ]),
            ]);

            $this->audit(
                action: 'addon_order_payment_failed',
                user: $order->user,
                auditable: $order,
                performedBy: null,
                oldValues: $oldValues,
                newValues: $order->fresh()->toArray()
            );

            $this->clearCaches($order->user);

            return $this->freshOrder($order);
        });
    }

    public function applyAddonBenefits(MembershipAddonOrder $order): void
    {
        $order->loadMissing(['addon', 'membership', 'user']);

        $addon = $order->addon;
        $user = $order->user;

        if (!$addon || !$user) {
            return;
        }

        if (!$addon->credit_type || !$addon->credit_quantity) {
            return;
        }

        $alreadyApplied = \App\Models\Membership\MembershipCreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('reference_type', 'membership_addon_order')
            ->where('reference_id', $order->id)
            ->exists();

        if ($alreadyApplied) {
            return;
        }

        $this->creditService->addCredits(
            user: $user,
            creditType: $addon->credit_type,
            quantity: (int) $addon->credit_quantity,
            membership: $order->membership,
            referenceType: 'membership_addon_order',
            referenceId: (int) $order->id,
            reason: 'Credits added from paid add-on order.',
            performedBy: null,
            metadata: [
                'addon_id' => $addon->id,
                'addon_slug' => $addon->slug,
                'duration_days' => $addon->duration_days,
            ]
        );
    }

    public function calculateAmounts(float $subtotal, float $discountAmount, float $gstPercentage): array
    {
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

    private function activeMembership(User $user): ?UserMembership
    {
        return UserMembership::query()
            ->where('user_id', $user->id)
            ->active()
            ->latest('expiry_date')
            ->latest('id')
            ->first();
    }

    private function freshOrder(MembershipAddonOrder $order): MembershipAddonOrder
    {
        return $order->fresh([
            'user:id,first_name,last_name,email,phone,role_id',
            'addon',
            'membership.plan',
            'coupon:id,code,title,discount_type,discount_value',
            'payments',
            'usages',
        ]);
    }

    private function generateOrderNumber(): string
    {
        $prefix = $this->settingValue('addon_order_prefix', 'HPMADD');

        do {
            $number = $prefix . now()->format('YmdHis') . strtoupper(Str::random(6));
        } while (MembershipAddonOrder::query()->where('order_number', $number)->exists());

        return $number;
    }

    private function gstPercentage(): float
    {
        return (float) $this->settingValue('gst_percentage', 18);
    }

    private function settingValue(string $key, mixed $default = null): mixed
    {
        if (!Schema::hasTable('membership_settings')) {
            return $default;
        }

        return Cache::store('redis')->remember("membership:setting:{$key}", 600, function () use ($key, $default) {
            $setting = MembershipSetting::query()->where('key', $key)->first();

            return $setting ? $setting->formattedValue() : $default;
        });
    }

    private function addonSnapshot(MembershipAddon $addon): array
    {
        return [
            'id' => (int) $addon->id,
            'name' => $addon->name,
            'slug' => $addon->slug,
            'addon_type' => $addon->addon_type,
            'currency' => $addon->currency,
            'price' => (float) $addon->price,
            'sale_price' => $addon->sale_price !== null ? (float) $addon->sale_price : null,
            'payable_amount' => $addon->payableAmount(),
            'credit_type' => $addon->credit_type,
            'credit_quantity' => $addon->credit_quantity !== null ? (int) $addon->credit_quantity : null,
            'duration_days' => $addon->duration_days !== null ? (int) $addon->duration_days : null,
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

    private function clearCaches(?User $user): void
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
        if (!Schema::hasTable('membership_audit_logs')) {
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
    public function adminOrders(array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return MembershipAddonOrder::query()
            ->with([
                'user:id,first_name,last_name,email,phone,role_id',
                'addon:id,name,slug,addon_type,credit_type,credit_quantity,duration_days',
                'membership:id,user_id,plan_id,status,start_date,expiry_date',
                'membership.plan:id,name,slug',
                'coupon:id,code,title',
                'payments',
            ])
            ->when(!empty($filters['user_id']), fn($q) => $q->where('user_id', (int) $filters['user_id']))
            ->when(!empty($filters['addon_id']), fn($q) => $q->where('addon_id', (int) $filters['addon_id']))
            ->when(!empty($filters['payment_status']), fn($q) => $q->where('payment_status', $filters['payment_status']))
            ->when(!empty($filters['order_status']), fn($q) => $q->where('order_status', $filters['order_status']))
            ->when(!empty($filters['razorpay_order_id']), fn($q) => $q->where('razorpay_order_id', $filters['razorpay_order_id']))
            ->when(!empty($filters['search']), function ($query) use ($filters) {
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
                        ->orWhereHas('addon', function ($addonQuery) use ($search) {
                            $addonQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('slug', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }
}
