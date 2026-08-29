<?php

namespace App\Filament\Resources;

use App\Enums\MovementType;
use App\Filament\Resources\ProductBatchResource\Pages;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductBatchResource extends Resource
{
    protected static ?string $model = ProductBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Batches & expiry';

    protected static ?string $modelLabel = 'batch';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'batch_number';

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

    public static function getNavigationBadge(): ?string
    {
        $count = ProductBatch::query()->inStock()->expiringWithin()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')
                ->label('Product')
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->required(),

            Forms\Components\Select::make('branch_id')
                ->label('Branch')
                ->relationship('branch', 'name')
                ->default(auth()->user()?->branch_id)
                ->required(),

            Forms\Components\TextInput::make('batch_number')
                ->required()
                ->maxLength(60),

            Forms\Components\DatePicker::make('expiration_date')
                ->native(false),

            Forms\Components\TextInput::make('quantity')
                ->numeric()
                ->default(0)
                ->required(),

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.medicine', 'branch', 'supplier']))
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Medicine / product')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (ProductBatch $record): ?string => $record->product?->medicine?->full_name)
                    ->sortable(),

                Tables\Columns\TextColumn::make('batch_number')
                    ->label('Batch ID')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable(),

                Tables\Columns\TextColumn::make('product.medicine.manufacturer')
                    ->label('Manufacturer')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Stock (units)')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expiration_date')
                    ->label('Expiry date')
                    ->date('d M Y')
                    ->placeholder('No expiry')
                    ->badge()
                    ->formatStateUsing(function ($state, ProductBatch $record): string {
                        $days = $record->days_to_expiry;

                        if ($days === null) {
                            return 'No expiry';
                        }

                        return $days < 0
                            ? 'Expired '.abs($days).'d ago'
                            : $days.' days left';
                    })
                    ->color(fn (ProductBatch $record): string => match (true) {
                        $record->is_expired => 'danger',
                        $record->is_expiring_soon => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_value')
                    ->label('Value')
                    ->money('PHP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => auth()->user()?->seesAllBranches() ?? false),

                Tables\Filters\Filter::make('expiring_soon')
                    ->label('Expiring within '.config('pos.expiry_alert_days').' days')
                    ->query(fn (Builder $query) => $query->inStock()->expiringWithin())
                    ->toggle(),

                Tables\Filters\Filter::make('expired')
                    ->label('Already expired')
                    ->query(fn (Builder $query) => $query->expired())
                    ->toggle(),

                Tables\Filters\Filter::make('in_stock')
                    ->label('In stock only')
                    ->query(fn (Builder $query) => $query->inStock())
                    ->default()
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('dispose')
                    ->label('Dispose')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (ProductBatch $record): bool => $record->quantity > 0
                        && (auth()->user()?->isManagement() ?? false))
                    ->form([
                        Forms\Components\Placeholder::make('on_hand')
                            ->label('Quantity on hand')
                            ->content(fn (ProductBatch $record): string => (string) $record->quantity),

                        Forms\Components\Select::make('type')
                            ->label('Reason')
                            ->options([
                                MovementType::EXPIRED->value => 'Expired',
                                MovementType::DAMAGED->value => 'Damaged',
                            ])
                            ->default(MovementType::EXPIRED->value)
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('quantity')
                            ->label('Quantity to dispose')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->maxValue(fn (ProductBatch $record): int => $record->quantity),

                        Forms\Components\Textarea::make('remarks')
                            ->rows(2)
                            ->placeholder('Expired items disposal'),
                    ])
                    ->action(function (ProductBatch $record, array $data, InventoryService $inventory) {
                        $inventory->dispose(
                            batch: $record,
                            quantity: (int) $data['quantity'],
                            type: MovementType::from($data['type']),
                            remarks: $data['remarks'] ?? null,
                        );

                        Notification::make()
                            ->title('Disposal recorded')
                            ->body("{$data['quantity']} unit(s) written off from batch {$record->batch_number}.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('expiration_date')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageProductBatches::route('/'),
        ];
    }
}
