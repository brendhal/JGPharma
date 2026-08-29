<?php

namespace App\Filament\Resources;

use App\Enums\MovementType;
use App\Filament\Resources\DisposalResource\Pages;
use App\Models\StockMovement;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the shop has thrown away.
 *
 * The same stock_movements rows Stock control shows, narrowed to the two
 * types that mean loss — expired and damaged — and priced, because the point
 * of looking at write-offs is finding out what they cost.
 */
class DisposalResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static ?string $slug = 'disposals';

    protected static ?string $navigationIcon = 'heroicon-o-trash';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Disposals';

    protected static ?string $modelLabel = 'disposal';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public static function canCreate(): bool
    {
        // Written off through Batches & expiry, never typed in here.
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->disposals();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Write-offs so far this month';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'product.packs', 'batch.supplier', 'user', 'branch',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y')
                    ->description(fn (StockMovement $record): ?string => $record->created_at?->format('g:i A'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (StockMovement $record): ?string => $record->batch
                        ? 'Batch '.$record->batch->batch_number
                            .($record->batch->expiration_date
                                ? ' · exp '.$record->batch->expiration_date->format('m/Y')
                                : '')
                        : null),

                // Who to claim the refund or replacement from. The link runs
                // through the batch, so stock written off without one has
                // nobody to bill.
                Tables\Columns\TextColumn::make('batch.supplier.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Reason')
                    ->badge(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Disposed')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger')
                    ->formatStateUsing(fn (int $state): string => number_format(abs($state)))
                    // The piece count is the truth; the boxes-and-blisters
                    // reading underneath is what a person can picture.
                    ->description(fn (StockMovement $record): ?string => $record->product?->isDivisible()
                        ? $record->product->formatPieces($record->pieces_disposed)
                        : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('disposal_value')
                    ->label('Cost lost')
                    ->money('PHP')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger')
                    ->state(fn (StockMovement $record): float => $record->disposal_value)
                    // Summed in PHP, not SQL: the per-piece cost depends on
                    // the product's pack size and the batch it came from.
                    // Filament hands a query builder here, not an Eloquent
                    // one, so the rows are re-fetched by id.
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total')
                            ->using(function ($query): string {
                                $total = StockMovement::query()
                                    ->whereIn('id', $query->clone()->pluck('stock_movements.id'))
                                    ->with(['product', 'batch'])
                                    ->get()
                                    ->sum(fn (StockMovement $movement): float => $movement->disposal_value);

                                return '₱'.number_format($total, 2);
                            })
                    ),

                Tables\Columns\TextColumn::make('remarks')
                    ->label('Note')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),

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
                    ->label('Reason')
                    ->options(collect(MovementType::disposalCases())
                        ->mapWithKeys(fn (MovementType $type): array => [$type->value => $type->getLabel()])
                        ->all()),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => auth()->user()?->seesAllBranches() ?? false),

                Tables\Filters\SelectFilter::make('supplier')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $supplierId) => $q->whereHas(
                            'batch',
                            fn (Builder $batch) => $batch->where('supplier_id', $supplierId)
                        )
                    )),

                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('period')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From')->native(false),
                        Forms\Components\DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'From '.\Carbon\Carbon::parse($data['from'])->format('d M Y');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until '.\Carbon\Carbon::parse($data['until'])->format('d M Y');
                        }

                        return $indicators;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->emptyStateHeading('Nothing written off')
            ->emptyStateDescription('Expired and damaged stock recorded from Batches & expiry shows up here.')
            ->emptyStateIcon('heroicon-o-trash');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisposals::route('/'),
        ];
    }
}
