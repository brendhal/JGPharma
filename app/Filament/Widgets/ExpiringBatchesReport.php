<?php

namespace App\Filament\Widgets;

use App\Enums\MovementType;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The critical batch expiration report — money that walks out the door if
 * nobody acts on it.
 */
class ExpiringBatchesReport extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Critical batch expiration report';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductBatch::query()
                    ->with(['product.medicine', 'branch'])
                    ->inStock()
                    ->where(function ($q) {
                        $q->expiringWithin()->orWhereDate('expiration_date', '<', today());
                    })
                    ->orderBy('expiration_date')
            )
            ->defaultPaginationPageOption(10)
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Medicine name')
                    ->weight('semibold')
                    ->description(fn (ProductBatch $record): ?string => $record->product?->medicine?->full_name)
                    ->searchable(),

                Tables\Columns\TextColumn::make('batch_number')
                    ->label('Batch ID')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('product.medicine.manufacturer')
                    ->label('Manufacturer')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Stock (units)')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('stock_value')
                    ->label('Value at risk')
                    ->money('PHP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('expiration_date')
                    ->label('Expiry date')
                    ->badge()
                    ->formatStateUsing(function (ProductBatch $record): string {
                        $days = $record->days_to_expiry;

                        return $days < 0
                            ? 'Expired '.abs($days).' days ago'
                            : $days.' days left';
                    })
                    ->color(fn (ProductBatch $record): string => $record->is_expired ? 'danger' : 'warning')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('dispose')
                    ->label('Dispose')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('quantity')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(fn (ProductBatch $record): int => $record->quantity)
                            ->maxValue(fn (ProductBatch $record): int => $record->quantity),

                        Forms\Components\Textarea::make('remarks')
                            ->rows(2)
                            ->default('Expired items disposal'),
                    ])
                    ->action(function (ProductBatch $record, array $data, InventoryService $inventory) {
                        $inventory->dispose(
                            batch: $record,
                            quantity: (int) $data['quantity'],
                            type: MovementType::EXPIRED,
                            remarks: $data['remarks'] ?? null,
                        );

                        Notification::make()
                            ->title('Batch written off')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nothing expiring')
            ->emptyStateDescription('No batch expires within the next '.config('pos.expiry_alert_days').' days.');
    }
}
