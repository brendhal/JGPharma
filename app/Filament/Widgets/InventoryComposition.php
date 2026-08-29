<?php

namespace App\Filament\Widgets;

use App\Models\BranchInventory;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * How the on-hand stock splits across product categories.
 */
class InventoryComposition extends ChartWidget
{
    protected static ?string $heading = 'Inventory composition';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '260px';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    protected function getData(): array
    {
        $rows = BranchInventory::query()
            ->join('products', 'products.id', '=', 'branch_inventories.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('branch_inventories.quantity', '>', 0)
            ->groupBy('categories.name')
            ->select(DB::raw('coalesce(categories.name, \'Uncategorised\') as label'))
            ->selectRaw('sum(branch_inventories.quantity) as total')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Units on hand',
                    'data' => $rows->pluck('total')->map(fn ($v) => (int) $v)->all(),
                    'backgroundColor' => [
                        '#0B5F8A', '#12734B', '#B45309', '#64748B', '#7C3AED', '#DC2626',
                    ],
                    'borderWidth' => 0,
                ],
            ],
            'labels' => $rows->pluck('label')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
            'cutout' => '62%',
        ];
    }
}
