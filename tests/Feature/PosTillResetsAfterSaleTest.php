<?php

use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;

/**
 * The payment panel belongs to the customer in front of the cashier.
 *
 * checkout() reads the total, the tender and the change before it empties the
 * till, and Livewire holds a computed property for the rest of the request
 * once it has been read. Clearing the state alone left those cached figures
 * to repaint the finished sale over an empty cart — the old amount due, the
 * old change, and a Complete sale button still lit for a cart with nothing
 * in it.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'BIOG-500',
        'name' => 'Biogesic 500mg',
        'unit' => 'Box',
        'piece_unit' => 'tab',
        'pieces_per_box' => 100,
        'cost_price' => 60,
        'selling_price' => 100,
        'is_batch_tracked' => false,
        'is_active' => true,
    ]);

    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 1000,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->cashier);

    $this->ringUp = fn () => Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('amountPaid', 500)
        ->call('checkout');
});

test('the sale still completes', function () {
    ($this->ringUp)();

    expect(Sale::count())->toBe(1);
});

test('the amount due goes back to zero once the sale is done', function () {
    $till = ($this->ringUp)();

    expect($till->instance()->totals['total'])->toBe(0.0)
        ->and($till->instance()->totals['subtotal'])->toBe(0.0)
        ->and($till->instance()->totals['discount'])->toBe(0.0);
});

test('the change due does not stay on screen for the next customer', function () {
    $till = ($this->ringUp)();

    expect($till->instance()->changeDue)->toBe(0.0)
        ->and($till->instance()->tendered)->toBe(0.0);
});

test('the complete sale button goes dead on an empty till', function () {
    $till = ($this->ringUp)();

    expect($till->instance()->canCheckout)->toBeFalse();
});

test('the cart and the cash box are both cleared', function () {
    ($this->ringUp)()
        ->assertSet('cart', [])
        ->assertSet('amountPaid', null)
        ->assertSet('discountPercent', 0.0)
        ->assertSet('discountType', 'NONE');
});

test('voiding a cart clears the panel the same way', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('amountPaid', 500);

    expect($till->instance()->totals['total'])->toBe(100.0);

    $till->call('voidAll');

    expect($till->instance()->totals['total'])->toBe(0.0)
        ->and($till->instance()->changeDue)->toBe(0.0)
        ->and($till->instance()->canCheckout)->toBeFalse();
});
