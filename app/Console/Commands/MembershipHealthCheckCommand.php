<?php

namespace App\Console\Commands;

use App\Models\Membership\MembershipAddon;
use App\Models\Membership\MembershipCategory;
use App\Models\Membership\MembershipFeature;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MembershipHealthCheckCommand extends Command
{
    protected $signature = 'membership:health-check';

    protected $description = 'Run health checks for the membership module.';

    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $this->info('Running membership module health check...');
        $this->newLine();

        $this->checkTables();
        $this->checkRedis();
        $this->checkQueue();
        $this->checkRazorpayConfig();
        $this->checkSettings();
        $this->checkRoutes();
        $this->checkPdfPackage();
        $this->checkCommands();
        $this->checkSeedData();

        $this->newLine();

        $this->table(
            ['Status', 'Count'],
            [
                ['Passed', $this->passed],
                ['Warnings', $this->warnings],
                ['Failed', $this->failed],
            ]
        );

        if ($this->failed > 0) {
            $this->error('Membership health check completed with failures.');

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn('Membership health check completed with warnings.');

            return self::SUCCESS;
        }

        $this->info('Membership health check completed successfully.');

        return self::SUCCESS;
    }

    private function checkTables(): void
    {
        $this->section('Database tables');

        $tables = [
            'membership_categories',
            'membership_features',
            'membership_plans',
            'membership_plan_features',
            'membership_plan_role_rules',
            'membership_coupons',
            'membership_orders',
            'user_memberships',
            'membership_addons',
            'membership_addon_orders',
            'membership_payments',
            'membership_webhook_events',
            'membership_refunds',
            'membership_coupon_usages',
            'membership_credit_balances',
            'membership_credit_transactions',
            'membership_lead_unlocks',
            'membership_addon_usages',
            'membership_renewals',
            'membership_plan_changes',
            'membership_invoices',
            'membership_notifications',
            'membership_settings',
            'membership_audit_logs',
            'membership_teams',
            'membership_team_members',
            'job_batches',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $this->pass("Table exists: {$table}");
            } else {
                $this->fail("Missing table: {$table}");
            }
        }
    }

    private function checkRedis(): void
    {
        $this->section('Redis cache');

        try {
            $key = 'membership:health-check:' . now()->timestamp;

            Cache::store('redis')->put($key, 'ok', 60);

            $value = Cache::store('redis')->get($key);

            Cache::store('redis')->forget($key);

            if ($value === 'ok') {
                $this->pass('Redis cache read/write working.');
                return;
            }

            $this->fail('Redis cache write/read mismatch.');
        } catch (Throwable $e) {
            $this->fail('Redis cache failed: ' . $e->getMessage());
        }
    }

    private function checkQueue(): void
    {
        $this->section('Queue');

        $defaultQueue = config('queue.default');

        if ($defaultQueue === 'redis') {
            $this->pass('Default queue driver is redis.');
        } else {
            $this->warn("Default queue driver is {$defaultQueue}. Expected redis.");
        }

        if (config('queue.connections.redis')) {
            $this->pass('Redis queue connection is configured.');
        } else {
            $this->fail('Redis queue connection is missing.');
        }
    }

    private function checkRazorpayConfig(): void
    {
        $this->section('Razorpay');

        $keyId = config('services.razorpay.key_id');
        $keySecret = config('services.razorpay.key_secret');
        $webhookSecret = config('services.razorpay.webhook_secret');

        $keyId ? $this->pass('Razorpay key_id configured.') : $this->fail('Missing Razorpay key_id.');
        $keySecret ? $this->pass('Razorpay key_secret configured.') : $this->fail('Missing Razorpay key_secret.');
        $webhookSecret ? $this->pass('Razorpay webhook_secret configured.') : $this->warn('Missing Razorpay webhook_secret.');
    }

    private function checkSettings(): void
    {
        $this->section('Membership settings');

        if (!Schema::hasTable('membership_settings')) {
            $this->fail('membership_settings table missing.');

            return;
        }

        $requiredSettings = [
            'gst_percentage',
            'order_expiry_minutes',
            'order_prefix',
            'addon_order_prefix',
            'invoice_prefix',
            'business_state',
        ];

        foreach ($requiredSettings as $key) {
            $exists = MembershipSetting::query()
                ->where('key', $key)
                ->exists();

            if ($exists) {
                $this->pass("Setting exists: {$key}");
            } else {
                $this->warn("Missing setting: {$key}");
            }
        }
    }

    private function checkRoutes(): void
    {
        $this->section('Routes');

        $requiredUris = [
            'api/membership/plans',
            'api/membership/my-status',
            'api/membership/orders',
            'api/membership/payments/verify',
            'api/membership/addons',
            'api/membership/addon-orders',
            'api/membership/addon-payments/verify',
            'api/membership/invoices',
            'api/membership/notifications',
            'api/membership/leads/unlock',
            'api/membership/features/consume',

            'api/membership/webhooks/razorpay',

            'api/admin/membership/plans',
            'api/admin/membership/orders',
            'api/admin/membership/payments',
            'api/admin/membership/users',
            'api/admin/membership/credits',
            'api/admin/membership/coupons',
            'api/admin/membership/addons',
            'api/admin/membership/addon-orders',
            'api/admin/membership/invoices',
            'api/admin/membership/settings',
            'api/admin/membership/reports/dashboard',
            'api/admin/membership/refunds',
            'api/admin/membership/audit-logs',
        ];

        $routes = collect(Route::getRoutes())->map(function ($route) {
            return trim($route->uri(), '/');
        });

        foreach ($requiredUris as $uri) {
            if ($routes->contains(trim($uri, '/'))) {
                $this->pass("Route exists: {$uri}");
            } else {
                $this->warn("Route not found exactly: {$uri}");
            }
        }
    }

    private function checkPdfPackage(): void
    {
        $this->section('Invoice PDF');

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $this->pass('Dompdf package is installed.');
        } else {
            $this->warn('Dompdf package not found. Invoice rows will work, but PDF generation may be skipped.');
        }

        if (view()->exists('membership.invoices.invoice')) {
            $this->pass('Invoice Blade view exists.');
        } else {
            $this->warn('Invoice Blade view missing: membership.invoices.invoice');
        }
    }

    private function checkCommands(): void
    {
        $this->section('Artisan commands');

        $commands = Artisan::all();

        foreach ([
            'membership:process-expirations',
            'membership:process-reminders',
            'membership:health-check',
        ] as $command) {
            if (array_key_exists($command, $commands)) {
                $this->pass("Command registered: {$command}");
            } else {
                $this->fail("Command missing: {$command}");
            }
        }
    }

    private function checkSeedData(): void
    {
        $this->section('Seed data');

        $this->countCheck(
            label: 'Membership categories',
            count: Schema::hasTable('membership_categories') ? MembershipCategory::query()->count() : 0
        );

        $this->countCheck(
            label: 'Membership features',
            count: Schema::hasTable('membership_features') ? MembershipFeature::query()->count() : 0
        );

        $this->countCheck(
            label: 'Membership plans',
            count: Schema::hasTable('membership_plans') ? MembershipPlan::query()->count() : 0
        );

        $this->countCheck(
            label: 'Membership add-ons',
            count: Schema::hasTable('membership_addons') ? MembershipAddon::query()->count() : 0,
            warningOnly: true
        );
    }

    private function countCheck(string $label, int $count, bool $warningOnly = false): void
    {
        if ($count > 0) {
            $this->pass("{$label}: {$count}");
            return;
        }

        if ($warningOnly) {
            $this->warn("{$label}: {$count}");
            return;
        }

        $this->fail("{$label}: {$count}");
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment>");
    }

    private function pass(string $message): void
    {
        $this->passed++;
        $this->line("<info>✓</info> {$message}");
    }

    private function warn(string $message): void
    {
        $this->warnings++;
        $this->line("<comment>!</comment> {$message}");
    }

    private function fail(string $message): void
    {
        $this->failed++;
        $this->line("<error>✗</error> {$message}");
    }
}