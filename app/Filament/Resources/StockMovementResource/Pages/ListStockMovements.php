<?php

namespace App\Filament\Resources\StockMovementResource\Pages;

use App\Enums\MovementType;
use App\Filament\Resources\StockMovementResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected static ?string $title = 'Stock & inventory control';

    /**
     * Stock leaves from here; it arrives somewhere else.
     *
     * Receiving a delivery used to be possible from this screen as well as
     * from the order it belongs to, which meant the same shipment could be
     * booked in twice by two people who each thought they were the one doing
     * it. Deliveries are now received against their purchase order — under
     * Purchasing — and what is left here is what only happens here: writing
     * stock off, and squaring the system with a physical count.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->recordDisposalAction(),
            $this->adjustLevelsAction(),
        ];
    }

    /**
     * Stock Out — write off expired or damaged goods.
     */
    protected function recordDisposalAction(): Actions\Action
    {
        return Actions\Action::make('recordDisposal')
            ->label('Record disposal')
            ->icon('heroicon-o-trash')
            ->color('gray')
            ->modalHeading('Record expired or damaged stock')
            ->form([
                Forms\Components\Select::make('product_batch_id')
                    ->label('Batch')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => ProductBatch::query()
                        ->inStock()
                        ->with('product')
                        ->where(fn ($q) => $q
                            ->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                            ->orWhere('batch_number', 'like', "%{$search}%"))
                        ->limit(25)
                        ->get()
                        ->mapWithKeys(fn (ProductBatch $batch) => [
                            $batch->id => "{$batch->product->name} — {$batch->batch_number} ({$batch->quantity} left)",
                        ]))
                    ->getOptionLabelUsing(function ($value): ?string {
                        $batch = ProductBatch::with('product')->find($value);

                        return $batch ? "{$batch->product->name} — {$batch->batch_number}" : null;
                    })
                    ->helperText('Search by product name or batch number.'),

                Forms\Components\Select::make('type')
                    ->label('Reason')
                    ->options([
                        MovementType::EXPIRED->value => 'Expired',
                        MovementType::DAMAGED->value => 'Damaged',
                    ])
                    ->default(MovementType::EXPIRED->value)
                    ->native(false)
                    ->required(),

                Forms\Components\TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                Forms\Components\Textarea::make('remarks')
                    ->rows(2)
                    ->placeholder('Expired items disposal'),
            ])
            ->action(function (array $data, InventoryService $inventory) {
                $batch = ProductBatch::findOrFail($data['product_batch_id']);

                $inventory->dispose(
                    batch: $batch,
                    quantity: (int) $data['quantity'],
                    type: MovementType::from($data['type']),
                    remarks: $data['remarks'] ?? null,
                );

                Notification::make()
                    ->title('Disposal recorded')
                    ->body("{$data['quantity']} unit(s) removed from batch {$batch->batch_number}.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Adjustment — reconcile the system with a physical count.
     */
    protected function adjustLevelsAction(): Actions\Action
    {
        return Actions\Action::make('adjustLevels')
            ->label('Adjust levels')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->modalHeading('Inventory adjustment')
            ->modalDescription('Use after a physical audit. The difference is logged as an ADJUSTMENT movement.')
            ->form([
                Forms\Components\Select::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->default(auth()->user()?->branch_id)
                    ->disabled(fn (): bool => ! auth()->user()->seesAllBranches())
                    ->dehydrated()
                    ->required()
                    ->live()
                    ->helperText(fn (): ?string => auth()->user()->seesAllBranches()
                        ? 'Adjustments are counted against one branch at a time.'
                        : null),

                Forms\Components\Select::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live(),

                Forms\Components\Placeholder::make('system_quantity')
                    ->label('System quantity')
                    ->content(function (Forms\Get $get, InventoryService $inventory): string {
                        $productId = $get('product_id');
                        $branchId = $get('branch_id');

                        if (! $productId || ! $branchId) {
                            return '—';
                        }

                        return (string) $inventory->availableStock((int) $branchId, (int) $productId);
                    }),

                Forms\Components\TextInput::make('counted_quantity')
                    ->label('Counted quantity')
                    ->numeric()
                    ->required(),

                Forms\Components\Textarea::make('remarks')
                    ->label('Reason')
                    ->rows(2)
                    ->required()
                    ->placeholder('Physical audit discrepancy'),
            ])
            ->action(function (array $data, InventoryService $inventory) {
                $movement = $inventory->adjust(
                    branchId: (int) $data['branch_id'],
                    productId: (int) $data['product_id'],
                    countedQuantity: (int) $data['counted_quantity'],
                    remarks: $data['remarks'],
                );

                Notification::make()
                    ->title($movement ? 'Adjustment recorded' : 'Nothing to adjust')
                    ->body($movement
                        ? "Logged {$movement->signed_quantity} against the system count."
                        : 'The counted quantity already matches the system.')
                    ->success()
                    ->send();
            });
    }
}
