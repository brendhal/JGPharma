<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\ProductBatch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'batches';

    protected static ?string $title = 'Batches & expiry';

    protected static ?string $icon = 'heroicon-o-calendar-days';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('branch_id')
                ->label('Branch')
                ->relationship('branch', 'name')
                ->default(auth()->user()?->branch_id)
                ->disabled(fn () => ! auth()->user()->seesAllBranches())
                ->dehydrated()
                ->required(),

            Forms\Components\TextInput::make('batch_number')
                ->label('Batch / lot number')
                ->required()
                ->maxLength(60),

            Forms\Components\DatePicker::make('expiration_date')
                ->label('Expiration date')
                ->native(false)
                ->minDate(now()->subYears(2)),

            Forms\Components\TextInput::make('quantity')
                ->numeric()
                ->default(0)
                ->required()
                ->helperText('Use Inventory → Adjust levels to change this after receiving.'),

            Forms\Components\TextInput::make('cost_price')
                ->numeric()
                ->prefix('₱')
                ->default(0),

            Forms\Components\Select::make('supplier_id')
                ->label('Supplier')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload(),

            Forms\Components\DatePicker::make('received_date')
                ->default(now())
                ->native(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('batch_number')
            ->columns([
                Tables\Columns\TextColumn::make('batch_number')
                    ->label('Batch')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('On hand')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expiration_date')
                    ->label('Expires')
                    ->date('d M Y')
                    ->placeholder('No expiry')
                    ->description(function (ProductBatch $record): ?string {
                        $days = $record->days_to_expiry;

                        if ($days === null) {
                            return null;
                        }

                        return $days < 0
                            ? abs($days).' days ago'
                            : $days.' days left';
                    })
                    ->color(fn (ProductBatch $record): string => match (true) {
                        $record->is_expired => 'danger',
                        $record->is_expiring_soon => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_price')
                    ->label('Cost')
                    ->money('PHP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('expiring')
                    ->label('Expiring soon')
                    ->query(fn ($query) => $query->expiringWithin())
                    ->toggle(),

                Tables\Filters\Filter::make('expired')
                    ->label('Already expired')
                    ->query(fn ($query) => $query->expired())
                    ->toggle(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add batch'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('expiration_date');
    }
}
