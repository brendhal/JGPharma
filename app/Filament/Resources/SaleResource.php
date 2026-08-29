<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Filament\Resources\SaleResource\Pages;
use App\Models\Sale;
use App\Services\PosService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Point of Sale';

    protected static ?string $navigationLabel = 'Sales history';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function canCreate(): bool
    {
        // Sales are created at the POS terminal, not through a form.
        return false;
    }

    /**
     * A management screen now.
     *
     * The till is a cashier's whole job: ring the sale up, hand over the
     * receipt, take the next customer. Browsing back through the day was one
     * more place to be than the work needs, so the history — and with it
     * every ticket's totals — belongs to management. A cashier who has to
     * reprint a receipt does it from the POS itself.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    /**
     * Belt and braces behind canViewAny().
     *
     * Only management reaches this resource at all, but if that ever changes
     * the scoping should already be right rather than newly forgotten.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if ($user && ! $user->isManagement()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['cashier', 'branch'])->withCount('items'))
            ->columns([
                Tables\Columns\TextColumn::make('sale_date')
                    ->label('Time')
                    ->dateTime('d M Y g:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->searchable()
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->badge()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Customer')
                    ->placeholder('Walk-in')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('discount')
                    ->label('Discount')
                    ->money('PHP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total amount')
                    ->money('PHP')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('PHP')->label('Total')),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Payment')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('payment_reference')
                    ->label('Reference no.')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('cashier.name')
                    ->label('Cashier')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(SaleStatus::class),

                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Payment method')
                    ->options(PaymentMethod::class),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Cashier')
                    ->relationship('cashier', 'name')
                    ->searchable(),

                Tables\Filters\Filter::make('period')
                    ->form([
                        Forms\Components\DatePicker::make('from')->native(false),
                        Forms\Components\DatePicker::make('until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('sale_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('sale_date', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('print')
                    ->label('Receipt')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (Sale $record): string => route('receipts.show', $record))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Sale $record): bool => $record->isVoidable() && auth()->user()->isManagement())
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason for voiding')
                            ->required()
                            ->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Every item on this invoice goes back into stock.')
                    ->action(function (Sale $record, array $data, PosService $pos) {
                        try {
                            $pos->void($record, $data['reason']);

                            Notification::make()
                                ->title('Sale voided')
                                ->body("{$record->invoice_number} was reversed and stock restored.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not void sale')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('sale_date', 'desc')
            ->striped()
            ->poll('30s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Invoice')
                ->schema([
                    Infolists\Components\TextEntry::make('invoice_number')->label('Invoice no.'),
                    Infolists\Components\TextEntry::make('sale_date')->dateTime('d M Y g:i A'),
                    Infolists\Components\TextEntry::make('cashier.name')->label('Cashier'),
                    Infolists\Components\TextEntry::make('branch.name')->label('Branch'),
                    Infolists\Components\TextEntry::make('customer_name')->label('Customer')->placeholder('Walk-in'),
                    Infolists\Components\TextEntry::make('discount_type')->label('Discount type')->badge(),
                    Infolists\Components\TextEntry::make('payment_method')->badge(),
                    Infolists\Components\TextEntry::make('payment_reference')
                        ->label('Reference no.')
                        ->placeholder('—')
                        ->copyable(),
                    Infolists\Components\TextEntry::make('status')->badge(),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Items')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('product.name')->label('Product')->columnSpan(2),
                            Infolists\Components\TextEntry::make('batch.batch_number')->label('Batch')->placeholder('—'),
                            Infolists\Components\TextEntry::make('quantity_label')->label('Qty'),
                            Infolists\Components\TextEntry::make('unit_price')->label('Unit price')->money('PHP'),
                            Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('PHP'),
                        ])
                        ->columns(6),
                ]),

            Infolists\Components\Section::make('Totals')
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->money('PHP'),
                    Infolists\Components\TextEntry::make('discount')->money('PHP'),
                    Infolists\Components\TextEntry::make('total_amount')->label('Total')->money('PHP')->weight('bold'),
                    Infolists\Components\TextEntry::make('amount_paid')->label('Paid')->money('PHP'),
                    Infolists\Components\TextEntry::make('change_amount')->label('Change')->money('PHP'),
                ])
                ->columns(6),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'view' => Pages\ViewSale::route('/{record}'),
        ];
    }
}
