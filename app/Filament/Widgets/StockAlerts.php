<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PurchaseResource;
use App\Models\BranchInventory;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything at or below its reorder level, most urgent first.
 */
class StockAlerts extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Stock alerts';

    /** Store-wide figures — management only, never the floor. */
    public static function canView(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    /** Everything at or below its reorder level, before the display limit. */
    protected static function lowStockQuery(): Builder
    {
        return BranchInventory::query()
            ->whereRaw('quantity <= coalesce(minimum_stock, (select minimum_stock from products where products.id = branch_inventories.product_id))')
            ->orderBy('quantity');
    }

    /**
     * The branch a "Reorder all" would buy for.
     *
     * A purchase order has exactly one receiving branch, so this cannot sweep
     * up every branch at once. The buyer's own branch is the answer when they
     * have one; an administrator who sees every branch buys for whichever is
     * worst off.
     */
    public function targetBranchId(): ?int
    {
        return auth()->user()?->branch_id
            ?: static::lowStockQuery()->value('branch_id');
    }

    /** The lines a "Reorder all" would open the order with. */
    public function reorderAllLines(): Collection
    {
        $branchId = $this->targetBranchId();

        if (! $branchId) {
            return new Collection;
        }

        return static::lowStockQuery()
            ->with('product')
            ->where('branch_id', $branchId)
            ->get()
            // A line whose product has since been deleted has nothing to buy.
            ->filter(fn (BranchInventory $record): bool => $record->product !== null)
            ->values();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::lowStockQuery()->with(['product.packs', 'product.medicine'])->limit(8))
            ->paginated(false)
            ->headerActions([
                Tables\Actions\Action::make('reorderAll')
                    ->label(fn (): string => 'Reorder all ('.$this->reorderAllLines()->count().')')
                    ->icon('heroicon-o-shopping-bag')
                    ->button()
                    ->visible(fn (): bool => $this->reorderAllLines()->isNotEmpty())
                    // Drafting an order for a dozen products off one click is
                    // worth a sentence saying that is what is about to happen.
                    ->requiresConfirmation()
                    ->modalHeading('Start a purchase order for everything low')
                    ->modalDescription(function (): string {
                        $lines = $this->reorderAllLines();
                        $branch = $lines->first()?->branch?->name;

                        return 'This opens a new purchase order with '.$lines->count()
                            .' product'.($lines->count() === 1 ? '' : 's')
                            .($branch ? ' for '.$branch : '')
                            .', each at its suggested quantity. Nothing is ordered and no '
                            .'stock moves until you choose a supplier and save it.';
                    })
                    ->modalSubmitActionLabel('Open the order')
                    ->url(fn (): string => PurchaseResource::getUrl('create', [
                        'items' => $this->reorderAllLines()
                            ->map(fn (BranchInventory $record): string => $record->product_id.':'.$record->suggested_reorder_units)
                            ->join(','),
                        'branch' => $this->targetBranchId(),
                    ])),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->weight('semibold')
                    ->description(fn (BranchInventory $record): string => $record->quantity <= 0
                        ? 'Out of stock'
                        : ($record->product?->formatPieces((int) $record->quantity) ?? $record->quantity).' left')
                    ->color(fn (BranchInventory $record): string => $record->quantity <= 0 ? 'danger' : 'warning'),

                Tables\Columns\TextColumn::make('reorder_level')
                    ->label('Reorder at')
                    ->alignCenter()
                    ->description(fn (BranchInventory $record): ?string => $record->product?->isDivisible()
                        ? $record->product->formatPieces((int) $record->reorder_level)
                        : null),
            ])
            ->actions([
                Tables\Actions\Action::make('reorder')
                    ->label('Reorder')
                    ->button()
                    ->url(fn (BranchInventory $record): string => PurchaseResource::getUrl('create', [
                        'items' => $record->product_id.':'.$record->suggested_reorder_units,
                        'branch' => $record->branch_id,
                    ])),
            ])
            ->emptyStateHeading('No stock alerts')
            ->emptyStateDescription('Every product is above its reorder level.');
    }
}
