<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExpiringBatchesReport;
use App\Filament\Widgets\InventoryComposition;
use App\Filament\Widgets\ReportsOverview;
use App\Filament\Widgets\SalesPerformanceChart;
use App\Filament\Widgets\TopSellingProducts;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class Reports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.reports';

    public function getHeading(): string|Htmlable
    {
        return 'System performance analytics';
    }

    public function getSubheading(): ?string
    {
        return 'Operational reporting for '.now()->format('F Y')
            .' · '.(auth()->user()->branch?->name ?? 'All branches');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ReportsOverview::class,
            SalesPerformanceChart::class,
            InventoryComposition::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getFooterWidgets(): array
    {
        return [
            ExpiringBatchesReport::class,
            TopSellingProducts::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
