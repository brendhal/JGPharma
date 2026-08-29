<?php

namespace App\Filament\Resources;

use App\Enums\MovementType;
use App\Filament\Resources\StockMovementResource\Pages;
use App\Models\StockMovement;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock control';

    protected static ?string $modelLabel = 'stock movement';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'batch', 'user', 'branch']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (StockMovement $record): ?string => $record->batch
                        ? 'Batch: '.$record->batch->batch_number
                        : null),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantity')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').number_format($state))
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Balance')
                    ->alignEnd()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remarks')
                    ->label('Reason')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Recorded by')
                    ->placeholder('System')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Movement type')
                    ->options(MovementType::class)
                    ->multiple(),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => auth()->user()?->seesAllBranches() ?? false),

                Tables\Filters\Filter::make('period')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('From')
                            ->native(false),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('Until')
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('60s');
    }

    public static function canCreate(): bool
    {
        // Movements are only ever written by the system, never typed by hand.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockMovements::route('/'),
        ];
    }
}
