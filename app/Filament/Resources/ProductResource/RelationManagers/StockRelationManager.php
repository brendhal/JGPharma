<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\BranchInventory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StockRelationManager extends RelationManager
{
    protected static string $relationship = 'inventories';

    protected static ?string $title = 'Stock per branch';

    protected static ?string $icon = 'heroicon-o-building-storefront';

    public function form(Form $form): Form
    {
        $product = $this->getOwnerRecord();
        $perUnit = max(1, (int) $product->pieces_per_box);
        $unit = trim((string) $product->unit) ?: 'unit';
        $piece = trim((string) $product->piece_unit) ?: 'piece';

        return $form->schema([
            Forms\Components\Select::make('branch_id')
                ->label('Branch')
                ->relationship('branch', 'name')
                ->required(),

            Forms\Components\TextInput::make('minimum_stock')
                ->label($perUnit > 1
                    ? "Reorder level for this branch (in {$unit})"
                    : 'Reorder level for this branch')
                ->numeric()
                ->minValue(0)
                ->live(onBlur: true)
                // Same trade as the product-wide level: stored in pieces to
                // stay comparable with the shelf, typed in whole units. Blank
                // has to survive the round trip, since blank means "inherit".
                ->afterStateHydrated(function (Forms\Components\TextInput $component, mixed $state) use ($perUnit): void {
                    if (blank($state) || $perUnit <= 1) {
                        return;
                    }

                    $component->state((int) ceil(max(0, (int) $state) / $perUnit));
                })
                ->dehydrateStateUsing(fn (mixed $state): ?int => blank($state)
                    ? null
                    : max(0, (int) $state) * $perUnit)
                ->helperText(function (Forms\Get $get) use ($perUnit, $unit, $piece): string {
                    $base = 'Leave blank to use the product-wide reorder level.';

                    if ($perUnit <= 1 || blank($get('minimum_stock'))) {
                        return $base;
                    }

                    $level = max(0, (int) $get('minimum_stock'));

                    return $base.' '.$level.' '.\Illuminate\Support\Str::plural($unit, $level)
                        .' is '.number_format($level * $perUnit).' '.\Illuminate\Support\Str::plural($piece).'.';
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('On hand')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (BranchInventory $record): string => match ($record->status) {
                        'Out of Stock' => 'danger',
                        'Low Stock' => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('reorder_level')
                    ->label('Reorder at')
                    ->alignCenter()
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
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Set reorder level'),
            ])
            ->paginated(false);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
