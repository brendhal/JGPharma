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
 * No money, no sale.
 *
 * A blank cash box used to be read as "the customer handed over the exact
 * total", so pressing Complete sale recorded a paid transaction nobody had
 * paid for. Cash now has to be entered and has to cover the total; card and
 * e-wallet settle the exact amount, but carry a reference number.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'DIAP-L',
        'barcode' => '4800000000011',
        'name' => 'Dry Comfort Diapers Large',
        'unit' => 'Case',
        'piece_unit' => 'pc',
        'pieces_per_box' => 48,
        'cost_price' => 480,
        'selling_price' => 720,
        'is_batch_tracked' => false,
        'is_active' => true,
    ]);

    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 96,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->cashier);
});

test('a cash sale will not complete with the cash box left blank', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->assertSet('amountPaid', null);

    expect($till->instance()->canCheckout)->toBeFalse();

    $till->call('checkout')->assertSet('lastSaleId', null);

    expect(Sale::count())->toBe(0);
});

test('a cash sale will not complete on zero', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('amountPaid', 0)
        ->call('checkout');

    expect(Sale::count())->toBe(0);
});

test('a cash sale will not complete when the cash is short', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('amountPaid', 100)
        ->call('checkout');

    expect(Sale::count())->toBe(0);
});

test('enough cash completes the sale and leaves change', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('amountPaid', 1000)
        ->call('checkout')
        ->assertSet('cart', []);

    $sale = Sale::firstOrFail();

    expect((float) $sale->amount_paid)->toBe(1000.0)
        ->and((float) $sale->change_amount)->toBe(280.0);
});

test('card and e-wallet settle the exact total with nothing tendered', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('paymentMethod', 'GCASH')
        ->set('paymentReference', '1234567890123')
        ->call('checkout');

    $sale = Sale::firstOrFail();

    expect((float) $sale->amount_paid)->toBe(720.0)
        ->and((float) $sale->change_amount)->toBe(0.0);
});

test('an empty cart still blocks checkout', function () {
    $till = Livewire::test(PointOfSale::class);

    expect($till->instance()->canCheckout)->toBeFalse();

    $till->call('checkout');

    expect(Sale::count())->toBe(0);
});
