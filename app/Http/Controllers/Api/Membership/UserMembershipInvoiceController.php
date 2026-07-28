<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Resources\Membership\MembershipInvoiceResource;
use App\Models\Membership\MembershipInvoice;
use App\Models\User;
use App\Services\Membership\MembershipInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UserMembershipInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $perPage = min(max((int) $request->get('per_page', 20), 1), 50);

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
                    'membershipOrder:id,order_number,plan_id,total_amount,payment_status,order_status',
                    'membershipOrder.plan:id,name,slug',
                    'addonOrder:id,order_number,addon_id,total_amount,payment_status,order_status',
                    'addonOrder.addon:id,name,slug',
                ])
                ->where('user_id', $user->id)
                ->when($request->filled('type'), function ($query) use ($request) {
                    if ($request->type === 'membership') {
                        $query->whereNotNull('membership_order_id');
                    }

                    if ($request->type === 'addon') {
                        $query->whereNotNull('addon_order_id');
                    }
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
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership invoices.', $e);
        }
    }

    public function show(Request $request, MembershipInvoice $invoice): JsonResponse
    {
        try {
            $user = $this->authenticatedUserOrFail($request);

            if ((int) $invoice->user_id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invoice not found.',
                ], 404);
            }

            $invoice->loadMissing([
                'membershipOrder.plan',
                'addonOrder.addon',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Membership invoice fetched successfully.',
                'data' => new MembershipInvoiceResource($invoice),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership invoice.', $e);
        }
    }

    public function download(
        Request $request,
        MembershipInvoice $invoice,
        MembershipInvoiceService $invoiceService
    ): JsonResponse|BinaryFileResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            if ((int) $invoice->user_id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invoice not found.',
                ], 404);
            }

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

        if ($invoice->membership_order_id && $invoice->membershipOrder) {
            return $invoiceService->generateForMembershipOrder($invoice->membershipOrder);
        }

        if ($invoice->addon_order_id && $invoice->addonOrder) {
            return $invoiceService->generateForAddonOrder($invoice->addonOrder);
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
                'auth' => ['Admin token is not allowed for frontend invoice API.'],
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