<?php

namespace App\Console\Commands;

use App\Models\Membership\MembershipOrder;
use App\Services\Membership\MembershipActivationService;
use Illuminate\Console\Command;

class MembershipActivatePaidOrdersCommand extends Command
{
    protected $signature = 'membership:activate-paid-orders
        {--user_id= : Activate only one user paid orders}
        {--order_id= : Activate only one order}
        {--limit=500 : Maximum orders to process}';

    protected $description = 'Activate memberships and credits for paid completed membership orders.';

    public function handle(MembershipActivationService $activationService): int
    {
        $limit = max(1, min((int) $this->option('limit'), 5000));

        $query = MembershipOrder::query()
            ->whereIn('payment_status', [
                MembershipOrder::PAYMENT_PAID,
                'paid',
                'success',
                'successful',
                'completed',
                'captured',
            ])
            ->with([
                'user',
                'plan.category',
                'plan.planFeatures.feature',
                'membership',
            ]);

        if ($this->option('user_id')) {
            $query->where('user_id', (int) $this->option('user_id'));
        }

        if ($this->option('order_id')) {
            $query->where('id', (int) $this->option('order_id'));
        }

        $orders = $query
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            $this->warn('No paid membership orders found.');

            return self::SUCCESS;
        }

        $activated = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $membership = $activationService->activateFromOrder($order);

                $this->info("OK order_id={$order->id}, user_id={$order->user_id}, membership_id={$membership->id}");

                $activated++;
            } catch (\Throwable $e) {
                $failed++;

                $this->error("FAILED order_id={$order->id}: {$e->getMessage()}");

                report($e);
            }
        }

        $this->info("Done. Success={$activated}, Failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
