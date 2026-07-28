<?php

namespace App\Console\Commands;

use App\Models\Membership\MembershipCreditBalance;
use App\Models\Membership\UserMembership;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipActivationService;
use App\Services\Membership\MembershipAddonOrderService;
use App\Services\Membership\MembershipOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcessMembershipExpirationsCommand extends Command
{
    protected $signature = 'membership:process-expirations
        {--order-minutes= : Expire pending orders older than given minutes}
        {--dry-run : Show counts without updating records}';

    protected $description = 'Process expired membership orders, add-on orders, memberships, and credits.';

    public function handle(
        MembershipOrderService $membershipOrderService,
        MembershipAddonOrderService $addonOrderService,
        MembershipActivationService $activationService,
        MembershipAccessService $accessService
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $orderMinutes = $this->option('order-minutes')
            ? (int) $this->option('order-minutes')
            : null;

        $this->info('Processing membership expirations...');

        try {
            $pendingMembershipOrders = $this->countPendingMembershipOrders($orderMinutes);
            $pendingAddonOrders = $this->countPendingAddonOrders($orderMinutes);
            $expiredMemberships = $this->countExpiredMemberships();
            $expiredCredits = $this->countExpiredCreditBalances();

            if ($dryRun) {
                $this->table(
                    ['Task', 'Count'],
                    [
                        ['Pending membership orders to expire', $pendingMembershipOrders],
                        ['Pending add-on orders to expire', $pendingAddonOrders],
                        ['Active memberships to expire', $expiredMemberships],
                        ['Credit balances to expire', $expiredCredits],
                    ]
                );

                $this->warn('Dry run only. No records were changed.');

                return self::SUCCESS;
            }

            $expiredMembershipOrderCount = $membershipOrderService->expirePendingOrders($orderMinutes);
            $expiredAddonOrderCount = $addonOrderService->expirePendingOrders($orderMinutes);
            $expiredMembershipCount = $this->expireMemberships($activationService, $accessService);
            $expiredCreditCount = $this->expireCreditBalances($accessService);

            $this->table(
                ['Task', 'Processed'],
                [
                    ['Expired pending membership orders', $expiredMembershipOrderCount],
                    ['Expired pending add-on orders', $expiredAddonOrderCount],
                    ['Expired active memberships', $expiredMembershipCount],
                    ['Expired credit balances', $expiredCreditCount],
                ]
            );

            Cache::store('redis')->increment('membership:reports:cache-version');
            Cache::store('redis')->forget('membership:admin:stats');

            $this->info('Membership expiration processing completed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            report($e);

            return self::FAILURE;
        }
    }

    private function countPendingMembershipOrders(?int $orderMinutes): int
    {
        $cutoff = now()->subMinutes(max($orderMinutes ?: 30, 1));

        return \App\Models\Membership\MembershipOrder::query()
            ->where('payment_status', \App\Models\Membership\MembershipOrder::PAYMENT_PENDING)
            ->whereIn('order_status', [
                \App\Models\Membership\MembershipOrder::STATUS_PENDING,
                \App\Models\Membership\MembershipOrder::STATUS_PROCESSING,
            ])
            ->where(function ($query) use ($cutoff) {
                if (Schema::hasColumn('membership_orders', 'expires_at')) {
                    $query->where('expires_at', '<=', now())
                        ->orWhere('created_at', '<=', $cutoff);

                    return;
                }

                $query->where('created_at', '<=', $cutoff);
            })
            ->count();
    }

    private function countPendingAddonOrders(?int $orderMinutes): int
    {
        $cutoff = now()->subMinutes(max($orderMinutes ?: 30, 1));

        return \App\Models\Membership\MembershipAddonOrder::query()
            ->where('payment_status', \App\Models\Membership\MembershipAddonOrder::PAYMENT_PENDING)
            ->whereIn('order_status', [
                \App\Models\Membership\MembershipAddonOrder::STATUS_PENDING,
                \App\Models\Membership\MembershipAddonOrder::STATUS_PROCESSING,
            ])
            ->where('created_at', '<=', $cutoff)
            ->count();
    }

    private function countExpiredMemberships(): int
    {
        return UserMembership::query()
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now())
            ->count();
    }

    private function countExpiredCreditBalances(): int
    {
        return MembershipCreditBalance::query()
            ->where('status', 'active')
            ->where('is_unlimited', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();
    }

    private function expireMemberships(
        MembershipActivationService $activationService,
        MembershipAccessService $accessService
    ): int {
        $count = 0;

        UserMembership::query()
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now())
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(100, function ($memberships) use (&$count, $activationService, $accessService) {
                foreach ($memberships as $membership) {
                    $freshMembership = UserMembership::query()->find($membership->id);

                    if (!$freshMembership || $freshMembership->status !== 'active') {
                        continue;
                    }

                    $activationService->expireMembership(
                        membership: $freshMembership,
                        performedBy: null
                    );

                    if ($freshMembership->user) {
                        $accessService->forgetUserCache($freshMembership->user);
                    }

                    $count++;
                }
            });

        return $count;
    }

    private function expireCreditBalances(MembershipAccessService $accessService): int
    {
        $count = 0;

        MembershipCreditBalance::query()
            ->where('status', 'active')
            ->where('is_unlimited', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(200, function ($balances) use (&$count, $accessService) {
                foreach ($balances as $balance) {
                    $freshBalance = MembershipCreditBalance::query()
                        ->where('id', $balance->id)
                        ->where('status', 'active')
                        ->first();

                    if (!$freshBalance) {
                        continue;
                    }

                    $freshBalance->update([
                        'status' => 'expired',
                    ]);

                    if ($freshBalance->user) {
                        $accessService->forgetUserCache($freshBalance->user);
                    }

                    $count++;
                }
            });

        return $count;
    }
}