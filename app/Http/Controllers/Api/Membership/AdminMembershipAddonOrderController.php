<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Resources\Membership\MembershipAddonOrderResource;
use App\Models\Membership\MembershipAddonOrder;
use App\Models\Membership\MembershipAddonUsage;
use App\Services\Membership\MembershipAddonOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminMembershipAddonOrderController extends Controller
{
    public function index(
        Request $request,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $orders = $addonOrderService->adminOrders($request->all());

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
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership add-on orders.', $e);
        }
    }

    public function show(
        MembershipAddonOrder $addonOrder,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $addonOrder = $addonOrderService->orderDetail($addonOrder);

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on order fetched successfully.',
                'data' => new MembershipAddonOrderResource($addonOrder),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership add-on order.', $e);
        }
    }

    public function markFailed(
        Request $request,
        MembershipAddonOrder $addonOrder,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            $request->validate([
                'reason' => ['nullable', 'string', 'max:1000'],
            ]);

            $addonOrder = $addonOrderService->markOrderAsFailed(
                order: $addonOrder,
                reason: $request->input('reason', 'Marked failed by admin.'),
                metadata: [
                    'marked_by' => 'admin',
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on order marked as failed successfully.',
                'data' => new MembershipAddonOrderResource($addonOrder),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to mark add-on order as failed.', $e);
        }
    }

    public function applyBenefits(
        MembershipAddonOrder $addonOrder,
        MembershipAddonOrderService $addonOrderService
    ): JsonResponse {
        try {
            if ($addonOrder->payment_status !== MembershipAddonOrder::PAYMENT_PAID) {
                throw ValidationException::withMessages([
                    'addon_order_id' => ['Benefits can be applied only for paid add-on orders.'],
                ]);
            }

            $addonOrderService->applyAddonBenefits($addonOrder);

            $addonOrder = $addonOrderService->orderDetail($addonOrder->fresh());

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on benefits applied successfully.',
                'data' => new MembershipAddonOrderResource($addonOrder),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to apply add-on benefits.', $e);
        }
    }

    public function usages(Request $request): JsonResponse
    {
        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $usages = MembershipAddonUsage::query()
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'addon:id,name,slug,addon_type,credit_type,credit_quantity',
                    'addonOrder:id,order_number,total_amount,payment_status,order_status',
                    'membership:id,user_id,plan_id,status,start_date,expiry_date',
                    'membership.plan:id,name,slug',
                ])
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->user_id))
                ->when($request->filled('addon_id'), fn ($q) => $q->where('addon_id', (int) $request->addon_id))
                ->when($request->filled('addon_order_id'), fn ($q) => $q->where('addon_order_id', (int) $request->addon_order_id))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on usages fetched successfully.',
                'data' => $usages,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch add-on usages.', $e);
        }
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