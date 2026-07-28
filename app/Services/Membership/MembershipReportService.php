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

class MembershipReportService
{
    public function dashboard(array $filters = []): array
    {
        return Cache::store('redis')->remember(
            $this->cacheKey('dashboard', $filters),
            300,
            fn () => [
                'period' => $this->period($filters),
                'summary' => $this->summary($filters),
                'membership_status' => $this->membershipStatusCounts(),
                'revenue_trend' => $this->revenueTrend($filters),
                'top_plans' => $this->topPlans($filters),
                'top_addons' => $this->topAddons($filters),
                'credit_usage' => $this->creditUsage($filters),
                'latest_membership_orders' => $this->latestMembershipOrders(),
                'latest_addon_orders' => $this->latestAddonOrders(),
            ]
        );
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
            ->when(!empty($filters['plan_id']), fn ($q) => $q->where('plan_id', (int) $filters['plan_id']))
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
            ->when(!empty($filters['addon_id']), fn ($q) => $q->where('addon_id', (int) $filters['addon_id']))
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
                    ->when(!empty($filters['plan_id']), fn ($q) => $q->where('plan_id', (int) $filters['plan_id']))
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
                    ->when(!empty($filters['addon_id']), fn ($q) => $q->where('addon_id', (int) $filters['addon_id']))
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
            ->map(fn ($row) => [
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
            ->map(fn ($row) => [
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
            ->when(!empty($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
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
            ->map(fn ($row) => [
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
            ->map(fn ($order) => [
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
            ->map(fn ($order) => [
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