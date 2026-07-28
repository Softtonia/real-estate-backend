<?php

namespace App\Services\Membership;

use App\Jobs\Membership\ProcessRazorpayWebhookJob;
use App\Models\Membership\MembershipAddonOrder;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipPayment;
use App\Models\Membership\MembershipWebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MembershipWebhookService
{
    public function __construct(
        private readonly RazorpayPaymentService $razorpayPaymentService,
        private readonly MembershipOrderService $orderService,
        private readonly MembershipAccessService $accessService,
        private readonly MembershipAddonOrderService $addonOrderService
    ) {}

    public function receiveRazorpayWebhook(
        string $rawPayload,
        ?string $signature,
        ?string $eventId
    ): MembershipWebhookEvent {
        $this->razorpayPaymentService->verifyWebhookSignature(
            payload: $rawPayload,
            signature: $signature
        );

        $payload = json_decode($rawPayload, true);

        if (!is_array($payload)) {
            throw ValidationException::withMessages([
                'payload' => ['Invalid Razorpay webhook payload.'],
            ]);
        }

        $eventName = (string) ($payload['event'] ?? 'unknown');
        $eventId = $eventId ?: sha1($rawPayload);

        $existing = MembershipWebhookEvent::query()
            ->where('event_id', $eventId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $event = MembershipWebhookEvent::query()->create([
            'gateway' => 'razorpay',
            'event_id' => $eventId,
            'event_name' => $eventName,
            'status' => 'pending',
            'payload' => $payload,
            'error_message' => null,
            'processed_at' => null,
        ]);

        ProcessRazorpayWebhookJob::dispatch($event->id);

        return $event;
    }

    public function processStoredEvent(MembershipWebhookEvent $event): void
    {
        $lockKey = "membership:webhook:razorpay:{$event->event_id}";

        Cache::store('redis')->lock($lockKey, 60)->block(10, function () use ($event) {
            DB::transaction(function () use ($event) {
                $event = MembershipWebhookEvent::query()
                    ->where('id', $event->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($event->status === 'processed') {
                    return;
                }

                try {
                    $this->processPayload($event);

                    $event->update([
                        'status' => 'processed',
                        'error_message' => null,
                        'processed_at' => now(),
                    ]);
                } catch (Throwable $e) {
                    $event->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'processed_at' => now(),
                    ]);

                    throw $e;
                }
            });
        });
    }

    private function processPayload(MembershipWebhookEvent $event): void
    {
        $payload = $event->payload ?? [];
        $eventName = (string) ($payload['event'] ?? $event->event_name);

        $paymentEntity = data_get($payload, 'payload.payment.entity', []);
        $orderEntity = data_get($payload, 'payload.order.entity', []);

        if (!is_array($paymentEntity)) {
            $paymentEntity = [];
        }

        if (!is_array($orderEntity)) {
            $orderEntity = [];
        }

        $razorpayOrderId = $paymentEntity['order_id']
            ?? $orderEntity['id']
            ?? null;

        if (!$razorpayOrderId) {
            throw new \RuntimeException('Razorpay order id missing in webhook payload.');
        }

        $membershipOrder = MembershipOrder::query()
            ->with(['user', 'plan'])
            ->where('razorpay_order_id', $razorpayOrderId)
            ->first();

        if ($membershipOrder) {
            $this->processMembershipOrderWebhook(
                event: $event,
                order: $membershipOrder,
                eventName: $eventName,
                paymentEntity: $paymentEntity,
                payload: $payload,
                razorpayOrderId: $razorpayOrderId
            );

            return;
        }

        $addonOrder = MembershipAddonOrder::query()
            ->with(['user', 'addon', 'membership'])
            ->where('razorpay_order_id', $razorpayOrderId)
            ->first();

        if ($addonOrder) {
            $this->processAddonOrderWebhook(
                event: $event,
                order: $addonOrder,
                eventName: $eventName,
                paymentEntity: $paymentEntity,
                payload: $payload,
                razorpayOrderId: $razorpayOrderId
            );

            return;
        }

        throw new \RuntimeException(
            'Local membership or add-on order not found for Razorpay order id: ' . $razorpayOrderId
        );
    }

    private function processMembershipOrderWebhook(
        MembershipWebhookEvent $event,
        MembershipOrder $order,
        string $eventName,
        array $paymentEntity,
        array $payload,
        string $razorpayOrderId
    ): void {
        $payment = $this->storePaymentFromWebhook(
            order: $order,
            paymentEntity: $paymentEntity,
            eventName: $eventName
        );

        if (in_array($eventName, ['payment.captured', 'order.paid'], true)) {
            $paidOrder = $this->orderService->markOrderAsPaid(
                order: $order,
                paymentData: [
                    'payment_method' => $payment?->payment_method,
                    'razorpay_order_id' => $razorpayOrderId,
                    'razorpay_payment_id' => $payment?->razorpay_payment_id,
                    'verified_from' => 'razorpay_webhook',
                    'webhook_event_id' => $event->event_id,
                    'webhook_event_name' => $eventName,
                ],
                performedBy: null
            );

            $this->activateIfServiceExists($paidOrder);

            return;
        }

        if ($eventName === 'payment.failed') {
            $this->orderService->markOrderAsFailed(
                order: $order,
                reason: $paymentEntity['error_description'] ?? 'Razorpay payment failed.',
                metadata: [
                    'webhook_event_id' => $event->event_id,
                    'webhook_event_name' => $eventName,
                    'payment' => $paymentEntity,
                ]
            );

            return;
        }

        if (str_starts_with($eventName, 'refund.')) {
            $this->storeRefundMetadataOnly(
                order: $order,
                eventName: $eventName,
                payload: $payload
            );
        }
    }

    private function processAddonOrderWebhook(
        MembershipWebhookEvent $event,
        MembershipAddonOrder $order,
        string $eventName,
        array $paymentEntity,
        array $payload,
        string $razorpayOrderId
    ): void {
        $payment = $this->storeAddonPaymentFromWebhook(
            order: $order,
            paymentEntity: $paymentEntity,
            eventName: $eventName
        );

        if (in_array($eventName, ['payment.captured', 'order.paid'], true)) {
            $this->addonOrderService->markOrderAsPaid(
                order: $order,
                paymentData: [
                    'payment_method' => $payment?->payment_method,
                    'razorpay_order_id' => $razorpayOrderId,
                    'razorpay_payment_id' => $payment?->razorpay_payment_id,
                    'verified_from' => 'razorpay_webhook',
                    'webhook_event_id' => $event->event_id,
                    'webhook_event_name' => $eventName,
                ],
                performedBy: null
            );

            return;
        }

        if ($eventName === 'payment.failed') {
            $this->addonOrderService->markOrderAsFailed(
                order: $order,
                reason: $paymentEntity['error_description'] ?? 'Razorpay add-on payment failed.',
                metadata: [
                    'webhook_event_id' => $event->event_id,
                    'webhook_event_name' => $eventName,
                    'payment' => $paymentEntity,
                ]
            );

            return;
        }

        if (str_starts_with($eventName, 'refund.')) {
            $this->storeAddonRefundMetadataOnly(
                order: $order,
                eventName: $eventName,
                payload: $payload
            );
        }
    }

    private function storePaymentFromWebhook(
        MembershipOrder $order,
        array $paymentEntity,
        string $eventName
    ): ?MembershipPayment {
        $razorpayPaymentId = $paymentEntity['id'] ?? null;

        if (!$razorpayPaymentId) {
            return null;
        }

        return MembershipPayment::query()->updateOrCreate(
            [
                'razorpay_payment_id' => $razorpayPaymentId,
            ],
            [
                'membership_order_id' => $order->id,
                'addon_order_id' => null,
                'user_id' => $order->user_id,
                'payment_gateway' => 'razorpay',
                'razorpay_order_id' => $paymentEntity['order_id'] ?? $order->razorpay_order_id,
                'razorpay_signature' => null,
                'currency' => $paymentEntity['currency'] ?? $order->currency ?? 'INR',
                'amount' => isset($paymentEntity['amount'])
                    ? round(((float) $paymentEntity['amount']) / 100, 2)
                    : $order->total_amount,
                'payment_status' => $this->normalizePaymentStatus(
                    (string) ($paymentEntity['status'] ?? $eventName)
                ),
                'payment_method' => $paymentEntity['method'] ?? null,
                'payment_date' => isset($paymentEntity['created_at'])
                    ? now()->setTimestamp((int) $paymentEntity['created_at'])
                    : now(),
                'verified_at' => now(),
                'failure_reason' => $paymentEntity['error_description'] ?? null,
                'gateway_response' => $paymentEntity,
            ]
        );
    }

    private function storeAddonPaymentFromWebhook(
        MembershipAddonOrder $order,
        array $paymentEntity,
        string $eventName
    ): ?MembershipPayment {
        $razorpayPaymentId = $paymentEntity['id'] ?? null;

        if (!$razorpayPaymentId) {
            return null;
        }

        return MembershipPayment::query()->updateOrCreate(
            [
                'razorpay_payment_id' => $razorpayPaymentId,
            ],
            [
                'membership_order_id' => null,
                'addon_order_id' => $order->id,
                'user_id' => $order->user_id,
                'payment_gateway' => 'razorpay',
                'razorpay_order_id' => $paymentEntity['order_id'] ?? $order->razorpay_order_id,
                'razorpay_signature' => null,
                'currency' => $paymentEntity['currency'] ?? $order->currency ?? 'INR',
                'amount' => isset($paymentEntity['amount'])
                    ? round(((float) $paymentEntity['amount']) / 100, 2)
                    : $order->total_amount,
                'payment_status' => $this->normalizePaymentStatus(
                    (string) ($paymentEntity['status'] ?? $eventName)
                ),
                'payment_method' => $paymentEntity['method'] ?? null,
                'payment_date' => isset($paymentEntity['created_at'])
                    ? now()->setTimestamp((int) $paymentEntity['created_at'])
                    : now(),
                'verified_at' => now(),
                'failure_reason' => $paymentEntity['error_description'] ?? null,
                'gateway_response' => $paymentEntity,
            ]
        );
    }

    private function storeRefundMetadataOnly(
        MembershipOrder $order,
        string $eventName,
        array $payload
    ): void {
        $order->update([
            'metadata' => array_merge($order->metadata ?? [], [
                'latest_refund_webhook' => [
                    'event' => $eventName,
                    'payload' => $payload,
                    'received_at' => now()->toDateTimeString(),
                ],
            ]),
        ]);
    }

    private function storeAddonRefundMetadataOnly(
        MembershipAddonOrder $order,
        string $eventName,
        array $payload
    ): void {
        $order->update([
            'metadata' => array_merge($order->metadata ?? [], [
                'latest_refund_webhook' => [
                    'event' => $eventName,
                    'payload' => $payload,
                    'received_at' => now()->toDateTimeString(),
                ],
            ]),
        ]);
    }

    private function normalizePaymentStatus(string $status): string
    {
        return match ($status) {
            'authorized', 'payment.authorized' => MembershipPayment::STATUS_AUTHORIZED,
            'captured', 'payment.captured', 'order.paid' => MembershipPayment::STATUS_CAPTURED,
            'failed', 'payment.failed' => MembershipPayment::STATUS_FAILED,
            'refunded', 'refund.created', 'refund.processed' => MembershipPayment::STATUS_REFUNDED,
            default => MembershipPayment::STATUS_CREATED,
        };
    }

    private function activateIfServiceExists(MembershipOrder $order): void
    {
        $serviceClass = \App\Services\Membership\MembershipActivationService::class;

        if (!class_exists($serviceClass)) {
            return;
        }

        app($serviceClass)->activateFromOrder($order);
    }
}