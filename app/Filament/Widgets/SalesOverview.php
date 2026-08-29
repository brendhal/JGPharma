<?php

namespace App\Filament\Widgets;

use App\Models\BranchInventory;
use App\Models\ProductBatch;
use App\Models\Sale;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four headline tiles at the top of the dashboard.
 */
class SalesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    protected function getStats(): array
    {
        $todaySales = (float) Sale::query()->completed()->today()->sum('total_amount');
        $yesterdaySales = (float) Sale::query()->completed()
            ->whereDate('sale_date', today()->subDay())
            ->sum('total_amount');

        $orders = Sale::query()->completed()->today()->count();

        $change = $yesterdaySales > 0
            ? (($todaySales - $yesterdaySales) / $yesterdaySales) * 100
            : null;

        $lowStock = BranchInventory::query()->lowStock()->count();
        $expiring = ProductBatch::query()->inStock()->expiringWithin()->count();

        $hoursOpen = max(1, now()->diffInHours(today()->setHour(8)));

        return [
            Stat::make("Today's sales", '₱'.number_format($todaySales, 2))
                ->description($change === null
                    ? 'No sales yesterday to compare'
                    : sprintf('%+.1f%% from yesterday', $change))
                ->descriptionIcon($change !== null && $change >= 0
                    ? 'heroicon-m-arrow-trending-up'
                    : 'heroicon-m-arrow-trending-down')
                ->color($change !== null && $change < 0 ? 'danger' : 'success')
                ->chart($this->last7DaysTotals()),

            Stat::make('Total orders', number_format($orders))
                ->description('Average '.number_format($orders / $hoursOpen, 1).' orders/hr')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('primary'),

            Stat::make('Low stock items', number_format($lowStock))
                ->description($lowStock > 0 ? 'Critical alert: needs attention' : 'All shelves healthy')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStock > 0 ? 'danger' : 'success'),

            Stat::make('Expiring soon', number_format($expiring).' batches')
                ->description('Within the next '.config('pos.expiry_alert_days').' days')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($expiring > 0 ? 'warning' : 'success'),
        ];
    }

    /** Sparkline data for the sales tile. */
    protected function last7DaysTotals(): array
    {
        return collect(range(6, 0))
            ->map(fn (int $daysAgo) => (float) Sale::query()
                ->completed()
                ->whereDate('sale_date', today()->subDays($daysAgo))
                ->sum('total_amount'))
            ->all();
    }
}
