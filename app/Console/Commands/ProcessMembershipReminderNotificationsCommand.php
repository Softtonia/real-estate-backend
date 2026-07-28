<?php

namespace App\Console\Commands;

use App\Services\Membership\MembershipNotificationService;
use Illuminate\Console\Command;
use Throwable;

class ProcessMembershipReminderNotificationsCommand extends Command
{
    protected $signature = 'membership:process-reminders
        {--days=7 : Days before expiry}
        {--expired : Create notifications for memberships expired today}';

    protected $description = 'Create membership expiry reminder notifications.';

    public function handle(MembershipNotificationService $notificationService): int
    {
        try {
            $days = max((int) $this->option('days'), 1);

            $reminderCount = $notificationService->createExpiryReminders($days);

            $expiredCount = 0;

            if ((bool) $this->option('expired')) {
                $expiredCount = $notificationService->createExpiredMembershipNotifications();
            }

            $this->table(
                ['Task', 'Created'],
                [
                    ["Expiry reminders {$days} days before expiry", $reminderCount],
                    ['Expired membership notifications', $expiredCount],
                ]
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            report($e);

            return self::FAILURE;
        }
    }
}