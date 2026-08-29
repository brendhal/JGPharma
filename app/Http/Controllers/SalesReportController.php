<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use App\Support\ReportPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A printable sales summary for a day, week, month or year.
 *
 * Cashiers get their own transactions only; management sees every sale their
 * branch scope allows, and may narrow the report to one cashier.
 */
class SalesReportController extends Controller
{
    /** How many products the profit ranking prints before it stops. */
    private const TOP_PRODUCTS = 15;

    /**
     * Whose sales this report covers, or null for everyone in scope.
     *
     * A cashier is always pinned to themselves — the request cannot argue
     * otherwise. Management may name anyone, but only within the branches
     * they can already see, so a branch manager asking for another store's
     * cashier gets a 404 rather than a quietly empty page.
     */
    private function resolveCashier(Request $request, User $user): ?User
    {
        if (! $user->isManagement()) {
            return $user;
        }

        $id = $request->integer('cashier');

        if ($id < 1) {
            return null;
        }

        $cashier = User::query()
            ->when(
                ! $user->seesAllBranches(),
                fn ($query) => $query->where('branch_id', $user->branch_id)
            )
            ->find($id);

        abort_if($cashier === null, 404);

        return $cashier;
    }

    public function __invoke(Request $request): View
    {
        $span = ReportPeriod::fromRequest($request);
        [$from, $to] = $span->range();
        $period = $span->period;
        $heading = $span->heading;

        $user = $request->user();
        $cashier = $this->resolveCashier($request, $user);

        $sales = Sale::query()
            ->completed()
            ->with(['cashier', 'branch', 'items.product.medicine', 'items.product.category'])
            ->withCount('items')
            ->withSum('items as units_sold', 'quantity')
            ->when($cashier, fn ($query) => $query->where('user_id', $cashier->id))
            ->whereBetween('sale_date', [$from, $to])
            ->orderBy('sale_date')
            ->get();

        // Cost prices and margins stay off the floor: a cashier gets their
        // own takings, management gets what those takings earned.
        $showProfit = $user->isManagement();

        $lines = $sales->flatMap->items;

        $cost = (float) $lines->sum(fn ($item) => (float) $item->cost_price * (int) $item->quantity);
        $revenue = (float) $sales->sum(fn (Sale $sale) => (float) $sale->total_amount);
        $profit = (float) $lines->sum(fn ($item) => $item->line_profit);

        // A year's report reads as twelve months; anything shorter reads as
        // days. Either way the buckets are the period broken into its
        // natural next unit down.
        $byMonth = $span->bucketsByMonth();

        return view('reports.sales', [
            'sales' => $sales,
            'period' => $period,
            'heading' => $heading,
            'from' => $from,
            'to' => $to,
            'user' => $user,
            'cashier' => $cashier,
            'scopedToSelf' => ! $user->isManagement(),
            'showProfit' => $showProfit,
            'store' => config('pos.store'),
            'totals' => [
                'gross' => (float) $sales->sum(fn (Sale $sale) => (float) $sale->subtotal),
                'discount' => (float) $sales->sum(fn (Sale $sale) => (float) $sale->discount),
                'net' => $revenue,
                'cost' => round($cost, 2),
                'profit' => round($profit, 2),
                'margin' => self::margin($profit, $revenue),
                'units' => (int) $sales->sum('units_sold'),
                'transactions' => $sales->count(),
            ],
            'byPaymentMethod' => $sales
                ->groupBy(fn (Sale $sale) => $sale->payment_method?->getLabel() ?? '—')
                ->map(fn ($group) => [
                    'count' => $group->count(),
                    'total' => (float) $group->sum(fn (Sale $sale) => (float) $sale->total_amount),
                ])
                ->sortKeys(),
            'bucketLabel' => $byMonth ? 'By month' : 'By day',
            'byBucket' => $sales
                ->groupBy(fn (Sale $sale) => $sale->sale_date->format($byMonth ? 'Y-m' : 'Y-m-d'))
                ->map(fn ($group) => self::summarise($group))
                ->sortKeys(),
            'byCashier' => $showProfit && ! $cashier
                ? $sales
                    ->groupBy(fn (Sale $sale) => $sale->cashier?->name ?? '—')
                    ->map(fn ($group) => self::summarise($group))
                    ->sortByDesc('profit')
                : collect(),
            'byCategory' => $showProfit
                ? $lines
                    ->groupBy(fn ($item) => $item->product?->category?->name ?? 'Uncategorised')
                    ->map(fn ($group) => self::summariseLines($group))
                    ->sortByDesc('profit')
                : collect(),
            'byProduct' => $showProfit
                ? $lines
                    ->groupBy(fn ($item) => $item->product?->name ?? 'Unknown item')
                    ->map(fn ($group) => self::summariseLines($group))
                    ->sortByDesc('profit')
                    ->take(self::TOP_PRODUCTS)
                : collect(),
        ]);
    }

    /**
     * Revenue, cost and profit for a set of whole sales.
     *
     * @param  \Illuminate\Support\Collection<int, Sale>  $sales
     * @return array{count:int, units:int, total:float, cost:float, profit:float, margin:float}
     */
    private static function summarise($sales): array
    {
        $lines = $sales->flatMap->items;
        $total = (float) $sales->sum(fn (Sale $sale) => (float) $sale->total_amount);
        $profit = (float) $lines->sum(fn ($item) => $item->line_profit);

        return [
            'count' => $sales->count(),
            'units' => (int) $lines->sum('quantity'),
            'total' => round($total, 2),
            'cost' => round((float) $lines->sum(fn ($item) => (float) $item->cost_price * (int) $item->quantity), 2),
            'profit' => round($profit, 2),
            'margin' => self::margin($profit, $total),
        ];
    }

    /**
     * The same figures for a set of sale lines.
     *
     * Revenue here is the line subtotal rather than the sale total, so a
     * whole-ticket discount is not attributed to any one product.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\SaleItem>  $lines
     * @return array{count:int, units:int, total:float, cost:float, profit:float, margin:float}
     */
    private static function summariseLines($lines): array
    {
        $total = (float) $lines->sum(fn ($item) => (float) $item->subtotal);
        $profit = (float) $lines->sum(fn ($item) => $item->line_profit);

        return [
            'count' => $lines->count(),
            'units' => (int) $lines->sum('quantity'),
            'total' => round($total, 2),
            'cost' => round((float) $lines->sum(fn ($item) => (float) $item->cost_price * (int) $item->quantity), 2),
            'profit' => round($profit, 2),
            'margin' => self::margin($profit, $total),
        ];
    }

    /** Profit as a percentage of revenue; nothing sold is nothing earned. */
    private static function margin(float $profit, float $revenue): float
    {
        return $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0;
    }
}
