<?php

namespace App\Filament\Resources;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\InventoryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Purchase orders';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference_number';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Purchase::query()->where('status', PurchaseStatus::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Order header')
                ->schema([
                    Forms\Components\Select::make('supplier_id')
                        ->label('Supplier')
                        ->relationship('supplier', 'name', fn (Builder $query) => $query->where('is_active', true))
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('branch_id')
                        ->label('Receiving branch')
                        ->relationship('branch', 'name')
                        ->default(fn (): mixed => request()->integer('branch') ?: auth()->user()?->branch_id)
                        ->disabled(fn (): bool => ! auth()->user()->seesAllBranches())
                        ->dehydrated()
                        ->required(),

                    Forms\Components\DatePicker::make('purchase_date')
                        ->label('Purchase date')
                        ->default(now())
                        ->native(false)
                        ->required(),

                    Forms\Components\Placeholder::make('reference_number')
                        ->label('Reference no.')
                        ->content(fn (?Purchase $record): string => $record?->reference_number ?? 'Generated on save'),
                ])
                ->columns(4),

            Forms\Components\Section::make('Items')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Product')
                                ->options(fn () => Product::active()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set, $state) {
                                    if ($product = Product::find($state)) {
                                        $set('cost_price', $product->cost_price);
                                    }
                                })
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('batch_number')
                                ->label('Batch no.')
                                ->maxLength(60),

                            Forms\Components\DatePicker::make('expiration_date')
                                ->label('Expires')
                                ->native(false),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Qty')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true)
                                // Deliveries are booked in whole units and
                                // multiplied by the pack size on receipt, so
                                // the unit is spelled out beside the number.
                                ->suffix(fn (Forms\Get $get): ?string => Product::find($get('product_id'))?->unit),

                            Forms\Components\TextInput::make('cost_price')
                                ->label('Unit cost')
                                ->numeric()
                                ->prefix('₱')
                                ->required()
                                ->live(onBlur: true),

                            Forms\Components\Placeholder::make('line_total')
                                ->label('Subtotal')
                                ->content(fn (Forms\Get $get): string => '₱'.number_format(
                                    ((float) $get('quantity')) * ((float) $get('cost_price')),
                                    2
                                )),
                        ])
                        ->columns(7)
                        ->defaultItems(1)
                        ->default(fn (): array => static::reorderDefaultItems())
                        ->addActionLabel('Add item')
                        ->disabled(fn (?Purchase $record): bool => $record?->isReceived() ?? false)
                        ->required(),
                ]),

            Forms\Components\Textarea::make('remarks')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * The opening lines of the Items repeater.
     *
     * The Reorder buttons on the dashboard's stock alerts hand over what ran
     * short and how much of it to buy, so the order opens ready to price
     * rather than blank — one product from a row's button, or every low line
     * at once from "Reorder all". Anywhere else the form is reached, this is
     * the usual single empty row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function reorderDefaultItems(): array
    {
        $requested = static::requestedReorderLines();

        if ($requested === []) {
            return [[]];
        }

        $products = Product::active()->findMany(array_keys($requested))->keyBy('id');
        $lines = [];

        foreach ($requested as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product) {
                continue;
            }

            $lines[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'cost_price' => $product->cost_price,
            ];
        }

        // An inactive or deleted product leaves nothing to open with.
        return $lines ?: [[]];
    }

    /**
     * Reorder lines named in the URL, as product id => whole units.
     *
     * "items=7:2,12:1" is what the stock alerts widget builds. The older
     * single-product form is still honoured so an existing link keeps working.
     *
     * @return array<int, int>
     */
    protected static function requestedReorderLines(): array
    {
        $items = trim((string) request()->query('items', ''));

        if ($items !== '') {
            $lines = [];

            foreach (explode(',', $items) as $pair) {
                [$id, $quantity] = array_pad(explode(':', $pair, 2), 2, '1');

                if ((int) $id > 0) {
                    $lines[(int) $id] = max(1, (int) $quantity);
                }
            }

            return $lines;
        }

        $product = request()->integer('product');

        return $product > 0
            ? [$product => max(1, request()->integer('quantity'))]
            : [];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'branch', 'creator'])->withCount('items'))
            ->columns([
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Reference')
                    ->searchable()
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Date')
                    ->date('d M Y')
                    // The date column holds no time, so the hour an order was
                    // encoded is the only thing that separates two orders on
                    // the same day. Shown in Philippine time, like everything.
                    ->description(fn (Purchase $record): ?string => $record->created_at?->format('g:i A'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('purchase_date', $direction)
                        ->orderBy('created_at', $direction)
                        ->orderBy('id', $direction)),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->description(fn (Purchase $record): ?string => $record->branch?->name),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Lines')
                    ->badge()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Encoded by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(PurchaseStatus::class),

                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('receive')
                    ->label('Receive')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Receive this delivery?')
                    ->modalDescription('Batches will be created and stock levels updated. This cannot be undone.')
                    ->visible(fn (Purchase $record): bool => $record->status === PurchaseStatus::PENDING)
                    ->action(function (Purchase $record, InventoryService $inventory) {
                        try {
                            $inventory->receivePurchase($record);

                            Notification::make()
                                ->title('Delivery received')
                                ->body("Stock updated from {$record->reference_number}.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not receive delivery')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('print')
                    ->label('Print')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (Purchase $record): string => route('purchases.print', $record))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Purchase $record): bool => $record->status !== PurchaseStatus::RECEIVED),
            ])
            // Newest first, and nothing else gets a say — a pending order
            // encoded this afternoon outranks one received this morning.
            // Same-day orders tie on the date column alone, so the time it
            // was encoded breaks it, and the id breaks that.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('purchase_date')
                ->orderByDesc('created_at')
                ->orderByDesc('id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }
}
