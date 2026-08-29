<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A printable record of stock written off over a day, week, month or year.
 *
 * Management only — a write-off sheet is a loss statement, and the branch
 * scope on stock movements already keeps one store's losses out of another's.
 */
class DisposalReportController extends Controller
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

        // Blank means both, which is the usual way to read a write-off sheet.
        $reason = MovementType::tryFrom($request->string('reason')->upper()->toString());

        if ($reason !== null && ! in_array($reason, MovementType::disposalCases(), true)) {
            $reason = null;
        }

        $disposals = StockMovement::query()
            ->disposals()
            ->with(['product.packs', 'batch', 'user', 'branch'])
            ->when($reason, fn ($query) => $query->where('type', $reason))
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        // Valued in PHP rather than SQL: the per-piece cost depends on the
        // product's pack size and on which batch the stock came out of, and
        // that is two joins and a division the database should not be doing.
        $value = fn ($rows): float => round(
            $rows->sum(fn (StockMovement $m): float => $m->disposal_value),
            2
        );

        return view('reports.disposals', [
            'disposals' => $disposals,
            'period' => $period,
            'heading' => $heading,
            'from' => $from,
            'to' => $to,
            'user' => $user,
            'reason' => $reason,
            'store' => config('pos.store'),
            'totals' => [
                'entries' => $disposals->count(),
                'pieces' => (int) $disposals->sum(fn (StockMovement $m): int => $m->pieces_disposed),
                'value' => $value($disposals),
                'products' => $disposals->pluck('product_id')->unique()->count(),
            ],
            'byReason' => collect(MovementType::disposalCases())
                ->mapWithKeys(function (MovementType $type) use ($disposals, $value): array {
                    $group = $disposals->where('type', $type);

                    return [$type->getLabel() => [
                        'entries' => $group->count(),
                        'pieces' => (int) $group->sum(fn (StockMovement $m): int => $m->pieces_disposed),
                        'value' => $value($group),
                    ]];
                }),
            'byProduct' => $disposals
                ->groupBy('product_id')
                ->map(fn ($group) => [
                    'product' => $group->first()->product,
                    'entries' => $group->count(),
                    'pieces' => (int) $group->sum(fn (StockMovement $m): int => $m->pieces_disposed),
                    'value' => $value($group),
                ])
                ->sortByDesc('value')
                ->values(),
        ]);
    }
}
