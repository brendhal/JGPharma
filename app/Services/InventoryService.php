<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Enums\TransferStatus;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every change to stock goes through here.
 *
 * Nothing else in the app writes to branch_inventories or product_batches
 * directly, which is what guarantees the stock_movements log always explains
 * the current on-hand figure.
 */
class InventoryService
{
    /**
     * Apply a single signed movement and log it.
     *
     * @param  int  $quantity  positive to add stock, negative to remove it
     */
    public function record(
        int $branchId,
        int $productId,
        MovementType $type,
        int $quantity,
        ?ProductBatch $batch = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $remarks = null,
    ): StockMovement {
        if ($quantity === 0) {
            throw new RuntimeException('A stock movement cannot be zero.');
        }

        return DB::transaction(function () use (
            $branchId, $productId, $type, $quantity, $batch, $referenceType, $referenceId, $remarks
        ) {
            if ($batch) {
                $batch->refresh();

                if ($quantity < 0 && $batch->quantity < abs($quantity)) {
                    throw new RuntimeException(
                        "Batch {$batch->batch_number} only has {$batch->quantity} left."
                    );
                }

                $batch->quantity += $quantity;
                $batch->save();
            }

            $inventory = $this->inventoryFor($branchId, $productId);
            $inventory->quantity += $quantity;
            $inventory->save();

            return StockMovement::create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'product_batch_id' => $batch?->id,
                'user_id' => auth()->id(),
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $inventory->quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'remarks' => $remarks,
            ]);
        });
    }

    /**
     * Pick batches to sell from, first-expiring first.
     *
     * @return array<int, array{batch: ProductBatch, quantity: int}>
     */
    public function allocateFefo(int $branchId, int $productId, int $quantity): array
    {
        $batches = ProductBatch::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->inStock()
            ->where(function ($q) {
                $q->whereNull('expiration_date')->orWhereDate('expiration_date', '>=', today());
            })
            ->fefo()
            ->get();

        $allocation = [];
        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $batch->quantity);
            $allocation[] = ['batch' => $batch, 'quantity' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $product = Product::find($productId);

            throw new RuntimeException(
                "Not enough unexpired stock for {$product?->name}. Short by {$remaining}."
            );
        }

        return $allocation;
    }

    /** On-hand quantity for a product at a branch. */
    public function availableStock(int $branchId, int $productId): int
    {
        return (int) BranchInventory::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->value('quantity') ?? 0;
    }

    /**
     * Receive a purchase order: create a batch per line and add the stock.
     */
    public function receivePurchase(Purchase $purchase): void
    {
        if ($purchase->status === PurchaseStatus::RECEIVED) {
            throw new RuntimeException('This purchase has already been received.');
        }

        DB::transaction(function () use ($purchase) {
            foreach ($purchase->items()->with('product')->get() as $item) {
                $batch = null;

                if ($item->product->is_batch_tracked) {
                    $batch = ProductBatch::withoutGlobalScopes()->firstOrCreate(
                        [
                            'branch_id' => $purchase->branch_id,
                            'product_id' => $item->product_id,
                            'batch_number' => $item->batch_number ?: 'NB-'.$purchase->reference_number,
                        ],
                        [
                            'expiration_date' => $item->expiration_date,
                            'quantity' => 0,
                            'cost_price' => $item->cost_price,
                            'received_date' => $purchase->purchase_date,
                            'supplier_id' => $purchase->supplier_id,
                        ]
                    );
                }

                // Deliveries are booked in boxes; the shelf is counted in
                // pieces, so a delivery of 5 boxes of 20 lands as 100 tablets.
                $pieces = $item->quantity * max(1, (int) $item->product->pieces_per_box);

                $this->record(
                    branchId: $purchase->branch_id,
                    productId: $item->product_id,
                    type: MovementType::PURCHASE,
                    quantity: $pieces,
                    batch: $batch,
                    referenceType: Purchase::class,
                    referenceId: $purchase->id,
                    remarks: "Received on {$purchase->reference_number} from {$purchase->supplier->name}",
                );

                // Keep the product's costing current with the latest delivery.
                if ((float) $item->cost_price > 0) {
                    $item->product->update(['cost_price' => $item->cost_price]);
                }
            }

            $purchase->update(['status' => PurchaseStatus::RECEIVED]);
        });
    }

    /**
     * Write off stock that expired or was damaged.
     */
    public function dispose(ProductBatch $batch, int $quantity, MovementType $type, ?string $remarks = null): StockMovement
    {
        if (! in_array($type, MovementType::disposalCases(), true)) {
            throw new RuntimeException('Disposals must be recorded as EXPIRED or DAMAGED.');
        }

        return $this->record(
            branchId: $batch->branch_id,
            productId: $batch->product_id,
            type: $type,
            quantity: -abs($quantity),
            batch: $batch,
            referenceType: ProductBatch::class,
            referenceId: $batch->id,
            remarks: $remarks ?: $type->getLabel(),
        );
    }

    /**
     * Correct on-hand stock to a counted figure after a physical audit.
     */
    public function adjust(int $branchId, int $productId, int $countedQuantity, ?string $remarks = null, ?ProductBatch $batch = null): ?StockMovement
    {
        $current = $batch
            ? $batch->quantity
            : $this->availableStock($branchId, $productId);

        $delta = $countedQuantity - $current;

        if ($delta === 0) {
            return null;
        }

        return $this->record(
            branchId: $branchId,
            productId: $productId,
            type: MovementType::ADJUSTMENT,
            quantity: $delta,
            batch: $batch,
            referenceType: 'audit',
            referenceId: null,
            remarks: $remarks ?: 'Physical audit correction',
        );
    }

    /**
     * Approve a transfer: stock leaves the sending branch immediately and is
     * held "in transit" until the receiving branch confirms it.
     */
    public function shipTransfer(StockTransfer $transfer): void
    {
        if (! $transfer->isPending()) {
            throw new RuntimeException('Only pending transfers can be approved.');
        }

        $items = $transfer->items()->with('product')->get();

        /*
         * Approving a transfer that carries no lines would move no stock and
         * still mark itself shipped, which reads as a transfer that worked.
         * Refuse it instead.
         */
        if ($items->isEmpty()) {
            throw new RuntimeException(
                'This transfer has no products on it. Edit it and add at least one line before approving.'
            );
        }

        DB::transaction(function () use ($transfer, $items) {
            foreach ($items as $item) {
                /*
                 * A product that is not batch tracked has no rows in
                 * product_batches, so there is nothing to allocate from. Its
                 * branch total is both the only thing to check and the only
                 * thing to move.
                 */
                if (! $item->product->is_batch_tracked) {
                    $onHand = $this->availableStock($transfer->from_branch_id, $item->product_id);

                    if ($onHand < $item->quantity) {
                        throw new RuntimeException(
                            "{$item->product->name} only has {$onHand} on hand at {$transfer->fromBranch->name}."
                        );
                    }

                    $this->record(
                        branchId: $transfer->from_branch_id,
                        productId: $item->product_id,
                        type: MovementType::TRANSFER_OUT,
                        quantity: -$item->quantity,
                        referenceType: StockTransfer::class,
                        referenceId: $transfer->id,
                        remarks: "Transfer {$transfer->transfer_number} to {$transfer->toBranch->name}",
                    );

                    continue;
                }

                $allocation = $item->product_batch_id
                    ? [['batch' => $item->batch, 'quantity' => $item->quantity]]
                    : $this->allocateFefo($transfer->from_branch_id, $item->product_id, $item->quantity);

                foreach ($allocation as $line) {
                    $this->record(
                        branchId: $transfer->from_branch_id,
                        productId: $item->product_id,
                        type: MovementType::TRANSFER_OUT,
                        quantity: -$line['quantity'],
                        batch: $line['batch'],
                        referenceType: StockTransfer::class,
                        referenceId: $transfer->id,
                        remarks: "Transfer {$transfer->transfer_number} to {$transfer->toBranch->name}",
                    );
                }
            }

            $transfer->update([
                'status' => TransferStatus::APPROVED,
                'approved_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Confirm arrival: mirror the batches into the receiving branch.
     */
    public function receiveTransfer(StockTransfer $transfer): void
    {
        if (! $transfer->isApproved()) {
            throw new RuntimeException('Only approved transfers can be received.');
        }

        DB::transaction(function () use ($transfer) {
            foreach ($transfer->items()->with(['product', 'batch'])->get() as $item) {
                $batch = null;

                if ($item->product->is_batch_tracked) {
                    $source = $item->batch;

                    $batch = ProductBatch::withoutGlobalScopes()->firstOrCreate(
                        [
                            'branch_id' => $transfer->to_branch_id,
                            'product_id' => $item->product_id,
                            'batch_number' => $source?->batch_number ?: 'TRF-'.$transfer->transfer_number,
                        ],
                        [
                            'expiration_date' => $source?->expiration_date,
                            'quantity' => 0,
                            'cost_price' => $source?->cost_price ?? $item->product->cost_price,
                            'received_date' => today(),
                            'supplier_id' => $source?->supplier_id,
                        ]
                    );
                }

                $this->record(
                    branchId: $transfer->to_branch_id,
                    productId: $item->product_id,
                    type: MovementType::TRANSFER_IN,
                    quantity: $item->quantity,
                    batch: $batch,
                    referenceType: StockTransfer::class,
                    referenceId: $transfer->id,
                    remarks: "Transfer {$transfer->transfer_number} from {$transfer->fromBranch->name}",
                );
            }

            $transfer->update([
                'status' => TransferStatus::RECEIVED,
                'received_date' => today(),
            ]);
        });
    }

    /** Fetch (or create) the running total row for a product at a branch. */
    protected function inventoryFor(int $branchId, int $productId): BranchInventory
    {
        return BranchInventory::withoutGlobalScopes()->firstOrCreate(
            ['branch_id' => $branchId, 'product_id' => $productId],
            ['quantity' => 0],
        );
    }
}
