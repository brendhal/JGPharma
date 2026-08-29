<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What each supplier charges for the same product, side by side.
 *
 * There is no quoted price list in this system — the only place a supplier's
 * price is written down is a purchase order. So a "quote" here is the cost on
 * the most recent order placed with that supplier for that product: what they
 * charged last time, which is the best guess at what they will charge next
 * time. Older orders are still read, but only to count how much has been
 * bought and to work out what buying at the cheapest price would have saved.
 *
 * Cancelled orders are ignored. Pending ones are not: a price agreed this
 * morning is more use when deciding where to order than one from a delivery
 * six months ago.
 *
 * Prices are per whole pack, the same unit a purchase order is written in, so
 * two suppliers are always compared on the same thing. The per-piece figure is
 * only ever shown alongside as a reading aid.
 *
 * Branch scoping comes free: the window into purchases is a relationship
 * query, so a branch manager compares the prices their own store was given
 * and an administrator compares every store's.
 */
class SupplierPriceComparison
{
    /** Lookback windows offered on the report, in months. 0 means everything. */
    public const WINDOWS = [
        3 => 'Last 3 months',
        6 => 'Last 6 months',
        12 => 'Last 12 months',
        24 => 'Last 24 months',
        0 => 'All time',
    ];

    public const DEFAULT_MONTHS = 12;

    /**
     * @param  array{months?:int|string|null, category?:int|string|null, search?:string|null, multi_only?:bool}  $filters
     * @return array{
     *     rows: Collection<int, array<string, mixed>>,
     *     suppliers: Collection<int, Supplier>,
     *     from: ?CarbonImmutable,
     *     to: CarbonImmutable,
     *     months: int,
     *     totals: array{products:int, comparable:int, suppliers:int, overspend:float, spend:float},
     *     best_by_supplier: Collection<int, array<string, mixed>>,
     * }
     */
    public function build(array $filters = []): array
    {
        $months = $this->months($filters['months'] ?? null);
        $to = CarbonImmutable::now()->endOfDay();
        $from = $months > 0 ? $to->subMonths($months)->startOfDay() : null;

        $categoryId = (int) ($filters['category'] ?? 0);
        $search = trim((string) ($filters['search'] ?? ''));
        $multiOnly = (bool) ($filters['multi_only'] ?? true);

        $items = PurchaseItem::query()
            ->whereHas('purchase', function (Builder $query) use ($from, $to): void {
                $query->whereIn('status', [PurchaseStatus::PENDING->value, PurchaseStatus::RECEIVED->value])
                    ->when($from, fn (Builder $q) => $q->whereBetween('purchase_date', [$from, $to]));
            })
            ->when(
                $categoryId > 0,
                fn (Builder $query) => $query->whereHas('product', fn (Builder $p) => $p->where('category_id', $categoryId))
            )
            ->when(
                $search !== '',
                fn (Builder $query) => $query->whereHas('product', fn (Builder $p) => $p->searchTerm($search))
            )
            ->where('cost_price', '>', 0)
            ->with(['product.category', 'product.brand', 'product.packs', 'purchase.supplier'])
            ->get()
            ->filter(fn (PurchaseItem $item): bool => $item->product !== null && $item->purchase?->supplier !== null);

        $rows = $items
            ->groupBy('product_id')
            ->map(fn (Collection $lines): array => $this->compareOneProduct($lines))
            ->filter(fn (array $row): bool => ! $multiOnly || count($row['quotes']) > 1)
            ->sortByDesc('overspend')
            ->values();

        // Only the suppliers that actually quote something on the rows shown
        // get a column, so a supplier of bandages never widens a sheet of
        // antibiotics.
        $suppliers = $rows
            ->flatMap(fn (array $row): array => array_map(fn (array $quote) => $quote['supplier'], $row['quotes']))
            ->unique('id')
            ->sortBy('name')
            ->values();

        return [
            'rows' => $rows,
            'suppliers' => $suppliers,
            'from' => $from,
            'to' => $to,
            'months' => $months,
            'totals' => [
                'products' => $rows->count(),
                'comparable' => $rows->filter(fn (array $row): bool => count($row['quotes']) > 1)->count(),
                'suppliers' => $suppliers->count(),
                'overspend' => round((float) $rows->sum('overspend'), 2),
                'spend' => round((float) $rows->sum('spend'), 2),
            ],
            'best_by_supplier' => $this->orderGuide($rows),
        ];
    }

    /** The lookback the report was asked for, or the default if it was nonsense. */
    public function months(int|string|null $requested): int
    {
        $months = (int) $requested;

        return array_key_exists($months, self::WINDOWS) ? $months : self::DEFAULT_MONTHS;
    }

    /** "Last 12 months", for a heading. */
    public function windowLabel(int $months): string
    {
        return self::WINDOWS[$months] ?? self::WINDOWS[self::DEFAULT_MONTHS];
    }

    /**
     * One product's price from every supplier who has quoted it.
     *
     * @param  Collection<int, PurchaseItem>  $lines
     * @return array<string, mixed>
     */
    private function compareOneProduct(Collection $lines): array
    {
        /** @var Product $product */
        $product = $lines->first()->product;

        $quotes = $lines
            ->groupBy(fn (PurchaseItem $item): int => (int) $item->purchase->supplier_id)
            ->map(function (Collection $supplierLines): array {
                // Newest order wins; two on the same day settle by insertion
                // order, the later one being the later agreement.
                $latest = $supplierLines
                    ->sortBy([
                        fn (PurchaseItem $item) => $item->purchase->purchase_date->timestamp,
                        fn (PurchaseItem $item) => $item->id,
                    ])
                    ->last();

                $prices = $supplierLines->map(fn (PurchaseItem $item): float => (float) $item->cost_price);

                return [
                    'supplier' => $latest->purchase->supplier,
                    'price' => (float) $latest->cost_price,
                    'date' => $latest->purchase->purchase_date,
                    'reference' => $latest->purchase->reference_number,
                    'status' => $latest->purchase->status,
                    'orders' => $supplierLines->pluck('purchase_id')->unique()->count(),
                    'quantity' => (int) $supplierLines->sum('quantity'),
                    'lowest_ever' => round((float) $prices->min(), 2),
                    'highest_ever' => round((float) $prices->max(), 2),
                    'is_best' => false,
                    'difference' => 0.0,
                    'difference_percent' => 0.0,
                ];
            })
            ->sortBy('price');

        $best = $quotes->first();
        $worst = $quotes->last();
        $bestPrice = (float) $best['price'];

        $quotes = $quotes->map(function (array $quote) use ($bestPrice): array {
            $quote['difference'] = round((float) $quote['price'] - $bestPrice, 2);
            $quote['difference_percent'] = $bestPrice > 0
                ? round((((float) $quote['price'] - $bestPrice) / $bestPrice) * 100, 1)
                : 0.0;
            $quote['is_best'] = abs($quote['difference']) < 0.005;

            return $quote;
        });

        // What the volume already bought would have cost at the cheapest
        // price on the sheet. Never negative: the cheapest quote is the
        // floor, so a line that was always bought well simply shows nothing.
        $spend = (float) $lines->sum(fn (PurchaseItem $item): float => (float) $item->cost_price * (int) $item->quantity);
        $atBest = (float) $lines->sum(fn (PurchaseItem $item): float => $bestPrice * (int) $item->quantity);

        $spread = round((float) $worst['price'] - $bestPrice, 2);

        return [
            'product' => $product,
            'quotes' => $quotes->keyBy(fn (array $quote): int => (int) $quote['supplier']->id)->all(),
            'best' => $best,
            'worst' => $worst,
            'spread' => $spread,
            'spread_percent' => $bestPrice > 0 ? round(($spread / $bestPrice) * 100, 1) : 0.0,
            'quantity' => (int) $lines->sum('quantity'),
            'spend' => round($spend, 2),
            'overspend' => round(max(0, $spend - $atBest), 2),
        ];
    }

    /**
     * The same answer turned round: what to order from whom.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{supplier: Supplier, lines: Collection<int, array<string, mixed>>, saving: float}>
     */
    private function orderGuide(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row): int => (int) $row['best']['supplier']->id)
            ->map(fn (Collection $group): array => [
                'supplier' => $group->first()['best']['supplier'],
                'lines' => $group
                    ->sortBy(fn (array $row): string => (string) $row['product']->name)
                    ->values(),
                'saving' => round((float) $group->sum('overspend'), 2),
            ])
            ->sortBy(fn (array $guide): string => (string) $guide['supplier']->name)
            ->values();
    }
}
