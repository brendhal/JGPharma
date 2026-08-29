<?php

namespace App\Filament\Widgets;

use App\Models\Sale;
use Filament\Widgets\ChartWidget;

class SalesPerformanceChart extends ChartWidget
{
    protected static ?string $heading = 'Sales performance';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 2;

    protected static ?string $maxHeight = '260px';

    public ?string $filter = '7';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 7);

        $labels = [];
        $revenue = [];
        $transactions = [];

        foreach (range($days - 1, 0) as $daysAgo) {
            $date = today()->subDays($daysAgo);

            $labels[] = $days <= 7
                ? $date->format('D')
                : $date->format('d M');

            $sales = Sale::query()->completed()->whereDate('sale_date', $date);

            $revenue[] = round((float) (clone $sales)->sum('total_amount'), 2);
            $transactions[] = (clone $sales)->count();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (₱)',
                    'data' => $revenue,
                    'backgroundColor' => 'rgba(11, 95, 138, 0.75)',
                    'borderColor' => '#0B5F8A',
                    'borderRadius' => 6,
                ],
                [
                    'label' => 'Transactions',
                    'data' => $transactions,
                    'backgroundColor' => 'rgba(148, 163, 184, 0.55)',
                    'borderColor' => '#94a3b8',
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
