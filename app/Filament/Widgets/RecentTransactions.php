<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentTransactions extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 2;

    protected static ?string $heading = 'Recent transactions';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Sale::query()
                    ->with(['items.product', 'cashier'])
                    ->completed()
                    ->latest('sale_date')
                    ->limit(8)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('sale_date')
                    ->label('Time')
                    ->dateTime('g:i A'),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Order ID')
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('item_summary')
                    ->label('Items')
                    ->wrap()
                    ->getStateUsing(fn (Sale $record): string => $record->items
                        ->map(fn ($item) => $item->product->name.' x'.$item->quantity)
                        ->take(3)
                        ->implode(', ').($record->items->count() > 3 ? '…' : '')),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total amount')
                    ->money('PHP')
                    ->alignEnd()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->url(fn (Sale $record): string => SaleResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
