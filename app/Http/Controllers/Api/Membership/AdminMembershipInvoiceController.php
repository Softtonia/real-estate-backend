<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Resources\Membership\MembershipInvoiceResource;
use App\Models\Membership\MembershipInvoice;
use App\Services\Membership\MembershipInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AdminMembershipInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $invoices = MembershipInvoice::query()
                ->select([
                    'id',
                    'membership_order_id',
                    'addon_order_id',
                    'user_id',
                    'invoice_number',
                    'invoice_date',
                    'currency',
                    'taxable_amount',
                    'gst_percentage',
                    'cgst_amount',
                    'sgst_amount',
                    'igst_amount',
                    'gst_amount',
                    'total_amount',
                    'billing_name',
                    'billing_email',
                    'billing_phone',
                    'billing_gst_number',
                    'billing_address',
                    'billing_city',
                    'billing_state',
                    'billing_country',
                    'billing_pincode',
                    'invoice_pdf_disk',
                    'invoice_pdf_path',
                    'status',
                    'created_at',
                ])
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id',
                    'membershipOrder:id,order_number,plan_id,total_amount,payment_status,order_status',
                    'membershipOrder.plan:id,name,slug',
                    'addonOrder:id,order_number,addon_id,total_amount,payment_status,order_status',
                    'addonOrder.addon:id,name,slug',
                ])
                ->when($request->filled('user_id'), function ($query) use ($request) {
                    $query->where('user_id', (int) $request->user_id);
                })
                ->when($request->filled('type'), function ($query) use ($request) {
                    if ($request->type === 'membership') {
                        $query->whereNotNull('membership_order_id');
                    }

                    if ($request->type === 'addon') {
                        $query->whereNotNull('addon_order_id');
                    }
                })
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = trim((string) $request->search);

                    $query->where(function ($q) use ($search) {
                        $q->where('invoice_number', 'like', "%{$search}%")
                            ->orWhere('billing_name', 'like', "%{$search}%")
                            ->orWhere('billing_email', 'like', "%{$search}%")
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
                'message' => 'Membership invoices fetched successfully.',
                'data' => MembershipInvoiceResource::collection($invoices),
                'meta' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page' => $invoices->lastPage(),
                    'per_page' => $invoices->perPage(),
                    'total' => $invoices->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership invoices.', $e);
        }
    }

    public function show(MembershipInvoice $invoice): JsonResponse
    {
        try {
            $invoice->loadMissing([
                'user:id,first_name,last_name,email,phone,role_id',
                'membershipOrder.plan',
                'addonOrder.addon',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Membership invoice fetched successfully.',
                'data' => new MembershipInvoiceResource($invoice),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership invoice.', $e);
        }
    }

    public function download(
        MembershipInvoice $invoice,
        MembershipInvoiceService $invoiceService
    ): JsonResponse|BinaryFileResponse {
        try {
            $invoice = $this->ensureInvoicePdf($invoice, $invoiceService);

            return $this->downloadInvoicePdf($invoice);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to download membership invoice.', $e);
        }
    }

    private function ensureInvoicePdf(
        MembershipInvoice $invoice,
        MembershipInvoiceService $invoiceService
    ): MembershipInvoice {
        $disk = $invoice->invoice_pdf_disk ?: 'local';
        $path = $invoice->invoice_pdf_path;

        if ($path && Storage::disk($disk)->exists($path)) {
            return $invoice;
        }

        $invoice->loadMissing(['membershipOrder', 'addonOrder']);

        if ($invoice->membership_order_id && $invoice->membershipOrder) {
            return $invoiceService->generateForMembershipOrder($invoice->membershipOrder);
        }

        if ($invoice->addon_order_id && $invoice->addonOrder) {
            return $invoiceService->generateForAddonOrder($invoice->addonOrder);
        }

        throw ValidationException::withMessages([
            'invoice' => ['Invoice source order was not found.'],
        ]);
    }

    private function downloadInvoicePdf(MembershipInvoice $invoice): BinaryFileResponse
    {
        $disk = $invoice->invoice_pdf_disk ?: 'local';
        $path = $invoice->invoice_pdf_path;

        if (!$path || !Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice PDF file was not found.'],
            ]);
        }

        return response()->download(
            Storage::disk($disk)->path($path),
            $invoice->invoice_number . '.pdf',
            [
                'Content-Type' => 'application/pdf',
            ]
        );
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