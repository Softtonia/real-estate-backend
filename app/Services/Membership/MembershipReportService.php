<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAddonOrder;
use App\Models\Membership\MembershipCreditTransaction;
use App\Models\Membership\MembershipInvoice;
use App\Models\Membership\MembershipOrder;
use App\Models\Membership\UserMembership;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MembershipReportService
{
    public function dashboard(array $filters = []): array
    {
        $filters = $this->dashboardFilters($filters);

        $cacheKey = 'membership:reports:dashboard:' . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($filters) {
            return [
                'period' => [
                    'date_from' => $filters['date_from'],
                    'date_to' => $filters['date_to'],
                    'group_by' => $filters['group_by'],
                ],

                'plans' => $this->safeDashboardStat(fn() => $this->dashboardPlanStats($filters)),
                'features' => $this->safeDashboardStat(fn() => $this->dashboardFeatureStats($filters)),
                'plan_features' => $this->safeDashboardStat(fn() => $this->dashboardPlanFeatureStats($filters)),
                'role_rules' => $this->safeDashboardStat(fn() => $this->dashboardRoleRuleStats($filters)),

                'orders' => $this->safeDashboardStat(fn() => $this->dashboardOrderStats($filters)),
                'user_memberships' => $this->safeDashboardStat(fn() => $this->dashboardUserMembershipStats($filters)),
                'transactions' => $this->safeDashboardStat(fn() => $this->dashboardTransactionStats($filters)),
                'credits' => $this->safeDashboardStat(fn() => $this->dashboardCreditStats($filters)),
                'coupons' => $this->safeDashboardStat(fn() => $this->dashboardCouponStats($filters)),
                'addons' => $this->safeDashboardStat(fn() => $this->dashboardAddonStats($filters)),
                'addon_orders' => $this->safeDashboardStat(fn() => $this->dashboardAddonOrderStats($filters)),

                'revenue' => $this->safeDashboardStat(fn() => $this->dashboardRevenueStats($filters)),

                'top_plans' => method_exists($this, 'topPlans')
                    ? $this->topPlans(array_merge($filters, ['limit' => 5]))
                    : [],

                'top_addons' => method_exists($this, 'topAddons')
                    ? $this->topAddons(array_merge($filters, ['limit' => 5]))
                    : [],

                'generated_at' => now()->toDateTimeString(),
            ];
        });
    }

    private function dashboardFilters(array $filters): array
    {
        return [
            'date_from' => ! empty($filters['date_from'])
                ? Carbon::parse($filters['date_from'])->toDateString()
                : now()->subDays(30)->toDateString(),

            'date_to' => ! empty($filters['date_to'])
                ? Carbon::parse($filters['date_to'])->toDateString()
                : now()->toDateString(),

            'group_by' => $filters['group_by'] ?? 'day',

            'plan_id' => $filters['plan_id'] ?? null,
            'addon_id' => $filters['addon_id'] ?? null,
            'user_id' => $filters['user_id'] ?? null,
            'limit' => $filters['limit'] ?? 10,
        ];
    }

    private function dashboardPlanStats(array $filters): array
    {
        if (! Schema::hasTable('membership_plans')) {
            return $this->missingTableStats('membership_plans');
        }

        $query = $this->dashboardQuery('membership_plans', $filters);

        $total = (clone $query)->count();

        $withFeatures = Schema::hasTable('membership_plan_features')
            ? (clone $query)->whereIn('id', function ($q) {
                $q->select('plan_id')->from('membership_plan_features');
            })->count()
            : 0;

        $withRoleRules = Schema::hasTable('membership_plan_role_rules')
            ? (clone $query)->whereIn('id', function ($q) {
                $q->select('plan_id')->from('membership_plan_role_rules');
            })->count()
            : 0;

        return [
            'available' => true,
            'total' => $total,
            'active' => Schema::hasColumn('membership_plans', 'status') ? (clone $query)->where('status', true)->count() : 0,
            'inactive' => Schema::hasColumn('membership_plans', 'status') ? (clone $query)->where('status', false)->count() : 0,
            'popular' => Schema::hasColumn('membership_plans', 'is_popular') ? (clone $query)->where('is_popular', true)->count() : 0,
            'with_features' => $withFeatures,
            'without_features' => max($total - $withFeatures, 0),
            'with_role_rules' => $withRoleRules,
            'without_role_rules' => max($total - $withRoleRules, 0),
        ];
    }

    private function dashboardFeatureStats(array $filters): array
    {
        if (! Schema::hasTable('membership_features')) {
            return $this->missingTableStats('membership_features');
        }

        $query = $this->dashboardQuery('membership_features', $filters);

        $total = (clone $query)->count();

        return [
            'available' => true,
            'total' => $total,
            'active' => Schema::hasColumn('membership_features', 'status') ? (clone $query)->where('status', true)->count() : 0,
            'inactive' => Schema::hasColumn('membership_features', 'status') ? (clone $query)->where('status', false)->count() : 0,
            'limit' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['limit']),
            'number' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['number']),
            'text' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['text']),
            'bool' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['bool', 'boolean']),
            'attached_to_plans' => Schema::hasTable('membership_plan_features')
                ? DB::table('membership_plan_features')->distinct()->count('feature_id')
                : 0,
            'not_attached_to_plans' => Schema::hasTable('membership_plan_features')
                ? max($total - DB::table('membership_plan_features')->distinct()->count('feature_id'), 0)
                : $total,
        ];
    }

    private function dashboardPlanFeatureStats(array $filters): array
    {
        if (! Schema::hasTable('membership_plan_features')) {
            return $this->missingTableStats('membership_plan_features');
        }

        $query = $this->dashboardQuery('membership_plan_features', $filters);

        return [
            'available' => true,
            'total_assignments' => (clone $query)->count(),
            'unlimited_assignments' => Schema::hasColumn('membership_plan_features', 'is_unlimited')
                ? (clone $query)->where('is_unlimited', true)->count()
                : 0,
            'limited_assignments' => Schema::hasColumn('membership_plan_features', 'is_unlimited')
                ? (clone $query)->where('is_unlimited', false)->count()
                : 0,
            'assigned_plans' => Schema::hasColumn('membership_plan_features', 'plan_id')
                ? (clone $query)->distinct()->count('plan_id')
                : 0,
            'assigned_features' => Schema::hasColumn('membership_plan_features', 'feature_id')
                ? (clone $query)->distinct()->count('feature_id')
                : 0,
        ];
    }

    private function dashboardRoleRuleStats(array $filters): array
    {
        if (! Schema::hasTable('membership_plan_role_rules')) {
            return $this->missingTableStats('membership_plan_role_rules');
        }

        $query = $this->dashboardQuery('membership_plan_role_rules', $filters);

        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'active' => Schema::hasColumn('membership_plan_role_rules', 'is_active')
                ? (clone $query)->where('is_active', true)->count()
                : 0,
            'inactive' => Schema::hasColumn('membership_plan_role_rules', 'is_active')
                ? (clone $query)->where('is_active', false)->count()
                : 0,
            'plans_with_rules' => Schema::hasColumn('membership_plan_role_rules', 'plan_id')
                ? (clone $query)->distinct()->count('plan_id')
                : 0,
            'roles_used' => Schema::hasColumn('membership_plan_role_rules', 'role_id')
                ? (clone $query)->distinct()->count('role_id')
                : 0,
        ];
    }

    private function dashboardOrderStats(array $filters): array
    {
        if (! Schema::hasTable('membership_orders')) {
            return $this->missingTableStats('membership_orders');
        }

        $table = 'membership_orders';
        $query = $this->dashboardQuery($table, $filters);
        $amountColumn = $this->firstColumn($table, ['total_amount', 'payable_amount', 'amount']);

        return [
            'available' => true,
            'total' => (clone $query)->count(),

            'status' => $this->statusSummary($table, $query, 'status'),
            'payment_status' => $this->statusSummary($table, $query, 'payment_status'),

            'total_amount' => $this->sumColumn($query, $amountColumn),
            'paid_amount' => $this->sumByStatus($table, $query, $amountColumn, ['paid', 'success', 'completed', 'captured']),
            'pending_amount' => $this->sumByStatus($table, $query, $amountColumn, ['pending', 'created', 'initiated']),
            'failed_amount' => $this->sumByStatus($table, $query, $amountColumn, ['failed', 'failure']),
            'cancelled_amount' => $this->sumByStatus($table, $query, $amountColumn, ['cancelled', 'canceled']),
        ];
    }

    private function dashboardUserMembershipStats(array $filters): array
    {
        if (! Schema::hasTable('user_memberships')) {
            return $this->missingTableStats('user_memberships');
        }

        $table = 'user_memberships';
        $query = $this->dashboardQuery($table, $filters);

        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'status' => $this->statusSummary($table, $query, 'status'),
            'active_users' => Schema::hasColumn($table, 'user_id')
                ? (clone $query)->where('status', 'active')->distinct()->count('user_id')
                : 0,
            'unique_users' => Schema::hasColumn($table, 'user_id')
                ? (clone $query)->distinct()->count('user_id')
                : 0,
            'expiring_soon_7_days' => Schema::hasColumn($table, 'ends_at')
                ? (clone $query)->whereBetween('ends_at', [now(), now()->addDays(7)])->count()
                : 0,
            'expiring_soon_30_days' => Schema::hasColumn($table, 'ends_at')
                ? (clone $query)->whereBetween('ends_at', [now(), now()->addDays(30)])->count()
                : 0,
        ];
    }

    private function dashboardTransactionStats(array $filters): array
    {
        $table = $this->firstExistingTable(['membership_transactions', 'membership_payments']);

        if (! $table) {
            return $this->missingTableStats('membership_transactions');
        }

        $query = $this->dashboardQuery($table, $filters);
        $amountColumn = $this->firstColumn($table, ['amount', 'total_amount', 'paid_amount']);

        return [
            'available' => true,
            'table' => $table,
            'total' => (clone $query)->count(),
            'status' => $this->statusSummary($table, $query, 'status'),
            'gateway' => $this->groupCount($query, $table, 'gateway'),
            'total_amount' => $this->sumColumn($query, $amountColumn),
            'success_amount' => $this->sumByStatus($table, $query, $amountColumn, ['success', 'paid', 'completed', 'captured']),
            'failed_amount' => $this->sumByStatus($table, $query, $amountColumn, ['failed', 'failure']),
            'refunded_amount' => $this->sumByStatus($table, $query, $amountColumn, ['refunded', 'refund']),
        ];
    }

    private function dashboardCreditStats(array $filters): array
    {
        $table = $this->firstExistingTable([
            'membership_user_credits',
            'membership_credits',
            'membership_feature_usages',
        ]);

        if (! $table) {
            return $this->missingTableStats('membership_credits');
        }

        $query = $this->dashboardQuery($table, $filters);

        return [
            'available' => true,
            'table' => $table,
            'total_records' => (clone $query)->count(),
            'unique_users' => Schema::hasColumn($table, 'user_id') ? (clone $query)->distinct()->count('user_id') : 0,
            'unique_features' => Schema::hasColumn($table, 'feature_id') ? (clone $query)->distinct()->count('feature_id') : 0,
            'allocated' => $this->sumFirstAvailableColumn($query, $table, ['allocated', 'allocated_count', 'total_credits', 'credit_limit', 'quantity']),
            'used' => $this->sumFirstAvailableColumn($query, $table, ['used', 'used_count', 'usage_count', 'consumed']),
            'remaining' => $this->sumFirstAvailableColumn($query, $table, ['remaining', 'remaining_count', 'available_credits', 'balance']),
            'unlimited' => Schema::hasColumn($table, 'is_unlimited') ? (clone $query)->where('is_unlimited', true)->count() : 0,
        ];
    }

    private function dashboardCouponStats(array $filters): array
    {
        if (! Schema::hasTable('membership_coupons')) {
            return $this->missingTableStats('membership_coupons');
        }

        $table = 'membership_coupons';
        $query = $this->dashboardQuery($table, $filters);

        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'active' => Schema::hasColumn($table, 'status') ? (clone $query)->where('status', true)->count() : 0,
            'inactive' => Schema::hasColumn($table, 'status') ? (clone $query)->where('status', false)->count() : 0,
            'expired' => Schema::hasColumn($table, 'expires_at') ? (clone $query)->where('expires_at', '<', now())->count() : 0,
            'percentage' => $this->countByColumnValue($query, $table, 'discount_type', ['percentage', 'percent']),
            'fixed' => $this->countByColumnValue($query, $table, 'discount_type', ['fixed', 'flat']),
            'used_count' => Schema::hasTable('membership_coupon_usages')
                ? DB::table('membership_coupon_usages')->count()
                : $this->sumFirstAvailableColumn($query, $table, ['used_count', 'usage_count']),
            'discount_given' => Schema::hasTable('membership_orders')
                ? round((float) DB::table('membership_orders')->sum('discount_amount'), 2)
                : 0.0,
        ];
    }

    private function dashboardAddonStats(array $filters): array
    {
        if (! Schema::hasTable('membership_addons')) {
            return $this->missingTableStats('membership_addons');
        }

        $table = 'membership_addons';
        $query = $this->dashboardQuery($table, $filters);

        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'active' => Schema::hasColumn($table, 'status') ? (clone $query)->where('status', true)->count() : 0,
            'inactive' => Schema::hasColumn($table, 'status') ? (clone $query)->where('status', false)->count() : 0,
            'total_sold' => Schema::hasTable('membership_addon_orders') ? DB::table('membership_addon_orders')->count() : 0,
            'total_revenue' => $this->addonRevenue(),
        ];
    }

    private function dashboardAddonOrderStats(array $filters): array
    {
        if (! Schema::hasTable('membership_addon_orders')) {
            return $this->missingTableStats('membership_addon_orders');
        }

        $table = 'membership_addon_orders';
        $query = $this->dashboardQuery($table, $filters);
        $amountColumn = $this->firstColumn($table, ['total_amount', 'payable_amount', 'amount']);

        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'status' => $this->statusSummary($table, $query, 'status'),
            'payment_status' => $this->statusSummary($table, $query, 'payment_status'),
            'total_amount' => $this->sumColumn($query, $amountColumn),
            'paid_amount' => $this->sumByStatus($table, $query, $amountColumn, ['paid', 'success', 'completed', 'captured']),
            'pending_amount' => $this->sumByStatus($table, $query, $amountColumn, ['pending', 'created', 'initiated']),
            'failed_amount' => $this->sumByStatus($table, $query, $amountColumn, ['failed', 'failure']),
        ];
    }

    private function dashboardRevenueStats(array $filters): array
    {
        $table = $this->firstExistingTable(['membership_transactions', 'membership_orders']);

        if (! $table) {
            return $this->missingTableStats('membership_revenue');
        }

        $amountColumn = $this->firstColumn($table, ['amount', 'total_amount', 'paid_amount', 'payable_amount']);

        if (! $amountColumn) {
            return [
                'available' => false,
                'reason' => 'No amount column found.',
            ];
        }

        $periodQuery = $this->dashboardQuery($table, $filters);
        $allTimeQuery = DB::table($table);

        $paidStatuses = ['paid', 'success', 'completed', 'captured'];

        return [
            'available' => true,
            'source_table' => $table,
            'period_revenue' => $this->sumByStatus($table, $periodQuery, $amountColumn, $paidStatuses),
            'all_time_revenue' => $this->sumByStatus($table, $allTimeQuery, $amountColumn, $paidStatuses),
            'today_revenue' => $this->revenueBetween($table, $amountColumn, now()->startOfDay(), now()->endOfDay()),
            'this_month_revenue' => $this->revenueBetween($table, $amountColumn, now()->startOfMonth(), now()->endOfMonth()),
            'failed_amount' => $this->sumByStatus($table, $periodQuery, $amountColumn, ['failed', 'failure']),
            'refunded_amount' => $this->sumByStatus($table, $periodQuery, $amountColumn, ['refunded', 'refund']),
        ];
    }

    private function dashboardQuery(string $table, array $filters): Builder
    {
        $query = DB::table($table);

        foreach (['user_id', 'plan_id', 'addon_id', 'feature_id', 'category_id'] as $filterColumn) {
            if (! empty($filters[$filterColumn]) && Schema::hasColumn($table, $filterColumn)) {
                $query->where($filterColumn, $filters[$filterColumn]);
            }
        }

        $dateColumn = $this->firstColumn($table, ['created_at', 'paid_at', 'updated_at']);

        if ($dateColumn) {
            $query->whereBetween($dateColumn, [
                Carbon::parse($filters['date_from'])->startOfDay(),
                Carbon::parse($filters['date_to'])->endOfDay(),
            ]);
        }

        return $query;
    }

    private function statusSummary(string $table, Builder $query, string $column): array
    {
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        return [
            'pending' => (clone $query)->whereIn($column, ['pending', 'created', 'initiated'])->count(),
            'paid' => (clone $query)->whereIn($column, ['paid', 'success', 'completed', 'captured'])->count(),
            'active' => (clone $query)->where($column, 'active')->count(),
            'expired' => (clone $query)->where($column, 'expired')->count(),
            'failed' => (clone $query)->whereIn($column, ['failed', 'failure'])->count(),
            'cancelled' => (clone $query)->whereIn($column, ['cancelled', 'canceled'])->count(),
            'refunded' => (clone $query)->whereIn($column, ['refunded', 'refund'])->count(),
        ];
    }

    private function sumByStatus(string $table, Builder $query, ?string $amountColumn, array $statuses): float
    {
        if (! $amountColumn) {
            return 0.0;
        }

        $statusColumn = null;

        if (Schema::hasColumn($table, 'payment_status')) {
            $statusColumn = 'payment_status';
        } elseif (Schema::hasColumn($table, 'status')) {
            $statusColumn = 'status';
        }

        if (! $statusColumn) {
            return 0.0;
        }

        return round((float) (clone $query)->whereIn($statusColumn, $statuses)->sum($amountColumn), 2);
    }

    private function revenueBetween(string $table, string $amountColumn, Carbon $from, Carbon $to): float
    {
        $dateColumn = $this->firstColumn($table, ['paid_at', 'created_at', 'updated_at']);

        if (! $dateColumn) {
            return 0.0;
        }

        $query = DB::table($table)->whereBetween($dateColumn, [$from, $to]);

        return $this->sumByStatus($table, $query, $amountColumn, ['paid', 'success', 'completed', 'captured']);
    }

    private function countByColumnValue(Builder $query, string $table, string $column, array $values): int
    {
        if (! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (clone $query)->whereIn($column, $values)->count();
    }

    private function groupCount(Builder $query, string $table, string $column): array
    {
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        return (clone $query)
            ->select($column, DB::raw('COUNT(*) as total'))
            ->whereNotNull($column)
            ->groupBy($column)
            ->pluck('total', $column)
            ->toArray();
    }

    private function sumColumn(Builder $query, ?string $column): float
    {
        if (! $column) {
            return 0.0;
        }

        return round((float) (clone $query)->sum($column), 2);
    }

    private function sumFirstAvailableColumn(Builder $query, string $table, array $columns): float
    {
        $column = $this->firstColumn($table, $columns);

        return $this->sumColumn($query, $column);
    }

    private function addonRevenue(): float
    {
        if (! Schema::hasTable('membership_addon_orders')) {
            return 0.0;
        }

        $table = 'membership_addon_orders';
        $amountColumn = $this->firstColumn($table, ['total_amount', 'payable_amount', 'amount']);

        if (! $amountColumn) {
            return 0.0;
        }

        return $this->sumByStatus($table, DB::table($table), $amountColumn, ['paid', 'success', 'completed', 'captured']);
    }

    private function firstExistingTable(array $tables): ?string
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function firstColumn(string $table, array $columns): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function missingTableStats(string $table): array
    {
        return [
            'available' => false,
            'table' => $table,
            'reason' => 'Table not found.',
        ];
    }

    private function safeDashboardStat(callable $callback): array
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return [
                'available' => false,
                'error' => config('app.debug') ? $e->getMessage() : 'Unable to calculate stats.',
            ];
        }
    }

    public function summary(array $filters = []): array
    {
        [$start, $end] = $this->dateRange($filters);

        $membershipOrders = MembershipOrder::query()
            ->where('payment_status', MembershipOrder::PAYMENT_PAID)
            ->whereRaw('COALESCE(paid_at, created_at) BETWEEN ? AND ?', [
                $start->toDateTimeString(),
                $end->toDateTimeString(),
            ])
            ->when(!empty($filters['plan_id']), fn($q) => $q->where('plan_id', (int) $filters['plan_id']))
            ->selectRaw('
                COUNT(*) as paid_orders,
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(taxable_amount), 0) as taxable_amount,
                COALESCE(SUM(gst_amount), 0) as gst_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        $addonOrders = MembershipAddonOrder::query()
            ->where('payment_status', MembershipAddonOrder::PAYMENT_PAID)
            ->whereRaw('COALESCE(paid_at, created_at) BETWEEN ? AND ?', [
                $start->toDateTimeString(),
                $end->toDateTimeString(),
            ])
            ->when(!empty($filters['addon_id']), fn($q) => $q->where('addon_id', (int) $filters['addon_id']))
            ->selectRaw('
                COUNT(*) as paid_orders,
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(taxable_amount), 0) as taxable_amount,
                COALESCE(SUM(gst_amount), 0) as gst_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        $invoiceTotals = MembershipInvoice::query()
            ->whereBetween('invoice_date', [$start, $end])
            ->selectRaw('
                COUNT(*) as total_invoices,
                COALESCE(SUM(total_amount), 0) as invoiced_amount
            ')
            ->first();

        return [
            'membership_orders' => [
                'paid_orders' => (int) $membershipOrders->paid_orders,
                'subtotal' => (float) $membershipOrders->subtotal,
                'discount_amount' => (float) $membershipOrders->discount_amount,
                'taxable_amount' => (float) $membershipOrders->taxable_amount,
                'gst_amount' => (float) $membershipOrders->gst_amount,
                'total_amount' => (float) $membershipOrders->total_amount,
            ],

            'addon_orders' => [
                'paid_orders' => (int) $addonOrders->paid_orders,
                'subtotal' => (float) $addonOrders->subtotal,
                'discount_amount' => (float) $addonOrders->discount_amount,
                'taxable_amount' => (float) $addonOrders->taxable_amount,
                'gst_amount' => (float) $addonOrders->gst_amount,
                'total_amount' => (float) $addonOrders->total_amount,
            ],

            'combined' => [
                'paid_orders' => (int) $membershipOrders->paid_orders + (int) $addonOrders->paid_orders,
                'subtotal' => round((float) $membershipOrders->subtotal + (float) $addonOrders->subtotal, 2),
                'discount_amount' => round((float) $membershipOrders->discount_amount + (float) $addonOrders->discount_amount, 2),
                'taxable_amount' => round((float) $membershipOrders->taxable_amount + (float) $addonOrders->taxable_amount, 2),
                'gst_amount' => round((float) $membershipOrders->gst_amount + (float) $addonOrders->gst_amount, 2),
                'total_amount' => round((float) $membershipOrders->total_amount + (float) $addonOrders->total_amount, 2),
            ],

            'invoices' => [
                'total_invoices' => (int) $invoiceTotals->total_invoices,
                'invoiced_amount' => (float) $invoiceTotals->invoiced_amount,
            ],
        ];
    }

    public function revenueTrend(array $filters = []): array
    {
        return Cache::store('redis')->remember(
            $this->cacheKey('revenue_trend', $filters),
            300,
            function () use ($filters) {
                [$start, $end] = $this->dateRange($filters);
                $groupBy = $filters['group_by'] ?? 'day';

                $periodExpression = $this->periodExpression($groupBy);

                $membershipRows = MembershipOrder::query()
                    ->where('payment_status', MembershipOrder::PAYMENT_PAID)
                    ->whereRaw('COALESCE(paid_at, created_at) BETWEEN ? AND ?', [
                        $start->toDateTimeString(),
                        $end->toDateTimeString(),
                    ])
                    ->when(!empty($filters['plan_id']), fn($q) => $q->where('plan_id', (int) $filters['plan_id']))
                    ->selectRaw("
                        {$periodExpression} as period,
                        COUNT(*) as membership_order_count,
                        COALESCE(SUM(total_amount), 0) as membership_revenue
                    ")
                    ->groupBy(DB::raw($periodExpression))
                    ->orderBy('period')
                    ->get();

                $addonRows = MembershipAddonOrder::query()
                    ->where('payment_status', MembershipAddonOrder::PAYMENT_PAID)
                    ->whereRaw('COALESCE(paid_at, created_at) BETWEEN ? AND ?', [
                        $start->toDateTimeString(),
                        $end->toDateTimeString(),
                    ])
                    ->when(!empty($filters['addon_id']), fn($q) => $q->where('addon_id', (int) $filters['addon_id']))
                    ->selectRaw("
                        {$periodExpression} as period,
                        COUNT(*) as addon_order_count,
                        COALESCE(SUM(total_amount), 0) as addon_revenue
                    ")
                    ->groupBy(DB::raw($periodExpression))
                    ->orderBy('period')
                    ->get();

                $trend = [];

                foreach ($membershipRows as $row) {
                    $period = (string) $row->period;

                    $trend[$period] ??= $this->emptyTrendRow($period);

                    $trend[$period]['membership_order_count'] = (int) $row->membership_order_count;
                    $trend[$period]['membership_revenue'] = (float) $row->membership_revenue;
                }

                foreach ($addonRows as $row) {
                    $period = (string) $row->period;

                    $trend[$period] ??= $this->emptyTrendRow($period);

                    $trend[$period]['addon_order_count'] = (int) $row->addon_order_count;
                    $trend[$period]['addon_revenue'] = (float) $row->addon_revenue;
                }

                foreach ($trend as $period => $row) {
                    $trend[$period]['total_order_count'] =
                        $row['membership_order_count'] + $row['addon_order_count'];

                    $trend[$period]['total_revenue'] = round(
                        $row['membership_revenue'] + $row['addon_revenue'],
                        2
                    );
                }

                ksort($trend);

                return array_values($trend);
            }
        );
    }

    public function topPlans(array $filters = []): array
    {
        [$start, $end] = $this->dateRange($filters);
        $limit = min(max((int) ($filters['limit'] ?? 10), 1), 50);

        return MembershipOrder::query()
            ->join('membership_plans', 'membership_orders.plan_id', '=', 'membership_plans.id')
            ->where('membership_orders.payment_status', MembershipOrder::PAYMENT_PAID)
            ->whereRaw('COALESCE(membership_orders.paid_at, membership_orders.created_at) BETWEEN ? AND ?', [
                $start->toDateTimeString(),
                $end->toDateTimeString(),
            ])
            ->selectRaw('
                membership_orders.plan_id,
                membership_plans.name as plan_name,
                membership_plans.slug as plan_slug,
                COUNT(*) as order_count,
                COALESCE(SUM(membership_orders.total_amount), 0) as revenue
            ')
            ->groupBy('membership_orders.plan_id', 'membership_plans.name', 'membership_plans.slug')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'plan_id' => (int) $row->plan_id,
                'plan_name' => $row->plan_name,
                'plan_slug' => $row->plan_slug,
                'order_count' => (int) $row->order_count,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();
    }

    public function topAddons(array $filters = []): array
    {
        [$start, $end] = $this->dateRange($filters);
        $limit = min(max((int) ($filters['limit'] ?? 10), 1), 50);

        return MembershipAddonOrder::query()
            ->join('membership_addons', 'membership_addon_orders.addon_id', '=', 'membership_addons.id')
            ->where('membership_addon_orders.payment_status', MembershipAddonOrder::PAYMENT_PAID)
            ->whereRaw('COALESCE(membership_addon_orders.paid_at, membership_addon_orders.created_at) BETWEEN ? AND ?', [
                $start->toDateTimeString(),
                $end->toDateTimeString(),
            ])
            ->selectRaw('
                membership_addon_orders.addon_id,
                membership_addons.name as addon_name,
                membership_addons.slug as addon_slug,
                membership_addons.addon_type,
                membership_addons.credit_type,
                COUNT(*) as order_count,
                COALESCE(SUM(membership_addon_orders.total_amount), 0) as revenue
            ')
            ->groupBy(
                'membership_addon_orders.addon_id',
                'membership_addons.name',
                'membership_addons.slug',
                'membership_addons.addon_type',
                'membership_addons.credit_type'
            )
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'addon_id' => (int) $row->addon_id,
                'addon_name' => $row->addon_name,
                'addon_slug' => $row->addon_slug,
                'addon_type' => $row->addon_type,
                'credit_type' => $row->credit_type,
                'order_count' => (int) $row->order_count,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();
    }

    public function creditUsage(array $filters = []): array
    {
        [$start, $end] = $this->dateRange($filters);

        return MembershipCreditTransaction::query()
            ->whereBetween('created_at', [$start, $end])
            ->when(!empty($filters['user_id']), fn($q) => $q->where('user_id', (int) $filters['user_id']))
            ->selectRaw('
                credit_type,
                COALESCE(SUM(CASE WHEN transaction_type = "credit" THEN quantity ELSE 0 END), 0) as credited,
                COALESCE(SUM(CASE WHEN transaction_type = "debit" THEN quantity ELSE 0 END), 0) as debited,
                COALESCE(SUM(CASE WHEN transaction_type = "refund" THEN quantity ELSE 0 END), 0) as refunded,
                COALESCE(SUM(CASE WHEN transaction_type = "adjust" THEN quantity ELSE 0 END), 0) as adjusted,
                COUNT(*) as transaction_count
            ')
            ->groupBy('credit_type')
            ->orderBy('credit_type')
            ->get()
            ->map(fn($row) => [
                'credit_type' => $row->credit_type,
                'credited' => (int) $row->credited,
                'debited' => (int) $row->debited,
                'refunded' => (int) $row->refunded,
                'adjusted' => (int) $row->adjusted,
                'transaction_count' => (int) $row->transaction_count,
            ])
            ->values()
            ->all();
    }

    public function membershipStatusCounts(): array
    {
        $statusRows = UserMembership::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $activeCount = UserMembership::query()
            ->active()
            ->count();

        return [
            'active' => (int) $activeCount,
            'all_active_status' => (int) ($statusRows['active'] ?? 0),
            'expired' => (int) ($statusRows['expired'] ?? 0),
            'cancelled' => (int) ($statusRows['cancelled'] ?? 0),
            'pending' => (int) ($statusRows['pending'] ?? 0),
            'total' => array_sum(array_map('intval', $statusRows)),
        ];
    }

    private function latestMembershipOrders(): array
    {
        return MembershipOrder::query()
            ->with(['user:id,first_name,last_name,email,phone', 'plan:id,name,slug'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn($order) => [
                'id' => (int) $order->id,
                'order_number' => $order->order_number,
                'user' => $this->userPayload($order->user),
                'plan' => $order->plan ? [
                    'id' => (int) $order->plan->id,
                    'name' => $order->plan->name,
                    'slug' => $order->plan->slug,
                ] : null,
                'total_amount' => (float) $order->total_amount,
                'payment_status' => $order->payment_status,
                'order_status' => $order->order_status,
                'created_at' => optional($order->created_at)->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function latestAddonOrders(): array
    {
        return MembershipAddonOrder::query()
            ->with(['user:id,first_name,last_name,email,phone', 'addon:id,name,slug,addon_type'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn($order) => [
                'id' => (int) $order->id,
                'order_number' => $order->order_number,
                'user' => $this->userPayload($order->user),
                'addon' => $order->addon ? [
                    'id' => (int) $order->addon->id,
                    'name' => $order->addon->name,
                    'slug' => $order->addon->slug,
                    'addon_type' => $order->addon->addon_type,
                ] : null,
                'total_amount' => (float) $order->total_amount,
                'payment_status' => $order->payment_status,
                'order_status' => $order->order_status,
                'created_at' => optional($order->created_at)->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function emptyTrendRow(string $period): array
    {
        return [
            'period' => $period,
            'membership_order_count' => 0,
            'addon_order_count' => 0,
            'total_order_count' => 0,
            'membership_revenue' => 0,
            'addon_revenue' => 0,
            'total_revenue' => 0,
        ];
    }

    private function period(array $filters): array
    {
        [$start, $end] = $this->dateRange($filters);

        return [
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'group_by' => $filters['group_by'] ?? 'day',
        ];
    }

    private function dateRange(array $filters): array
    {
        $start = !empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(30)->startOfDay();

        $end = !empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        return [$start, $end];
    }

    private function periodExpression(string $groupBy): string
    {
        return match ($groupBy) {
            'month' => "DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m')",
            'week' => "DATE_FORMAT(COALESCE(paid_at, created_at), '%x-W%v')",
            default => "DATE(COALESCE(paid_at, created_at))",
        };
    }

    private function cacheKey(string $type, array $filters): string
    {
        $version = Cache::store('redis')->get('membership:reports:cache-version', 1);

        return 'membership:reports:' . $version . ':' . $type . ':' . md5(json_encode($filters));
    }

    private function userPayload(?object $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'email' => $user->email,
            'phone' => $user->phone,
        ];
    }
}
