<?php

namespace App\Filament\Resources;

use App\Enums\TransferStatus;
use App\Filament\Resources\StockTransferResource\Pages;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Branch transfers';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'transfer_number';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Transfer details')
                ->schema([
                    Forms\Components\Select::make('from_branch_id')
                        ->label('From branch')
                        ->options(fn () => Branch::where('is_active', true)->pluck('name', 'id'))
                        ->default(auth()->user()?->branch_id)
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('to_branch_id')
                        ->label('To branch')
                        ->options(fn (Forms\Get $get) => Branch::where('is_active', true)
                            ->when($get('from_branch_id'), fn ($q, $id) => $q->whereKeyNot($id))
                            ->pluck('name', 'id'))
                        ->required(),

                    Forms\Components\DatePicker::make('transfer_date')
                        ->default(now())
                        ->native(false)
                        ->required(),

                    Forms\Components\Placeholder::make('transfer_number')
                        ->label('Transfer no.')
                        ->content(fn (?StockTransfer $record): string => $record?->transfer_number ?? 'Generated on save'),
                ])
                ->columns(4),

            Forms\Components\Section::make('Items to move')
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
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Qty')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required(),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->addActionLabel('Add item')
                        // Only a status we can actually read, and that is
                        // not pending, locks the lines. An unknown status must
                        // never disable this: a disabled repeater skips
                        // saveRelationships() and the lines are lost.
                        ->disabled(fn (?StockTransfer $record): bool => $record?->status !== null
                            && ! $record->isPending())
                        ->required(),
                ]),

            Forms\Components\Textarea::make('remarks')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['fromBranch', 'toBranch', 'requester'])
                ->withCount('items'))
            ->columns([
                Tables\Columns\TextColumn::make('transfer_number')
                    ->label('Transfer no.')
                    ->searchable()
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('transfer_date')
                    ->label('Date')
                    ->date('d M Y')
                    // Date-only column, so same-day transfers would otherwise
                    // tie; the hour it was raised is the tiebreak.
                    ->description(fn (StockTransfer $record): ?string => $record->created_at?->format('g:i A'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('transfer_date', $direction)
                        ->orderBy('created_at', $direction)
                        ->orderBy('id', $direction)),

                Tables\Columns\TextColumn::make('fromBranch.name')
                    ->label('From')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('toBranch.name')
                    ->label('To')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Lines')
                    ->badge()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(TransferStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve & ship')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Stock leaves the sending branch now and sits in transit until received.')
                    ->visible(fn (StockTransfer $record): bool => $record->isPending())
                    ->action(function (StockTransfer $record, InventoryService $inventory) {
                        try {
                            $inventory->shipTransfer($record);

                            Notification::make()
                                ->title('Transfer approved')
                                ->body("{$record->transfer_number} is on its way to {$record->toBranch->name}.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not approve transfer')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('receive')
                    ->label('Confirm receipt')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => $record->isApproved())
                    ->action(function (StockTransfer $record, InventoryService $inventory) {
                        try {
                            $inventory->receiveTransfer($record);

                            Notification::make()
                                ->title('Transfer received')
                                ->body("Stock added to {$record->toBranch->name}.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not receive transfer')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (StockTransfer $record): bool => $record->isPending()),
            ])
            // Newest first whatever the status, with the encoded time
            // breaking same-day ties.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('transfer_date')
                ->orderByDesc('created_at')
                ->orderByDesc('id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockTransfers::route('/'),
            'create' => Pages\CreateStockTransfer::route('/create'),
            'edit' => Pages\EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
