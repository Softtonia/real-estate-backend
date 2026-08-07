<?php

namespace App\Services\Membership;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MembershipReportService
{
    public function dashboard(array $filters = []): array
    {
        $filters = $this->filters($filters);

        $cacheKey = 'membership:reports:dashboard:' . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($filters) {
            return [
                'period' => [
                    'date_from' => $filters['date_from'],
                    'date_to' => $filters['date_to'],
                    'group_by' => $filters['group_by'],
                ],

                'plans' => $this->safe(fn () => $this->dashboardPlanStats($filters)),
                'features' => $this->safe(fn () => $this->dashboardFeatureStats($filters)),
                'plan_features' => $this->safe(fn () => $this->dashboardPlanFeatureStats($filters)),
                'role_rules' => $this->safe(fn () => $this->dashboardRoleRuleStats($filters)),
                'orders' => $this->safe(fn () => $this->dashboardOrderStats($filters)),
                'user_memberships' => $this->safe(fn () => $this->dashboardUserMembershipStats($filters)),
                'transactions' => $this->safe(fn () => $this->dashboardPaymentStats($filters)),
                'credits' => $this->safe(fn () => $this->dashboardCreditStats($filters)),
                'credit_transactions' => $this->safe(fn () => $this->dashboardCreditTransactionStats($filters)),
                'coupons' => $this->safe(fn () => $this->dashboardCouponStats($filters)),
                'addons' => $this->safe(fn () => $this->dashboardAddonStats($filters)),
                'addon_orders' => $this->safe(fn () => $this->dashboardAddonOrderStats($filters)),
                'revenue' => $this->safe(fn () => $this->dashboardRevenueStats($filters)),

                'generated_at' => now()->toDateTimeString(),
            ];
        });
    }

    public function summary(array $filters = []): array
    {
        $filters = $this->filters($filters);

        return [
            'orders' => $this->safe(fn () => $this->dashboardOrderStats($filters)),
            'payments' => $this->safe(fn () => $this->dashboardPaymentStats($filters)),
            'memberships' => $this->safe(fn () => $this->dashboardUserMembershipStats($filters)),
            'revenue' => $this->safe(fn () => $this->dashboardRevenueStats($filters)),
        ];
    }

    public function revenueTrend(array $filters = []): array
    {
        $filters = $this->filters($filters);

        $table = $this->firstExistingTable(['membership_payments', 'membership_orders']);

        if (! $table) {
            return [];
        }

        $amountColumn = $this->firstColumn($table, ['amount', 'total_amount', 'paid_amount', 'payable_amount']);

        if (! $amountColumn) {
            return [];
        }

        $dateColumn = $this->firstColumn($table, ['payment_date', 'paid_at', 'created_at']);

        if (! $dateColumn) {
            return [];
        }

        $statusColumn = $this->firstColumn($table, ['payment_status', 'order_status', 'status']);

        $query = DB::table($table)
            ->whereBetween($dateColumn, [
                Carbon::parse($filters['date_from'])->startOfDay(),
                Carbon::parse($filters['date_to'])->endOfDay(),
            ]);

        if ($statusColumn) {
            $query->whereIn($statusColumn, $this->paidStatuses());
        }

        foreach (['user_id', 'plan_id', 'addon_id'] as $column) {
            if (! empty($filters[$column]) && Schema::hasColumn($table, $column)) {
                $query->where($column, (int) $filters[$column]);
            }
        }

        $dateExpression = $this->dateGroupExpression($dateColumn, $filters['group_by']);

        return $query
            ->selectRaw("{$dateExpression} as period")
            ->selectRaw('COUNT(*) as total_transactions')
            ->selectRaw("COALESCE(SUM({$amountColumn}), 0) as revenue")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'total_transactions' => (int) $row->total_transactions,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values()
            ->all();
    }

    public function creditUsage(array $filters = []): array
    {
        $filters = $this->filters($filters);

        return [
            'balances' => $this->safe(fn () => $this->dashboardCreditStats($filters)),
            'transactions' => $this->safe(fn () => $this->dashboardCreditTransactionStats($filters)),
        ];
    }

    public function topPlans(array $filters = []): array
    {
        $filters = $this->filters($filters);
        $limit = min(max((int) ($filters['limit'] ?? 10), 1), 50);

        if (! Schema::hasTable('membership_plans') || ! Schema::hasTable('membership_orders')) {
            return [];
        }

        $amountColumn = $this->firstColumn('membership_orders', ['total_amount', 'payable_amount', 'amount']);

        if (! $amountColumn) {
            return [];
        }

        $dateColumn = $this->firstColumn('membership_orders', ['paid_at', 'created_at']);

        $query = DB::table('membership_plans as p')
            ->leftJoin('membership_orders as o', function ($join) use ($dateColumn, $filters) {
                $join->on('o.plan_id', '=', 'p.id');

                if ($dateColumn) {
                    $join->whereBetween("o.{$dateColumn}", [
                        Carbon::parse($filters['date_from'])->startOfDay(),
                        Carbon::parse($filters['date_to'])->endOfDay(),
                    ]);
                }
            })
            ->select([
                'p.id',
                'p.name',
                'p.slug',
                'p.currency',
            ])
            ->selectRaw('COUNT(o.id) as total_orders')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN o.payment_status IN (?, ?, ?, ?, ?) THEN o.{$amountColumn} ELSE 0 END), 0) as revenue",
                $this->paidStatuses()
            )
            ->groupBy('p.id', 'p.name', 'p.slug', 'p.currency')
            ->orderByDesc('revenue')
            ->limit($limit);

        return $query->get()->map(fn ($row) => [
            'plan_id' => (int) $row->id,
            'plan_name' => $row->name,
            'plan_slug' => $row->slug,
            'currency' => $row->currency ?: 'INR',
            'total_orders' => (int) $row->total_orders,
            'revenue' => round((float) $row->revenue, 2),
        ])->values()->all();
    }

    public function topAddons(array $filters = []): array
    {
        $filters = $this->filters($filters);
        $limit = min(max((int) ($filters['limit'] ?? 10), 1), 50);

        if (! Schema::hasTable('membership_addons') || ! Schema::hasTable('membership_addon_orders')) {
            return [];
        }

        $amountColumn = $this->firstColumn('membership_addon_orders', ['total_amount', 'payable_amount', 'amount']);

        if (! $amountColumn) {
            return [];
        }

        $dateColumn = $this->firstColumn('membership_addon_orders', ['paid_at', 'created_at']);

        $query = DB::table('membership_addons as a')
            ->leftJoin('membership_addon_orders as o', function ($join) use ($dateColumn, $filters) {
                $join->on('o.addon_id', '=', 'a.id');

                if ($dateColumn) {
                    $join->whereBetween("o.{$dateColumn}", [
                        Carbon::parse($filters['date_from'])->startOfDay(),
                        Carbon::parse($filters['date_to'])->endOfDay(),
                    ]);
                }
            })
            ->select([
                'a.id',
                'a.name',
                'a.slug',
                'a.currency',
            ])
            ->selectRaw('COUNT(o.id) as total_orders')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN o.payment_status IN (?, ?, ?, ?, ?) THEN o.{$amountColumn} ELSE 0 END), 0) as revenue",
                $this->paidStatuses()
            )
            ->groupBy('a.id', 'a.name', 'a.slug', 'a.currency')
            ->orderByDesc('revenue')
            ->limit($limit);

        return $query->get()->map(fn ($row) => [
            'addon_id' => (int) $row->id,
            'addon_name' => $row->name,
            'addon_slug' => $row->slug,
            'currency' => $row->currency ?: 'INR',
            'total_orders' => (int) $row->total_orders,
            'revenue' => round((float) $row->revenue, 2),
        ])->values()->all();
    }

    private function dashboardPlanStats(array $filters): array
    {
        if (! Schema::hasTable('membership_plans')) {
            return $this->missingTableStats('membership_plans');
        }

        $query = $this->dashboardQuery('membership_plans', $filters);
        $total = (clone $query)->count();

        $withFeatures = Schema::hasTable('membership_plan_features')
            ? (clone $query)->whereIn('id', fn ($q) => $q->select('plan_id')->from('membership_plan_features'))->count()
            : 0;

        $withRoleRules = Schema::hasTable('membership_plan_role_rules')
            ? (clone $query)->whereIn('id', fn ($q) => $q->select('plan_id')->from('membership_plan_role_rules'))->count()
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
            'total_revenue' => $this->planTotalRevenue($filters),
        ];
    }

    private function dashboardFeatureStats(array $filters): array
    {
        if (! Schema::hasTable('membership_features')) {
            return $this->missingTableStats('membership_features');
        }

        $query = $this->dashboardQuery('membership_features', $filters);

        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'active' => Schema::hasColumn('membership_features', 'status') ? (clone $query)->where('status', true)->count() : 0,
            'inactive' => Schema::hasColumn('membership_features', 'status') ? (clone $query)->where('status', false)->count() : 0,
            'limit' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['limit']),
            'number' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['number']),
            'text' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['text']),
            'bool' => $this->countByColumnValue($query, 'membership_features', 'feature_type', ['bool', 'boolean']),
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
            'status' => $this->statusSummary($table, $query, 'order_status'),
            'payment_status' => $this->statusSummary($table, $query, 'payment_status'),
            'total_amount' => $this->sumColumn($query, $amountColumn),
            'paid_amount' => $this->sumByStatus($table, $query, $amountColumn, $this->paidStatuses()),
            'pending_amount' => $this->sumByStatus($table, $query, $amountColumn, $this->pendingStatuses()),
            'failed_amount' => $this->sumByStatus($table, $query, $amountColumn, $this->failedStatuses()),
            'cancelled_amount' => $this->sumByStatus($table, $query, $amountColumn, $this->cancelledStatuses()),
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
            'active' => Schema::hasColumn($table, 'status') ? (clone $query)->where('status', 'active')->count() : 0,
            'expired' => Schema::hasColumn($table, 'status') ? (clone $query)->where('status', 'expired')->count() : 0,
            'cancelled' => Schema::hasColumn($table, 'status') ? (clone $query)->whereIn('status', ['cancelled', 'canceled'])->count() : 0,
            'unique_users' => Schema::hasColumn($table, 'user_id') ? (clone $query)->distinct()->count('user_id') : 0,
            'expiring_soon_7_days' => Schema::hasColumn($table, 'expiry_date')
                ? (clone $query)->whereBetween('expiry_date', [now(), now()->addDays(7)])->count()
                : 0,
            'expiring_soon_30_days' => Schema::hasColumn($table, 'expiry_date')
                ? (clone $query)->whereBetween('expiry_date', [now(), now()->addDays(30)])->count()
                : 0,
        ];
    }

    private function dashboardPaymentStats(array $filters): array
    {
        if (! Schema::hasTable('membership_payments')) {
            return $this->missingTableStats('membership_payments');
        }

        $table = 'membership_payments';
        $query = $this->dashboardQuery($table, $filters);
        $amountColumn = $this->firstColumn($table, ['amount', 'total_amount', 'paid_amount']);

        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'payment_status' => $this->statusSummary($table, $query, 'payment_status'),
            'gateway' => $this->groupCount($query, $table, 'payment_gateway'),
            'total_amount' => $this->sumColumn($query, $amountColumn),
            'success_amount' => $this->sumByStatus($table, $query, $amountColumn, $this->paidStatuses()),
            'failed_amount' => $this->sumByStatus($table, $query, $amountColumn, $this->failedStatuses()),
            'refunded_amount' => $this->sumByStatus($table, $query, $amountColumn, ['refunded', 'refund']),
        ];
    }

    private function dashboardCreditStats(array $filters): array
    {
        if (! Schema::hasTable('membership_credit_balances')) {
            return $this->missingTableStats('membership_credit_balances');
        }

        $table = 'membership_credit_balances';
        $query = $this->dashboardQuery($table, $filters);

        $activeQuery = (clone $query)
            ->where('status', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        $expiredQuery = (clone $query)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        return [
            'available' => true,
            'total_records' => (clone $query)->count(),

            'active' => (clone $activeQuery)->count(),
            'inactive' => (clone $query)->where('status', false)->count(),
            'expired' => (clone $expiredQuery)->count(),

            'unlimited' => (clone $query)->where('is_unlimited', true)->count(),
            'limited' => (clone $query)->where('is_unlimited', false)->count(),

            'unique_users' => (clone $query)->distinct()->count('user_id'),
            'unique_memberships' => (clone $query)->distinct()->count('membership_id'),
            'unique_credit_types' => (clone $query)->distinct()->count('credit_type'),

            'total_credits' => round((float) (clone $query)->where('is_unlimited', false)->sum('total_credits'), 2),
            'used_credits' => round((float) (clone $query)->sum('used_credits'), 2),
            'remaining_credits' => round((float) (clone $query)->where('is_unlimited', false)->sum('remaining_credits'), 2),

            'active_total_credits' => round((float) (clone $activeQuery)->where('is_unlimited', false)->sum('total_credits'), 2),
            'active_used_credits' => round((float) (clone $activeQuery)->sum('used_credits'), 2),
            'active_remaining_credits' => round((float) (clone $activeQuery)->where('is_unlimited', false)->sum('remaining_credits'), 2),

            'by_credit_type' => (clone $query)
                ->selectRaw('credit_type')
                ->selectRaw('COUNT(*) as total_records')
                ->selectRaw('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_records')
                ->selectRaw('SUM(CASE WHEN is_unlimited = 1 THEN 1 ELSE 0 END) as unlimited_records')
                ->selectRaw('SUM(CASE WHEN is_unlimited = 0 THEN total_credits ELSE 0 END) as total_credits')
                ->selectRaw('SUM(used_credits) as used_credits')
                ->selectRaw('SUM(CASE WHEN is_unlimited = 0 THEN remaining_credits ELSE 0 END) as remaining_credits')
                ->groupBy('credit_type')
                ->orderBy('credit_type')
                ->get()
                ->map(fn ($row) => [
                    'credit_type' => $row->credit_type,
                    'total_records' => (int) $row->total_records,
                    'active_records' => (int) $row->active_records,
                    'unlimited_records' => (int) $row->unlimited_records,
                    'total_credits' => (int) $row->total_credits,
                    'used_credits' => (int) $row->used_credits,
                    'remaining_credits' => (int) $row->remaining_credits,
                ])
                ->values(),
        ];
    }

    private function dashboardCreditTransactionStats(array $filters): array
    {
        if (! Schema::hasTable('membership_credit_transactions')) {
            return $this->missingTableStats('membership_credit_transactions');
        }

        $table = 'membership_credit_transactions';
        $query = $this->dashboardQuery($table, $filters);

        return [
            'available' => true,
            'total_transactions' => (clone $query)->count(),

            'credited_quantity' => (int) (clone $query)
                ->whereIn('transaction_type', ['credit', 'refund'])
                ->sum('quantity'),

            'debited_quantity' => (int) (clone $query)
                ->whereIn('transaction_type', ['debit', 'expire'])
                ->sum('quantity'),

            'adjust_quantity' => (int) (clone $query)
                ->where('transaction_type', 'adjust')
                ->sum('quantity'),

            'by_transaction_type' => $this->groupCount($query, $table, 'transaction_type'),
            'by_credit_type' => (clone $query)
                ->selectRaw('credit_type')
                ->selectRaw('COUNT(*) as total_transactions')
                ->selectRaw('SUM(quantity) as total_quantity')
                ->groupBy('credit_type')
                ->orderBy('credit_type')
                ->get()
                ->map(fn ($row) => [
                    'credit_type' => $row->credit_type,
                    'total_transactions' => (int) $row->total_transactions,
                    'total_quantity' => (int) $row->total_quantity,
                ])
                ->values(),
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
            'discount_given' => Schema::hasTable('membership_orders') && Schema::hasColumn('membership_orders', 'discount_amount')
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
            'status' => $this->statusSummary($table, $query, 'order_status'),
            'payment_status' => $this->statusSummary($table, $query, 'payment_status'),
            'total_amount' => $this->sumColumn($query, $amountColumn),
            'paid_amount' => $this->sumByStatus($table, $query, $amountColumn, $this->paidStatuses()),
        ];
    }

    private function dashboardRevenueStats(array $filters): array
    {
        $sourceTable = Schema::hasTable('membership_payments') ? 'membership_payments' : 'membership_orders';

        if (! Schema::hasTable($sourceTable)) {
            return $this->missingTableStats('membership_revenue');
        }

        $amountColumn = $this->firstColumn($sourceTable, ['amount', 'total_amount', 'paid_amount', 'payable_amount']);

        if (! $amountColumn) {
            return [
                'available' => false,
                'reason' => 'No amount column found.',
            ];
        }

        $periodQuery = $this->dashboardQuery($sourceTable, $filters);
        $allTimeQuery = DB::table($sourceTable);

        return [
            'available' => true,
            'source_table' => $sourceTable,
            'period_revenue' => $this->sumByStatus($sourceTable, $periodQuery, $amountColumn, $this->paidStatuses()),
            'all_time_revenue' => $this->sumByStatus($sourceTable, $allTimeQuery, $amountColumn, $this->paidStatuses()),
            'today_revenue' => $this->revenueBetween($sourceTable, $amountColumn, now()->startOfDay(), now()->endOfDay()),
            'this_month_revenue' => $this->revenueBetween($sourceTable, $amountColumn, now()->startOfMonth(), now()->endOfMonth()),
            'failed_amount' => $this->sumByStatus($sourceTable, $periodQuery, $amountColumn, $this->failedStatuses()),
            'refunded_amount' => $this->sumByStatus($sourceTable, $periodQuery, $amountColumn, ['refunded', 'refund']),
        ];
    }

    private function planTotalRevenue(array $filters): float
    {
        if (! Schema::hasTable('membership_orders')) {
            return 0.0;
        }

        $amountColumn = $this->firstColumn('membership_orders', ['total_amount', 'payable_amount', 'amount']);

        if (! $amountColumn) {
            return 0.0;
        }

        $query = $this->dashboardQuery('membership_orders', $filters);

        $statusColumn = $this->firstColumn('membership_orders', ['payment_status', 'order_status', 'status']);

        if ($statusColumn) {
            $query->whereIn($statusColumn, $this->paidStatuses());
        }

        return round((float) $query->sum($amountColumn), 2);
    }

    private function dashboardQuery(string $table, array $filters): Builder
    {
        $query = DB::table($table);

        foreach (['user_id', 'plan_id', 'addon_id', 'feature_id', 'category_id'] as $column) {
            if (! empty($filters[$column]) && Schema::hasColumn($table, $column)) {
                $query->where($column, (int) $filters[$column]);
            }
        }

        $dateColumn = $this->firstColumn($table, [
            'created_at',
            'payment_date',
            'paid_at',
            'updated_at',
        ]);

        if ($dateColumn) {
            $query->whereBetween($dateColumn, [
                Carbon::parse($filters['date_from'])->startOfDay(),
                Carbon::parse($filters['date_to'])->endOfDay(),
            ]);
        }

        return $query;
    }

    private function filters(array $filters): array
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

    private function statusSummary(string $table, Builder $query, string $column): array
    {
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        return [
            'pending' => (clone $query)->whereIn($column, $this->pendingStatuses())->count(),
            'paid' => (clone $query)->whereIn($column, $this->paidStatuses())->count(),
            'active' => (clone $query)->where($column, 'active')->count(),
            'expired' => (clone $query)->where($column, 'expired')->count(),
            'failed' => (clone $query)->whereIn($column, $this->failedStatuses())->count(),
            'cancelled' => (clone $query)->whereIn($column, $this->cancelledStatuses())->count(),
            'refunded' => (clone $query)->whereIn($column, ['refunded', 'refund'])->count(),
        ];
    }

    private function sumByStatus(string $table, Builder $query, ?string $amountColumn, array $statuses): float
    {
        if (! $amountColumn) {
            return 0.0;
        }

        $statusColumn = $this->firstColumn($table, ['payment_status', 'order_status', 'status']);

        if (! $statusColumn) {
            return round((float) (clone $query)->sum($amountColumn), 2);
        }

        return round((float) (clone $query)->whereIn($statusColumn, $statuses)->sum($amountColumn), 2);
    }

    private function revenueBetween(string $table, string $amountColumn, Carbon $from, Carbon $to): float
    {
        $dateColumn = $this->firstColumn($table, ['payment_date', 'paid_at', 'created_at']);

        if (! $dateColumn) {
            return 0.0;
        }

        return $this->sumByStatus(
            $table,
            DB::table($table)->whereBetween($dateColumn, [$from, $to]),
            $amountColumn,
            $this->paidStatuses()
        );
    }

    private function groupCount(Builder $query, string $table, string $column): array
    {
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        return (clone $query)
            ->select($column)
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull($column)
            ->groupBy($column)
            ->pluck('total', $column)
            ->toArray();
    }

    private function countByColumnValue(Builder $query, string $table, string $column, array $values): int
    {
        if (! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (clone $query)->whereIn($column, $values)->count();
    }

    private function sumColumn(Builder $query, ?string $column): float
    {
        if (! $column) {
            return 0.0;
        }

        return round((float) (clone $query)->sum($column), 2);
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

    private function dateGroupExpression(string $dateColumn, string $groupBy): string
    {
        return match ($groupBy) {
            'month' => "DATE_FORMAT({$dateColumn}, '%Y-%m')",
            'week' => "YEARWEEK({$dateColumn}, 1)",
            default => "DATE({$dateColumn})",
        };
    }

    private function paidStatuses(): array
    {
        return ['paid', 'success', 'successful', 'completed', 'captured'];
    }

    private function pendingStatuses(): array
    {
        return ['pending', 'created', 'initiated'];
    }

    private function failedStatuses(): array
    {
        return ['failed', 'failure'];
    }

    private function cancelledStatuses(): array
    {
        return ['cancelled', 'canceled'];
    }

    private function missingTableStats(string $table): array
    {
        return [
            'available' => false,
            'table' => $table,
            'reason' => 'Table not found.',
        ];
    }

    private function safe(callable $callback): array
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
}