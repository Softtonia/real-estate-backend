<?php

namespace App\Jobs\Membership;

use App\Models\Membership\MembershipWebhookEvent;
use App\Services\Membership\MembershipWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessRazorpayWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $webhookEventId
    ) {
        $this->onConnection('redis');
        $this->onQueue('membership');
    }

    public function handle(MembershipWebhookService $webhookService): void
    {
        $event = MembershipWebhookEvent::query()->findOrFail($this->webhookEventId);

        $webhookService->processStoredEvent($event);
    }
}