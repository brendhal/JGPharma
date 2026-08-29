<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\StockMovement;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Written-off stock sorted into a refund or replacement claim per supplier.
 *
 * A disposal reaches a supplier through the batch it came out of, so stock
 * written off without a batch — an adjustment on loose pieces, a product that
 * was never batch tracked — cannot be claimed from anyone and is listed on its
 * own sheet at the end so nothing goes missing from the totals.
 *
 * Expired stock is the default because that is the pull-out a supplier
 * normally accepts; damaged is available for the branches that claim it.
 *
 * Management only. The branch scope on stock movements keeps one store's
 * claims out of another's.
 */
class SupplierClaimController extends Controller
{
    /** @var array<int, string> */
    private const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        abort_unless($user?->isManagement() ?? false, 403);

        $period = $request->string('period')->lower()->toString();

        if (! in_array($period, self::PERIODS, true)) {
            $period = 'monthly';
        }

        $anchor = rescue(
            fn (): CarbonImmutable => CarbonImmutable::parse($request->string('date')->toString()),
            CarbonImmutable::now(),
            report: false,
        );

        [$from, $to, $heading] = match ($period) {
            'daily' => [
                $anchor->startOfDay(),
                $anchor->endOfDay(),
                $anchor->format('l, d F Y'),
            ],
            'weekly' => [
                $anchor->startOfWeek(),
                $anchor->endOfWeek(),
                'Week of '.$anchor->startOfWeek()->format('d M Y'),
            ],
            'yearly' => [
                $anchor->startOfYear(),
                $anchor->endOfYear(),
                $anchor->format('Y'),
            ],
            default => [
                $anchor->startOfMonth(),
                $anchor->endOfMonth(),
                $anchor->format('F Y'),
            ],
        };

        // Expired unless asked otherwise; 'all' covers both write-off reasons.
        $requested = $request->string('reason')->upper()->toString();
        $reason = $requested === 'ALL'
            ? null
            : (MovementType::tryFrom($requested) ?? MovementType::EXPIRED);

        if ($reason !== null && ! in_array($reason, MovementType::disposalCases(), true)) {
            $reason = MovementType::EXPIRED;
        }

        $supplierId = $request->integer('supplier');
        $supplier = $supplierId > 0 ? Supplier::find($supplierId) : null;

        $disposals = StockMovement::query()
            ->disposals()
            ->with(['product.packs', 'batch.supplier', 'user', 'branch'])
            ->when($reason, fn ($query) => $query->where('type', $reason))
            ->when(
                $supplier,
                fn ($query) => $query->whereHas(
                    'batch',
                    fn ($batch) => $batch->where('supplier_id', $supplier->id)
                )
            )
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        return view('reports.supplier-claims', [
            'claims' => $this->groupBySupplier($disposals),
            'period' => $period,
            'heading' => $heading,
            'from' => $from,
            'to' => $to,
            'user' => $user,
            'reason' => $reason,
            'supplier' => $supplier,
            'store' => config('pos.store'),
            'totals' => [
                'entries' => $disposals->count(),
                'pieces' => (int) $disposals->sum(fn (StockMovement $m): int => $m->pieces_disposed),
                'value' => round($disposals->sum(fn (StockMovement $m): float => $m->disposal_value), 2),
            ],
        ]);
    }

    /**
     * One claim sheet per supplier, unclaimable stock last.
     *
     * @param  Collection<int, StockMovement>  $disposals
     * @return Collection<int, array{supplier: ?Supplier, entries: int, pieces: int, value: float, rows: Collection<int, StockMovement>}>
     */
    private function groupBySupplier(Collection $disposals): Collection
    {
        return $disposals
            ->groupBy(fn (StockMovement $movement): int => $movement->batch?->supplier_id ?? 0)
            ->map(fn (Collection $rows): array => [
                'supplier' => $rows->first()->batch?->supplier,
                'entries' => $rows->count(),
                'pieces' => (int) $rows->sum(fn (StockMovement $m): int => $m->pieces_disposed),
                'value' => round($rows->sum(fn (StockMovement $m): float => $m->disposal_value), 2),
                'rows' => $rows->sortBy(fn (StockMovement $m): string => $m->product?->name ?? '')->values(),
            ])
            ->sortBy(fn (array $claim): string => $claim['supplier']?->name ?? 'zzzz')
            ->values();
    }
}
