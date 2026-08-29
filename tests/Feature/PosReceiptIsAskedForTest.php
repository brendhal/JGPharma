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
 * The receipt is asked for, not assumed.
 *
 * Completing a sale used to throw a print preview up in a new tab every time,
 * which is a window to close on every customer who did not want a ticket. The
 * button now confirms first and offers both ways out; the sale is recorded
 * either way, and only the printing differs.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'BIOG-500',
        'barcode' => '4800000000044',
        'name' => 'Biogesic 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
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

    /** A till with one box in the cart and the money on the counter. */
    $this->till = fn () => Livewire::test(PointOfSale::class)
        ->call('submitScan', '4800000000044')
        ->set('amountPaid', 500);
});

test('completing a sale prints nothing on its own', function () {
    ($this->till)()
        ->call('checkout')
        ->assertNotDispatched('open-receipt');

    expect(Sale::query()->count())->toBe(1);
});

test('asking for a receipt opens it', function () {
    ($this->till)()
        ->call('checkout', true)
        ->assertDispatched('open-receipt');

    expect(Sale::query()->count())->toBe(1);
});

test('the sale is recorded the same either way', function () {
    $quiet = ($this->till)()->call('checkout');
    $printed = ($this->till)()->call('checkout', true);

    $sales = Sale::query()->orderBy('id')->get();

    expect($sales)->toHaveCount(2)
        ->and((float) $sales[0]->total_amount)->toBe((float) $sales[1]->total_amount);

    // Both tills are left clear for the next customer.
    expect($quiet->get('cart'))->toBe([])
        ->and($printed->get('cart'))->toBe([]);
});

test('the confirmation shows what the cashier is about to take', function () {
    $till = ($this->till)();

    $summary = $till->instance()->checkoutSummary();

    expect($summary)->toContain('Total ₱100.00')
        ->toContain('Cash ₱500.00')
        ->toContain('Change ₱400.00');
});

test('a refused sale prints nothing and records nothing', function () {
    Livewire::test(PointOfSale::class)
        ->call('submitScan', '4800000000044')
        // No cash entered.
        ->call('checkout', true)
        ->assertNotDispatched('open-receipt');

    expect(Sale::query()->count())->toBe(0);
});

test('the button stays out of reach until the sale can be taken', function () {
    $till = Livewire::test(PointOfSale::class);

    expect($till->instance()->canCheckout)->toBeFalse();

    $till->call('submitScan', '4800000000044')->set('amountPaid', 500);

    expect($till->instance()->canCheckout)->toBeTrue();
});
