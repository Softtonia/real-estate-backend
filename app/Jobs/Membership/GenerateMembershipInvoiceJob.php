<?php

namespace App\Jobs\Membership;

use App\Models\Membership\MembershipAddonOrder;
use App\Models\Membership\MembershipOrder;
use App\Services\Membership\MembershipInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateMembershipInvoiceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public ?int $membershipOrderId = null,
        public ?int $addonOrderId = null
    ) {
        $this->onConnection('redis');
        $this->onQueue('membership');
    }

    public function handle(MembershipInvoiceService $invoiceService): void
    {
        if ($this->membershipOrderId) {
            $order = MembershipOrder::query()->findOrFail($this->membershipOrderId);

            $invoiceService->generateForMembershipOrder($order);

            return;
        }

        if ($this->addonOrderId) {
            $order = MembershipAddonOrder::query()->findOrFail($this->addonOrderId);

            $invoiceService->generateForAddonOrder($order);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Membership invoice generation failed.', [
            'membership_order_id' => $this->membershipOrderId,
            'addon_order_id' => $this->addonOrderId,
            'error' => $exception->getMessage(),
        ]);
    }
}