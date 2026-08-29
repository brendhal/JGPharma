<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Support\ReportPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What is sitting in the e-wallet and the card terminal, waiting to be moved.
 *
 * Cash is counted in the drawer at close. GCash and card are not — that money
 * lives with the provider until somebody cashes it out, and the only way to
 * check the provider's figure is to lay every transaction reference beside
 * it. That is what this prints: one line per payment, a subtotal per day, and
 * the amount to withdraw.
 *
 * The two providers can be printed together or one at a time, because they
 * are reconciled against different statements — GCash in the wallet app, card
 * against the terminal's settlement report.
 */
class CollectionsReportController extends Controller
{
    /**
     * Periods this report offers.
     *
     * Withdrawing is a weekly-or-longer errand, so there is no daily sheet
     * here — the day's takings belong on the sales report instead.
     *
     * @var array<int, string>
     */
    private const PERIODS = ['weekly', 'monthly', 'yearly'];

    public function __invoke(Request $request): View
    {
        // Cashiers ring the sales up; moving the money is the owner's job.
        abort_unless($request->user()?->isManagement() ?? false, 403);

        $span = ReportPeriod::fromRequest($request, self::PERIODS, 'monthly');
        [$from, $to] = $span->range();

        $methods = $this->methods($request);

        $sales = Sale::query()
            ->completed()
            ->with(['cashier', 'branch'])
            ->whereIn('payment_method', $methods->map(fn (PaymentMethod $m) => $m->value))
            ->whereBetween('sale_date', [$from, $to])
            ->orderBy('sale_date')
            ->get();

        $sections = $methods->mapWithKeys(fn (PaymentMethod $method): array => [
            $method->value => $this->section(
                $method,
                $sales->filter(fn (Sale $sale) => $sale->payment_method === $method),
            ),
        ]);

        return view('reports.collections', [
            'period' => $span->period,
            'heading' => $span->heading,
            'from' => $from,
            'to' => $to,
            'user' => $request->user(),
            'store' => config('pos.store'),
            'sections' => $sections,
            'methodLabel' => $this->methodLabel($methods),
            'singleMethod' => $methods->count() === 1,
            'grandTotal' => round((float) $sales->sum(fn (Sale $sale) => (float) $sale->total_amount), 2),
            'transactions' => $sales->count(),
            'missingReferences' => $sales
                ->filter(fn (Sale $sale) => blank($sale->payment_reference))
                ->count(),
        ]);
    }

    /**
     * Which providers this run covers.
     *
     * Everything that carries a reference number by default; one of them when
     * the request names it, so a GCash withdrawal is not printed alongside a
     * card settlement it has nothing to do with. Cash is never an answer —
     * there is nothing to withdraw.
     *
     * @return Collection<int, PaymentMethod>
     */
    private function methods(Request $request): Collection
    {
        $collectable = collect(PaymentMethod::cases())
            ->filter(fn (PaymentMethod $method) => $method->requiresReference())
            ->values();

        $named = PaymentMethod::tryFrom($request->string('method')->upper()->toString());

        return $named && $named->requiresReference()
            ? collect([$named])
            : $collectable;
    }

    /** @param  Collection<int, PaymentMethod>  $methods */
    private function methodLabel(Collection $methods): string
    {
        return $methods->count() === 1
            ? $methods->first()->getLabel()
            : $methods->map(fn (PaymentMethod $m) => $m->getLabel())->join(' & ');
    }

    /**
     * One provider's takings: the payments, a subtotal per day, and the total.
     *
     * @param  Collection<int, Sale>  $sales
     * @return array{method: PaymentMethod, sales: Collection<int, Sale>, total: float, count: int, byDay: Collection<string, array{count:int, total:float}>, missing: int}
     */
    private function section(PaymentMethod $method, Collection $sales): array
    {
        return [
            'method' => $method,
            'sales' => $sales->values(),
            'count' => $sales->count(),
            'total' => round((float) $sales->sum(fn (Sale $sale) => (float) $sale->total_amount), 2),
            // A day's subtotal is what a single withdrawal usually covers, so
            // it is the figure to match against the provider's statement.
            'byDay' => $sales
                ->groupBy(fn (Sale $sale) => $sale->sale_date->format('Y-m-d'))
                ->map(fn (Collection $group): array => [
                    'count' => $group->count(),
                    'total' => round((float) $group->sum(fn (Sale $sale) => (float) $sale->total_amount), 2),
                ])
                ->sortKeys(),
            // Sales taken before the reference number became compulsory. They
            // still count towards the money, they just cannot be matched
            // line by line.
            'missing' => $sales->filter(fn (Sale $sale) => blank($sale->payment_reference))->count(),
        ];
    }
}
