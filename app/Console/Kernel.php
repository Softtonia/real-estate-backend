<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    protected $commands = [
        \App\Console\Commands\ProcessMembershipExpirationsCommand::class,
        \App\Console\Commands\ProcessMembershipReminderNotificationsCommand::class,
         \App\Console\Commands\MembershipHealthCheckCommand::class,
    ];
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run daily at 2:00 AM
        $schedule->command('app:clean')
            ->dailyAt('2:00')
            ->appendOutputTo(storage_path('logs/tokens_clean.log'));

        $schedule->command('api-security:cleanup')->hourly();
        $schedule->command('membership:process-expirations')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
        $schedule->command('membership:process-reminders --days=7')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('membership:process-reminders --days=3')
            ->dailyAt('09:15')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('membership:process-reminders --days=1')
            ->dailyAt('09:30')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('membership:process-reminders --expired')
            ->dailyAt('10:00')
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
