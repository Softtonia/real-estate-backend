<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAddonOrder;
use App\Models\Membership\MembershipInvoice;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\MembershipSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipInvoiceService
{
    public function generateForMembershipOrder(MembershipOrder $order): MembershipInvoice
    {
        $order = MembershipOrder::query()
            ->with(['user', 'plan'])
            ->findOrFail($order->id);

        if ($order->payment_status !== MembershipOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['Invoice can be generated only for paid membership orders.'],
            ]);
        }

        $existingInvoice = MembershipInvoice::query()
            ->where('membership_order_id', $order->id)
            ->first();

        if ($existingInvoice) {
            return $existingInvoice;
        }

        return DB::transaction(function () use ($order) {
            $invoice = MembershipInvoice::query()->create(
                $this->invoicePayload(
                    userId: (int) $order->user_id,
                    membershipOrderId: (int) $order->id,
                    addonOrderId: null,
                    currency: $order->currency ?: 'INR',
                    taxableAmount: (float) $order->taxable_amount,
                    gstPercentage: (float) $order->gst_percentage,
                    gstAmount: (float) $order->gst_amount,
                    totalAmount: (float) $order->total_amount,
                    billing: $order->metadata['billing'] ?? [],
                    metadata: [
                        'type' => 'membership_order',
                        'order_number' => $order->order_number,
                        'plan_id' => $order->plan_id,
                        'plan_name' => $order->plan?->name,
                    ]
                )
            );

            $this->generatePdfIfAvailable(
                invoice: $invoice,
                description: 'Membership Plan - ' . ($order->plan?->name ?? 'Plan'),
                userName: $this->userName($order->user)
            );

            Cache::store('redis')->forget('membership:admin:stats');

            return $invoice->fresh();
        });
    }

    public function generateForAddonOrder(MembershipAddonOrder $order): MembershipInvoice
    {
        $order = MembershipAddonOrder::query()
            ->with(['user', 'addon'])
            ->findOrFail($order->id);

        if ($order->payment_status !== MembershipAddonOrder::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'order_id' => ['Invoice can be generated only for paid add-on orders.'],
            ]);
        }

        $existingInvoice = MembershipInvoice::query()
            ->where('addon_order_id', $order->id)
            ->first();

        if ($existingInvoice) {
            return $existingInvoice;
        }

        return DB::transaction(function () use ($order) {
            $invoice = MembershipInvoice::query()->create(
                $this->invoicePayload(
                    userId: (int) $order->user_id,
                    membershipOrderId: null,
                    addonOrderId: (int) $order->id,
                    currency: $order->currency ?: 'INR',
                    taxableAmount: (float) $order->taxable_amount,
                    gstPercentage: (float) $order->gst_percentage,
                    gstAmount: (float) $order->gst_amount,
                    totalAmount: (float) $order->total_amount,
                    billing: $order->metadata['billing'] ?? [],
                    metadata: [
                        'type' => 'membership_addon_order',
                        'order_number' => $order->order_number,
                        'addon_id' => $order->addon_id,
                        'addon_name' => $order->addon?->name,
                    ]
                )
            );

            $this->generatePdfIfAvailable(
                invoice: $invoice,
                description: 'Membership Add-on - ' . ($order->addon?->name ?? 'Add-on'),
                userName: $this->userName($order->user)
            );

            Cache::store('redis')->forget('membership:admin:stats');

            return $invoice->fresh();
        });
    }

    private function invoicePayload(
        int $userId,
        ?int $membershipOrderId,
        ?int $addonOrderId,
        string $currency,
        float $taxableAmount,
        float $gstPercentage,
        float $gstAmount,
        float $totalAmount,
        array $billing,
        array $metadata
    ): array {
        $gstSplit = $this->gstSplit($gstAmount, $billing);

        return [
            'membership_order_id' => $membershipOrderId,
            'addon_order_id' => $addonOrderId,
            'user_id' => $userId,

            'invoice_number' => $this->generateInvoiceNumber(),
            'invoice_date' => now(),

            'currency' => $currency,
            'taxable_amount' => round($taxableAmount, 2),
            'gst_percentage' => round($gstPercentage, 2),
            'cgst_amount' => $gstSplit['cgst_amount'],
            'sgst_amount' => $gstSplit['sgst_amount'],
            'igst_amount' => $gstSplit['igst_amount'],
            'gst_amount' => round($gstAmount, 2),
            'total_amount' => round($totalAmount, 2),

            'billing_name' => $billing['name'] ?? null,
            'billing_email' => $billing['email'] ?? null,
            'billing_phone' => $billing['phone'] ?? null,
            'billing_gst_number' => $billing['gst_number'] ?? null,
            'billing_address' => $billing['address'] ?? null,
            'billing_city' => $billing['city'] ?? null,
            'billing_state' => $billing['state'] ?? null,
            'billing_country' => $billing['country'] ?? 'India',
            'billing_pincode' => $billing['pincode'] ?? null,

            'invoice_pdf_disk' => null,
            'invoice_pdf_path' => null,
            'status' => 'generated',

            'metadata' => $metadata,
        ];
    }

    private function gstSplit(float $gstAmount, array $billing): array
    {
        $businessState = strtolower(trim((string) $this->settingValue('business_state', '')));
        $billingState = strtolower(trim((string) ($billing['state'] ?? '')));

        $isInterState = $businessState !== ''
            && $billingState !== ''
            && $businessState !== $billingState;

        if ($isInterState) {
            return [
                'cgst_amount' => 0,
                'sgst_amount' => 0,
                'igst_amount' => round($gstAmount, 2),
            ];
        }

        $half = round($gstAmount / 2, 2);

        return [
            'cgst_amount' => $half,
            'sgst_amount' => round($gstAmount - $half, 2),
            'igst_amount' => 0,
        ];
    }

    private function generatePdfIfAvailable(
        MembershipInvoice $invoice,
        string $description,
        string $userName
    ): void {
        if (!view()->exists('membership.invoices.invoice')) {
            return;
        }

        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return;
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('membership.invoices.invoice', [
            'invoice' => $invoice,
            'description' => $description,
            'userName' => $userName,
        ]);

        $path = 'membership/invoices/' . now()->format('Y/m') . '/' . $invoice->invoice_number . '.pdf';

        Storage::disk('local')->put($path, $pdf->output());

        $invoice->update([
            'invoice_pdf_disk' => 'local',
            'invoice_pdf_path' => $path,
        ]);
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = $this->settingValue('invoice_prefix', 'HPM');

        do {
            $number = $prefix . 'INV' . now()->format('YmdHis') . strtoupper(Str::random(5));
        } while (MembershipInvoice::query()->where('invoice_number', $number)->exists());

        return $number;
    }

    private function settingValue(string $key, mixed $default = null): mixed
    {
        if (!Schema::hasTable('membership_settings')) {
            return $default;
        }

        return Cache::store('redis')->remember("membership:setting:{$key}", 600, function () use ($key, $default) {
            $setting = MembershipSetting::query()
                ->where('key', $key)
                ->first();

            return $setting ? $setting->formattedValue() : $default;
        });
    }

    private function userName(?object $user): string
    {
        if (!$user) {
            return 'Customer';
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : ($user->email ?? 'Customer');
    }
}