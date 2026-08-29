<?php

namespace App\Filament\Widgets;

use App\Models\BranchInventory;
use App\Models\ProductBatch;
use App\Models\Sale;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Headline numbers for the Reports screen.
 */
class ReportsOverview extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    protected function getStats(): array
    {
        $today = (float) Sale::query()->completed()->today()->sum('total_amount');

        $monthToDate = (float) Sale::query()->completed()
            ->whereBetween('sale_date', [now()->startOfMonth(), now()])
            ->sum('total_amount');

        $dayOfMonth = max(1, (int) now()->day);
        $forecast = ($monthToDate / $dayOfMonth) * now()->daysInMonth;

        $criticalStock = BranchInventory::query()->lowStock()->count()
            + BranchInventory::query()->outOfStock()->count();

        $expiringBatches = ProductBatch::query()->inStock()->expiringWithin();
        $expiringCount = (clone $expiringBatches)->count();

        $lossAtRisk = (float) (clone $expiringBatches)
            ->sum(DB::raw('quantity * cost_price'));

        $grossProfit = (float) Sale::query()
            ->completed()
            ->whereBetween('sale_date', [now()->startOfMonth(), now()])
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->sum(DB::raw('sale_items.subtotal - (sale_items.cost_price * sale_items.quantity)'));

        return [
            Stat::make('Daily sales revenue', '₱'.number_format($today, 2))
                ->description('Sales rung up today')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),

            Stat::make('Monthly forecast', '₱'.number_format($forecast, 2))
                ->description('₱'.number_format($monthToDate, 2).' month-to-date')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Gross profit (MTD)', '₱'.number_format($grossProfit, 2))
                ->description('Revenue less cost of goods sold')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('success'),

            Stat::make('Critical stock', number_format($criticalStock).' items')
                ->description('Low or out of stock')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($criticalStock > 0 ? 'danger' : 'success'),

            Stat::make('Expiring < '.config('pos.expiry_alert_days').' days', number_format($expiringCount).' batches')
                ->description('Estimated loss: ₱'.number_format($lossAtRisk, 2))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($expiringCount > 0 ? 'warning' : 'success'),
        ];
    }
}
