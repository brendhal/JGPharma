<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

/**
 * Best sellers for the current month, with the profit each one earned.
 */
class TopSellingProducts extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Top selling items (this month)';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->select('products.*')
                    ->join('sale_items', 'sale_items.product_id', '=', 'products.id')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->where('sales.status', 'COMPLETED')
                    ->whereBetween('sales.sale_date', [now()->startOfMonth(), now()->endOfMonth()])
                    ->groupBy('products.id')
                    ->selectRaw('sum(sale_items.quantity) as units_sold')
                    ->selectRaw('sum(sale_items.subtotal) as revenue')
                    ->selectRaw('sum(sale_items.subtotal - (sale_items.cost_price * sale_items.quantity)) as profit')
                    ->orderByDesc(DB::raw('sum(sale_items.quantity)'))
            )
            ->defaultPaginationPageOption(10)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Product')
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('unit')
                    ->label('Unit')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('units_sold')
                    ->label('Units sold')
                    ->alignCenter()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('revenue')
                    ->label('Revenue')
                    ->money('PHP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('profit')
                    ->label('Gross profit')
                    ->money('PHP')
                    ->alignEnd()
                    ->color('success')
                    ->weight('semibold'),
            ])
            ->emptyStateHeading('No sales this month yet');
    }
}
