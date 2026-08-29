<?php

use App\Enums\PurchaseStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PurchaseResource\Pages\ListPurchases;
use App\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

/**
 * One door for stock coming in.
 *
 * Receiving a delivery used to be possible from Stock & inventory control as
 * well as from the purchase order it belongs to, so the same shipment could
 * be booked in twice by two people who each thought they were the one doing
 * it. Stock control keeps what only happens there — disposals and physical
 * counts — and deliveries are received against their order.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->supplier = Supplier::create(['name' => 'MediSupply Ph', 'is_active' => true]);

    $this->product = Product::create([
        'sku' => 'BIOG-500',
        'name' => 'Biogesic 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 60,
        'selling_price' => 100,
        'is_batch_tracked' => true,
    ]);
});

/* ---------------------------------------------------------- Stock control */

test('stock control no longer offers Receive goods', function () {
    Livewire::actingAs($this->admin)
        ->test(ListStockMovements::class)
        ->assertActionDoesNotExist('receiveGoods');
});

test('stock control keeps the actions that only happen there', function (string $action) {
    Livewire::actingAs($this->admin)
        ->test(ListStockMovements::class)
        ->assertActionExists($action);
})->with(['recordDisposal', 'adjustLevels']);

test('the Receive goods button is gone from the page itself', function () {
    $this->actingAs($this->admin)
        ->get(ListStockMovements::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Record disposal')
        ->assertSee('Adjust levels')
        ->assertDontSee('Receive goods');
});

/* ------------------------------------------------------- Purchasing still */

test('a delivery is still received against its purchase order', function () {
    $purchase = Purchase::create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $this->supplier->id,
        'purchase_date' => now(),
    ]);

    $purchase->items()->create([
        'product_id' => $this->product->id,
        'batch_number' => 'B-001',
        'expiration_date' => now()->addYear(),
        'quantity' => 20,
        'cost_price' => 60,
    ]);

    $this->actingAs($this->admin);

    Livewire::actingAs($this->admin)
        ->test(ListPurchases::class)
        ->callTableAction('receive', $purchase);

    expect($purchase->fresh()->status)->toBe(PurchaseStatus::RECEIVED)
        ->and((int) $this->product->batches()->sum('quantity'))->toBe(20);
});
