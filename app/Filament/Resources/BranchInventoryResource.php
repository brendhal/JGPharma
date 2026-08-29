<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchInventoryResource\Pages;
use App\Models\BranchInventory;
use App\Services\InventoryService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BranchInventoryResource extends Resource
{
    protected static ?string $model = BranchInventory::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock levels';

    protected static ?string $modelLabel = 'stock level';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    /** What one piece of this product is called, pluralised for a count. */
    protected static function pieceNoun(\App\Models\Product $product, int $count = 2): string
    {
        // A product that is not divisible has no separate piece unit; the one
        // unit it is sold in is also the one it is counted in.
        $noun = $product->piece_unit ?: ($product->unit ?: 'piece');

        return \Illuminate\Support\Str::plural($noun, $count);
    }

    /**
     * A shelf count with the unit it is counted in, e.g. "84 Bottles".
     *
     * The bare number is ambiguous next to a breakdown reading "14 Case" —
     * naming the unit is what says the 84 is bottles and not cases.
     */
    protected static function countWithUnit(?\App\Models\Product $product, int $quantity): string
    {
        if (! $product) {
            return (string) $quantity;
        }

        return $quantity.' '.static::pieceNoun($product, $quantity);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.medicine', 'product.packs', 'branch']))
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (BranchInventory $record): ?string => $record->product?->medicine?->full_name)
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU')
                    ->color('gray')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('On hand')
                    ->alignCenter()
                    ->weight('bold')
                    ->formatStateUsing(fn (BranchInventory $record, $state): string => static::countWithUnit(
                        $record->product,
                        (int) $state,
                    ))
                    ->color(fn (BranchInventory $record): string => match ($record->status) {
                        'Out of Stock' => 'danger',
                        'Low Stock' => 'warning',
                        default => 'success',
                    })
                    // The number is the piece count, which is what the shelf
                    // actually holds; underneath it, the same figure counted
                    // in boxes and blisters, which is what a person can see.
                    ->description(fn (BranchInventory $record): ?string => $record->product?->isDivisible()
                        ? $record->product->formatPieces((int) $record->quantity)
                        : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('reorder_level')
                    ->label('Reorder at')
                    ->alignCenter()
                    ->formatStateUsing(fn (BranchInventory $record, $state): string => static::countWithUnit(
                        $record->product,
                        (int) $state,
                    ))
                    ->description(fn (BranchInventory $record): ?string => $record->product?->isDivisible()
                        ? $record->product->formatPieces((int) $record->reorder_level)
                        : null),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Out of Stock' => 'danger',
                        'Low Stock' => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('product.selling_price')
                    ->label('Price')
                    ->money('PHP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last movement')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => auth()->user()?->seesAllBranches() ?? false),

                Tables\Filters\Filter::make('low_stock')
                    ->label('Low stock')
                    ->query(fn (Builder $query) => $query->lowStock())
                    ->toggle(),

                Tables\Filters\Filter::make('out_of_stock')
                    ->label('Out of stock')
                    ->query(fn (Builder $query) => $query->outOfStock())
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust')
                    ->label('Adjust')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('warning')
                    ->form([
                        Forms\Components\Placeholder::make('current')
                            ->label('System quantity')
                            ->content(function (BranchInventory $record): string {
                                $quantity = (int) $record->quantity;
                                $product = $record->product;

                                if (! $product?->isDivisible()) {
                                    return (string) $quantity;
                                }

                                return $quantity.' '.static::pieceNoun($product)
                                    .'  ('.$product->formatPieces($quantity).')';
                            }),

                        Forms\Components\TextInput::make('counted_quantity')
                            // A count typed in boxes would wipe the shelf, so
                            // the unit is named in the label, not just implied.
                            ->label(fn (BranchInventory $record): string => $record->product?->isDivisible()
                                ? 'Counted quantity (in '.static::pieceNoun($record->product).')'
                                : 'Counted quantity')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText(fn (BranchInventory $record): string => $record->product?->isDivisible()
                                ? 'Count the smallest piece, not boxes — a full box of '
                                    .$record->product->pieces_per_box.' is '.$record->product->pieces_per_box.'.'
                                : 'Enter what you physically counted on the shelf.'),

                        Forms\Components\Textarea::make('remarks')
                            ->label('Reason')
                            ->required()
                            ->rows(2)
                            ->placeholder('Physical audit discrepancy'),
                    ])
                    ->action(function (BranchInventory $record, array $data, InventoryService $inventory) {
                        $movement = $inventory->adjust(
                            branchId: $record->branch_id,
                            productId: $record->product_id,
                            countedQuantity: (int) $data['counted_quantity'],
                            remarks: $data['remarks'],
                        );

                        Notification::make()
                            ->title($movement ? 'Stock adjusted' : 'No change needed')
                            ->body($movement
                                ? "Recorded {$movement->signed_quantity} for {$record->product->name}."
                                : 'The counted quantity already matches the system.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('quantity')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchInventories::route('/'),
        ];
    }
}
