<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Models\BranchInventory;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four tiles that sit above the catalog table.
 */
class ProductCatalogStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $branchId = auth()->user()?->branch_id;

        $base = fn () => BranchInventory::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $lowStock = $base()->lowStock()->count();
        $outOfStock = $base()->outOfStock()->count();

        $value = (float) $base()
            ->join('products', 'products.id', '=', 'branch_inventories.product_id')
            ->sum(\Illuminate\Support\Facades\DB::raw('branch_inventories.quantity * products.cost_price'));

        return [
            Stat::make('Total SKU', number_format(Product::query()->active()->count()))
                ->description('Active products in the catalog')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('primary'),

            Stat::make('Low stock alerts', number_format($lowStock))
                ->description('At or below reorder level')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStock > 0 ? 'danger' : 'success'),

            Stat::make('Out of stock', number_format($outOfStock))
                ->description('Nothing left on the shelf')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($outOfStock > 0 ? 'warning' : 'success'),

            Stat::make('Value in stock', '₱'.number_format($value, 2))
                ->description('Valued at cost price')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
