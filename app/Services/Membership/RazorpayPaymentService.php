<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAddonOrder;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipPayment;
use App\Models\User;
use App\Services\Payment\PaymentGatewayConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Razorpay\Api\Api;
use Throwable;

class RazorpayPaymentService
{
    public function __construct(
        private readonly MembershipOrderService $orderService,
        private readonly MembershipAccessService $accessService,
        private readonly MembershipAddonOrderService $addonOrderService,
        private readonly PaymentGatewayConfigService $gatewayConfigService
    ) {}

    public function createRazorpayOrder(MembershipOrder $order, User $user): array
    {
        if ((int) $order->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'order_id' => ['Order not found.'],
            ]);
        }

        if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['This order is already paid.'],
            ]);
        }

        if ($order->order_status !== MembershipOrder::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'order_id' => ['This order is not payable.'],
            ]);
        }

        if ($order->expires_at && $order->expires_at->isPast()) {
            $this->orderService->markOrderAsFailed(
                order: $order,
                reason: 'Order expired before Razorpay payment initiation.'
            );

            throw ValidationException::withMessages([
                'order_id' => ['This order has expired. Please create a new order.'],
            ]);
        }

        if ((float) $order->total_amount <= 0) {
            throw ValidationException::withMessages([
                'order_id' => ['Free order does not require Razorpay payment.'],
            ]);
        }

        if ($order->razorpay_order_id) {
            return $this->checkoutPayload($order->fresh(['user', 'plan']));
        }

        $api = $this->api();
        $credentials = $this->activeCredentials();

        try {
            $razorpayOrder = $api->order->create([
                'receipt' => $order->order_number,
                'amount' => $order->amountInPaise(),
                'currency' => $order->currency ?: ($credentials['currency'] ?? 'INR'),
                'payment_capture' => 1,
                'notes' => [
                    'local_order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'user_id' => (string) $order->user_id,
                    'plan_id' => (string) $order->plan_id,
                    'order_type' => 'membership_plan',
                    'gateway_mode' => $credentials['mode'] ?? 'test',
                ],
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'razorpay' => ['Unable to create Razorpay order: ' . $e->getMessage()],
            ]);
        }

        $razorpayOrderPayload = method_exists($razorpayOrder, 'toArray')
            ? $razorpayOrder->toArray()
            : (array) $razorpayOrder;

        DB::transaction(function () use ($order, $razorpayOrder, $razorpayOrderPayload) {
            $order->update([
                'razorpay_order_id' => $razorpayOrder['id'] ?? null,
                'order_status' => MembershipOrder::STATUS_PROCESSING,
                'metadata' => array_merge($order->metadata ?? [], [
                    'razorpay_order_response' => $razorpayOrderPayload,
                ]),
            ]);
        });

        return $this->checkoutPayload($order->fresh(['user', 'plan']));
    }

    public function verifyMembershipPayment(User $user, array $data): MembershipPayment
    {
        $razorpayOrderId = (string) ($data['razorpay_order_id'] ?? '');
        $razorpayPaymentId = (string) ($data['razorpay_payment_id'] ?? '');
        $razorpaySignature = (string) ($data['razorpay_signature'] ?? '');

        if (! $razorpayOrderId || ! $razorpayPaymentId || ! $razorpaySignature) {
            throw ValidationException::withMessages([
                'payment' => ['Razorpay order id, payment id, and signature are required.'],
            ]);
        }

        $order = MembershipOrder::query()
            ->with(['user', 'plan'])
            ->where('razorpay_order_id', $razorpayOrderId)
            ->first();

        if (! $order || (int) $order->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'razorpay_order_id' => ['Order not found.'],
            ]);
        }

        if ($order->payment_status === MembershipOrder::PAYMENT_PAID) {
            $existingPayment = MembershipPayment::query()
                ->where('razorpay_payment_id', $razorpayPaymentId)
                ->first();

            if ($existingPayment) {
                return $existingPayment->load(['membershipOrder.plan', 'user']);
            }
        }

        $this->verifyPaymentSignature(
            razorpayOrderId: $razorpayOrderId,
            razorpayPaymentId: $razorpayPaymentId,
            razorpaySignature: $razorpaySignature
        );

        $paymentDetails = $this->fetchPayment($razorpayPaymentId);

        return DB::transaction(function () use (
            $order,
            $user,
            $razorpayOrderId,
            $razorpayPaymentId,
            $razorpaySignature,
            $paymentDetails
        ) {
            $payment = MembershipPayment::query()->updateOrCreate(
                [
                    'razorpay_payment_id' => $razorpayPaymentId,
                ],
                [
                    'membership_order_id' => $order->id,
                    'addon_order_id' => null,
                    'user_id' => $user->id,
                    'payment_gateway' => 'razorpay',
                    'razorpay_order_id' => $razorpayOrderId,
                    'razorpay_signature' => $razorpaySignature,
                    'currency' => $order->currency ?: 'INR',
                    'amount' => $order->total_amount,
                    'payment_status' => $this->normalizeRazorpayPaymentStatus(
                        (string) ($paymentDetails['status'] ?? 'captured')
                    ),
                    'payment_method' => $paymentDetails['method'] ?? null,
                    'payment_date' => now(),
                    'verified_at' => now(),
                    'failure_reason' => $paymentDetails['error_description'] ?? null,
                    'gateway_response' => $paymentDetails,
                ]
            );

            if ($payment->payment_status === MembershipPayment::STATUS_CAPTURED) {
                $paidOrder = $this->orderService->markOrderAsPaid($order, [
                    'payment_method' => $payment->payment_method,
                    'razorpay_order_id' => $razorpayOrderId,
                    'razorpay_payment_id' => $razorpayPaymentId,
                    'verified_from' => 'frontend_verify_api',
                ], $user);

                app(MembershipActivationService::class)->activateFromOrder($paidOrder);
            } else {
                $this->orderService->markOrderAsFailed(
                    order: $order,
                    reason: 'Razorpay payment status: ' . $payment->payment_status,
                    metadata: $paymentDetails
                );
            }

            $this->clearCaches($user);

            return $payment->fresh(['membershipOrder.plan', 'user']);
        });
    }

    public function verifyPaymentSignature(
        string $razorpayOrderId,
        string $razorpayPaymentId,
        string $razorpaySignature
    ): bool {
        $credentials = $this->activeCredentials();

        $secret = (string) ($credentials['key_secret'] ?? '');

        if (! $secret) {
            throw ValidationException::withMessages([
                'razorpay' => ['Razorpay key secret is not configured.'],
            ]);
        }

        $payload = $razorpayOrderId . '|' . $razorpayPaymentId;
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expectedSignature, $razorpaySignature)) {
            throw ValidationException::withMessages([
                'razorpay_signature' => ['Invalid Razorpay payment signature.'],
            ]);
        }

        return true;
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }

        $credentials = $this->activeCredentials();

        $secret = (string) ($credentials['webhook_secret'] ?? '');

        if (! $secret) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    public function fetchPayment(string $razorpayPaymentId): array
    {
        try {
            return $this->api()
                ->payment
                ->fetch($razorpayPaymentId)
                ->toArray();
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'razorpay_payment_id' => ['Unable to fetch Razorpay payment: ' . $e->getMessage()],
            ]);
        }
    }

    public function checkoutPayload(MembershipOrder $order): array
    {
        $order->loadMissing(['user', 'plan']);

        $credentials = $this->activeCredentials();

        return [
            'key' => $credentials['key_id'],
            'mode' => $credentials['mode'],
            'razorpay_order_id' => $order->razorpay_order_id,
            'order_id' => (int) $order->id,
            'order_number' => $order->order_number,
            'amount' => $order->amountInPaise(),
            'display_amount' => (float) $order->total_amount,
            'currency' => $order->currency ?: ($credentials['currency'] ?? 'INR'),
            'name' => config('app.name', 'Holiplaces'),
            'description' => 'Membership Plan - ' . ($order->plan?->name ?? 'Plan'),
            'prefill' => [
                'name' => trim(($order->user?->first_name ?? '') . ' ' . ($order->user?->last_name ?? '')),
                'email' => $order->user?->email,
                'contact' => $order->user?->phone,
            ],
            'notes' => [
                'local_order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'user_id' => (string) $order->user_id,
                'plan_id' => (string) $order->plan_id,
                'order_type' => 'membership_plan',
            ],
        ];
    }

    public function createRazorpayAddonOrder(MembershipAddonOrder $order, User $user): array
    {
        if ((int) $order->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'order_id' => ['Order not found.'],
            ]);
        }

        if ($order->payment_status === MembershipAddonOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['This add-on order is already paid.'],
            ]);
        }

        if (! in_array($order->order_status, [
            MembershipAddonOrder::STATUS_PENDING,
            MembershipAddonOrder::STATUS_PROCESSING,
        ], true)) {
            throw ValidationException::withMessages([
                'order_id' => ['This add-on order is not payable.'],
            ]);
        }

        if ((float) $order->total_amount <= 0) {
            throw ValidationException::withMessages([
                'order_id' => ['Free add-on order does not require Razorpay payment.'],
            ]);
        }

        if ($order->razorpay_order_id) {
            return $this->addonCheckoutPayload($order->fresh(['user', 'addon']));
        }

        $credentials = $this->activeCredentials();

        try {
            $razorpayOrder = $this->api()->order->create([
                'receipt' => $order->order_number,
                'amount' => $order->amountInPaise(),
                'currency' => $order->currency ?: ($credentials['currency'] ?? 'INR'),
                'payment_capture' => 1,
                'notes' => [
                    'local_addon_order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'user_id' => (string) $order->user_id,
                    'addon_id' => (string) $order->addon_id,
                    'order_type' => 'membership_addon',
                    'gateway_mode' => $credentials['mode'] ?? 'test',
                ],
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'razorpay' => ['Unable to create Razorpay add-on order: ' . $e->getMessage()],
            ]);
        }

        $razorpayOrderPayload = method_exists($razorpayOrder, 'toArray')
            ? $razorpayOrder->toArray()
            : (array) $razorpayOrder;

        DB::transaction(function () use ($order, $razorpayOrder, $razorpayOrderPayload) {
            $order->update([
                'razorpay_order_id' => $razorpayOrder['id'] ?? null,
                'order_status' => MembershipAddonOrder::STATUS_PROCESSING,
                'metadata' => array_merge($order->metadata ?? [], [
                    'razorpay_order_response' => $razorpayOrderPayload,
                ]),
            ]);
        });

        return $this->addonCheckoutPayload($order->fresh(['user', 'addon']));
    }

    public function verifyAddonPayment(User $user, array $data): MembershipPayment
    {
        $razorpayOrderId = (string) ($data['razorpay_order_id'] ?? '');
        $razorpayPaymentId = (string) ($data['razorpay_payment_id'] ?? '');
        $razorpaySignature = (string) ($data['razorpay_signature'] ?? '');

        if (! $razorpayOrderId || ! $razorpayPaymentId || ! $razorpaySignature) {
            throw ValidationException::withMessages([
                'payment' => ['Razorpay order id, payment id, and signature are required.'],
            ]);
        }

        $order = MembershipAddonOrder::query()
            ->with(['user', 'addon', 'membership'])
            ->where('razorpay_order_id', $razorpayOrderId)
            ->first();

        if (! $order || (int) $order->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'razorpay_order_id' => ['Add-on order not found.'],
            ]);
        }

        if ($order->payment_status === MembershipAddonOrder::PAYMENT_PAID) {
            $existingPayment = MembershipPayment::query()
                ->where('razorpay_payment_id', $razorpayPaymentId)
                ->first();

            if ($existingPayment) {
                return $existingPayment->load(['addonOrder.addon', 'user']);
            }
        }

        $this->verifyPaymentSignature(
            razorpayOrderId: $razorpayOrderId,
            razorpayPaymentId: $razorpayPaymentId,
            razorpaySignature: $razorpaySignature
        );

        $paymentDetails = $this->fetchPayment($razorpayPaymentId);

        return DB::transaction(function () use (
            $order,
            $user,
            $razorpayOrderId,
            $razorpayPaymentId,
            $razorpaySignature,
            $paymentDetails
        ) {
            $payment = MembershipPayment::query()->updateOrCreate(
                [
                    'razorpay_payment_id' => $razorpayPaymentId,
                ],
                [
                    'membership_order_id' => null,
                    'addon_order_id' => $order->id,
                    'user_id' => $user->id,
                    'payment_gateway' => 'razorpay',
                    'razorpay_order_id' => $razorpayOrderId,
                    'razorpay_signature' => $razorpaySignature,
                    'currency' => $order->currency ?: 'INR',
                    'amount' => $order->total_amount,
                    'payment_status' => $this->normalizeRazorpayPaymentStatus(
                        (string) ($paymentDetails['status'] ?? 'captured')
                    ),
                    'payment_method' => $paymentDetails['method'] ?? null,
                    'payment_date' => now(),
                    'verified_at' => now(),
                    'failure_reason' => $paymentDetails['error_description'] ?? null,
                    'gateway_response' => $paymentDetails,
                ]
            );

            if ($payment->payment_status === MembershipPayment::STATUS_CAPTURED) {
                $this->addonOrderService->markOrderAsPaid($order, [
                    'payment_method' => $payment->payment_method,
                    'razorpay_order_id' => $razorpayOrderId,
                    'razorpay_payment_id' => $razorpayPaymentId,
                    'verified_from' => 'frontend_verify_api',
                ], $user);
            } else {
                $this->addonOrderService->markOrderAsFailed(
                    order: $order,
                    reason: 'Razorpay payment status: ' . $payment->payment_status,
                    metadata: $paymentDetails
                );
            }

            $this->clearCaches($user);

            return $payment->fresh(['addonOrder.addon', 'user']);
        });
    }

    public function addonCheckoutPayload(MembershipAddonOrder $order): array
    {
        $order->loadMissing(['user', 'addon']);

        $credentials = $this->activeCredentials();

        return [
            'key' => $credentials['key_id'],
            'mode' => $credentials['mode'],
            'razorpay_order_id' => $order->razorpay_order_id,
            'addon_order_id' => (int) $order->id,
            'order_number' => $order->order_number,
            'amount' => $order->amountInPaise(),
            'display_amount' => (float) $order->total_amount,
            'currency' => $order->currency ?: ($credentials['currency'] ?? 'INR'),
            'name' => config('app.name', 'Holiplaces'),
            'description' => 'Membership Add-on - ' . ($order->addon?->name ?? 'Add-on'),
            'prefill' => [
                'name' => trim(($order->user?->first_name ?? '') . ' ' . ($order->user?->last_name ?? '')),
                'email' => $order->user?->email,
                'contact' => $order->user?->phone,
            ],
            'notes' => [
                'local_addon_order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'user_id' => (string) $order->user_id,
                'addon_id' => (string) $order->addon_id,
                'order_type' => 'membership_addon',
            ],
        ];
    }

    private function api(): Api
    {
        $credentials = $this->activeCredentials();

        if (! $credentials['enabled']) {
            throw ValidationException::withMessages([
                'payment_gateway' => ['Razorpay payment gateway is disabled.'],
            ]);
        }

        if (empty($credentials['key_id']) || empty($credentials['key_secret'])) {
            throw ValidationException::withMessages([
                'payment_gateway' => ['Razorpay key id or key secret is not configured.'],
            ]);
        }

        return new Api($credentials['key_id'], $credentials['key_secret']);
    }

    private function activeCredentials(): array
    {
        return $this->gatewayConfigService->activeRazorpayCredentials();
    }

    private function normalizeRazorpayPaymentStatus(string $status): string
    {
        return match ($status) {
            'authorized' => MembershipPayment::STATUS_AUTHORIZED,
            'captured' => MembershipPayment::STATUS_CAPTURED,
            'failed' => MembershipPayment::STATUS_FAILED,
            'refunded' => MembershipPayment::STATUS_REFUNDED,
            default => MembershipPayment::STATUS_CREATED,
        };
    }

    private function clearCaches(User $user): void
    {
        $this->accessService->forgetUserCache($user);

        if (Schema::hasTable('membership_settings')) {
            Cache::store('redis')->forget('membership:admin:stats');
        }
    }
}