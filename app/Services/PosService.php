<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a cart into a Sale: prices it, takes the money, and moves the stock.
 */
class PosService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * Price a cart without touching the database.
     *
     * @param  array<int, array{product_id:int, quantity:int, unit?:string, unit_price:float, discount_percent?:float}>  $cart
     * @return array{gross: float, line_discount: float, subtotal: float, discount: float, total: float}
     */
    public function totals(array $cart, DiscountType $discountType = DiscountType::NONE, float $discountPercent = 0): array
    {
        $gross = 0.0;
        $lineDiscount = 0.0;

        foreach ($cart as $line) {
            $lineGross = (float) $line['unit_price'] * (int) $line['quantity'];
            $lineOff = $lineGross * ((float) ($line['discount_percent'] ?? 0) / 100);

            $gross += $lineGross;
            $lineDiscount += $lineOff;
        }

        $subtotal = round($gross - $lineDiscount, 2);

        /*
         * Senior and PWD carry their statutory percentage; a regular sale takes
         * whatever the cashier keyed in. Either way it comes straight off the
         * shelf price — prices are what the customer pays, with no tax split
         * out of them.
         *
         * A hundred percent is the whole ticket and there is nothing past it.
         * The manual box is clamped here rather than trusted, because the
         * browser's max attribute does not stop a mis-keyed 500 from reaching
         * this method, and 500 would hand the customer money.
         */
        $percent = $discountType->isStatutory()
            ? $discountType->percentage()
            : min(100, max(0, $discountPercent));

        $saleDiscount = round($subtotal * ($percent / 100), 2);

        return [
            'gross' => round($gross, 2),
            'line_discount' => round($lineDiscount, 2),
            'subtotal' => $subtotal,
            'discount' => round($lineDiscount + $saleDiscount, 2),
            'total' => round($subtotal - $saleDiscount, 2),
        ];
    }

    /**
     * Commit the sale: writes the invoice, its lines, and the stock movements
     * in one transaction. Anything short on stock aborts the whole thing.
     *
     * @param  array<int, array{product_id:int, quantity:int, unit?:string, unit_price:float, discount_percent?:float}>  $cart
     */
    public function checkout(
        array $cart,
        int $branchId,
        float $amountPaid,
        PaymentMethod $paymentMethod = PaymentMethod::CASH,
        DiscountType $discountType = DiscountType::NONE,
        float $discountPercent = 0,
        ?string $customerName = null,
        ?string $discountIdNumber = null,
        ?string $paymentReference = null,
    ): Sale {
        if (empty($cart)) {
            throw new RuntimeException('The cart is empty.');
        }

        $totals = $this->totals($cart, $discountType, $discountPercent);

        if ($paymentMethod === PaymentMethod::CASH && $amountPaid + 0.001 < $totals['total']) {
            throw new RuntimeException('Cash received is less than the amount due.');
        }

        $paymentReference = $this->normaliseReference($paymentMethod, $paymentReference);

        return DB::transaction(function () use (
            $cart, $branchId, $amountPaid, $paymentMethod, $paymentReference, $discountType,
            $customerName, $discountIdNumber, $totals
        ) {
            $sale = Sale::create([
                'branch_id' => $branchId,
                'user_id' => auth()->id(),
                'sale_date' => now(),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'total_amount' => $totals['total'],
                'amount_paid' => $amountPaid,
                'change_amount' => max(0, round($amountPaid - $totals['total'], 2)),
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'status' => SaleStatus::COMPLETED,
                'customer_name' => $customerName,
                'discount_type' => $discountType,
                'discount_id_number' => $discountIdNumber,
            ]);

            foreach ($cart as $line) {
                $product = Product::with('packs')->findOrFail($line['product_id']);
                $quantity = (int) $line['quantity'];
                $unitPrice = (float) $line['unit_price'];
                $linePercent = (float) ($line['discount_percent'] ?? 0);
                $lineGross = $unitPrice * $quantity;
                $lineDiscount = round($lineGross * ($linePercent / 100), 2);

                // The customer buys units; the shelf gives up pieces.
                $sellingUnit = $product->sellingOption($line['unit'] ?? null);
                $multiplier = $sellingUnit->pieces;
                $pieces = $quantity * $multiplier;

                if ($product->is_batch_tracked) {
                    $allocation = $this->inventory->allocateFefo($branchId, $product->id, $pieces);
                } else {
                    $available = $this->inventory->availableStock($branchId, $product->id);

                    if ($available < $pieces) {
                        throw new RuntimeException(
                            "Only {$product->formatPieces($available)} left of {$product->name}."
                        );
                    }

                    $allocation = [['batch' => null, 'quantity' => $pieces]];
                }

                // One line per cart row. A box may be drawn from two lots when
                // the first runs dry, but the customer bought one box at one
                // price, so the receipt says so and the batch shown is
                // whichever lot supplied most of it. The stock movements below
                // keep the per-lot truth.
                $primary = collect($allocation)->sortByDesc('quantity')->first();

                // Costing is captured per box; restate it per unit sold so
                // margin on three loose tablets is not three boxes' worth.
                $boxCost = (float) ($primary['batch']?->cost_price ?? $product->cost_price);
                $unitCost = round($boxCost / max(1, (int) $product->pieces_per_box) * $multiplier, 2);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_batch_id' => $primary['batch']?->id,
                    'quantity' => $quantity,
                    'unit_label' => $sellingUnit->label,
                    'unit_multiplier' => $multiplier,
                    'unit_price' => $unitPrice,
                    'cost_price' => $unitCost,
                    'discount' => $lineDiscount,
                    'subtotal' => round($lineGross - $lineDiscount, 2),
                ]);

                foreach ($allocation as $part) {
                    $this->inventory->record(
                        branchId: $branchId,
                        productId: $product->id,
                        type: MovementType::SALE,
                        quantity: -$part['quantity'],
                        batch: $part['batch'],
                        referenceType: Sale::class,
                        referenceId: $sale->id,
                        remarks: "Sold on {$sale->invoice_number}",
                    );
                }
            }

            return $sale->load('items.product', 'cashier', 'branch');
        });
    }

    /**
     * Check the transaction reference a non-cash sale has to carry.
     *
     * The number is what ties the sale to the wallet or terminal statement at
     * close, so it is checked here as well as on the till screen — a stale
     * page or a direct call must not be able to slip a GCash sale through
     * without one. Cash keeps nothing; there is no number to keep.
     */
    protected function normaliseReference(PaymentMethod $paymentMethod, ?string $reference): ?string
    {
        if (! $paymentMethod->requiresReference()) {
            return null;
        }

        $reference = preg_replace('/[\s-]+/', '', (string) $reference);

        if ($reference === '') {
            throw new RuntimeException(
                'Enter the '.$paymentMethod->referenceLabel().' before completing the sale.'
            );
        }

        if (! ctype_digit($reference)) {
            throw new RuntimeException(
                $paymentMethod->referenceLabel().' must be numbers only.'
            );
        }

        return $reference;
    }

    /**
     * Void a completed sale and put every unit back where it came from.
     */
    public function void(Sale $sale, ?string $reason = null): Sale
    {
        if (! $sale->isVoidable()) {
            throw new RuntimeException('Only completed sales can be voided.');
        }

        return DB::transaction(function () use ($sale, $reason) {
            // Reverse what actually left the shelf, lot by lot, rather than
            // working back from the sale lines — a line may have drawn on two
            // batches, and each one is owed its own pieces back.
            $sold = StockMovement::withoutGlobalScopes()
                ->with('batch')
                ->where('reference_type', Sale::class)
                ->where('reference_id', $sale->id)
                ->where('type', MovementType::SALE)
                ->get();

            foreach ($sold as $movement) {
                $this->inventory->record(
                    branchId: $sale->branch_id,
                    productId: $movement->product_id,
                    type: MovementType::RETURN,
                    quantity: abs($movement->quantity),
                    batch: $movement->batch,
                    referenceType: Sale::class,
                    referenceId: $sale->id,
                    remarks: "Void of {$sale->invoice_number}".($reason ? ": {$reason}" : ''),
                );
            }

            $sale->update([
                'status' => SaleStatus::VOIDED,
                'remarks' => trim(($sale->remarks ?? '')."\nVoided: ".($reason ?? 'no reason given')),
            ]);

            return $sale;
        });
    }

    /**
     * Look up a product by scanned QR/barcode or typed name.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    public function search(string $term, int $branchId, int $limit = 8)
    {
        return Product::query()
            ->active()
            ->with(['medicine', 'brand'])
            ->searchTerm($term)
            ->withSum(
                ['inventories as stock_on_hand' => fn ($q) => $q->where('branch_id', $branchId)],
                'quantity'
            )
            ->limit($limit)
            ->get();
    }
}
