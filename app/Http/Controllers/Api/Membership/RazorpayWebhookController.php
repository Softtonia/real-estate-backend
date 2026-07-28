<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Services\Membership\MembershipWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class RazorpayWebhookController extends Controller
{
    public function handle(
        Request $request,
        MembershipWebhookService $webhookService
    ): JsonResponse {
        try {
            $event = $webhookService->receiveRazorpayWebhook(
                rawPayload: $request->getContent(),
                signature: $request->header('X-Razorpay-Signature'),
                eventId: $request->header('X-Razorpay-Event-Id')
            );

            return response()->json([
                'status' => true,
                'message' => 'Webhook received successfully.',
                'data' => [
                    'event_id' => $event->event_id,
                    'event_name' => $event->event_name,
                    'processing_status' => $event->status,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Razorpay webhook.',
                'error' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to process Razorpay webhook.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }
}