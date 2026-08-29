<?php

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PosService;
use Livewire\Livewire;

/**
 * No reference, no non-cash sale.
 *
 * Cash lands in the drawer and is counted at close. GCash and card do not —
 * the only trace of the money is the number the wallet app or the terminal
 * hands back, so the cashier records it before the sale is taken, and the
 * receipt prints it for the day to be reconciled against the statement.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'PARA-500',
        'barcode' => '4800000000022',
        'name' => 'Paracetamol 500mg',
        'unit' => 'Box',
        'piece_unit' => 'tab',
        'pieces_per_box' => 100,
        'cost_price' => 80,
        'selling_price' => 120,
        'is_batch_tracked' => false,
        'is_active' => true,
    ]);

    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 500,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->cashier);

    $this->till = fn (string $method) => Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('paymentMethod', $method);
});

test('a gcash sale will not complete without a reference number', function () {
    $till = ($this->till)('GCASH');

    expect($till->instance()->canCheckout)->toBeFalse();

    $till->call('checkout')->assertSet('lastSaleId', null);

    expect(Sale::count())->toBe(0);
});

test('a card sale will not complete without an approval number', function () {
    ($this->till)('CARD')->call('checkout');

    expect(Sale::count())->toBe(0);
});

test('a gcash sale completes once the reference is entered', function () {
    ($this->till)('GCASH')
        ->set('paymentReference', '1234567890123')
        ->call('checkout')
        ->assertSet('cart', []);

    $sale = Sale::firstOrFail();

    expect($sale->payment_reference)->toBe('1234567890123')
        ->and($sale->payment_method)->toBe(PaymentMethod::GCASH);
});

test('letters in the reference are refused rather than stripped', function () {
    // Dropping the letters would leave a shortened number on the ticket that
    // matches nothing on the statement, so the sale is held instead.
    $till = ($this->till)('GCASH')->set('paymentReference', '12AB34');

    expect($till->instance()->canCheckout)->toBeFalse();

    $till->call('checkout');

    expect(Sale::count())->toBe(0);
});

test('a reference pasted with spaces or dashes is stored as digits', function () {
    ($this->till)('GCASH')
        ->set('paymentReference', '1234 5678-90123')
        ->call('checkout');

    expect(Sale::firstOrFail()->payment_reference)->toBe('1234567890123');
});

test('a reference of any length is accepted', function () {
    ($this->till)('GCASH')
        ->set('paymentReference', '90210')
        ->call('checkout');

    expect(Sale::firstOrFail()->payment_reference)->toBe('90210');
});

test('cash keeps no reference even if one is typed', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('paymentMethod', 'GCASH')
        ->set('paymentReference', '1234567890123')
        ->set('paymentMethod', 'CASH')
        ->set('amountPaid', 200)
        ->call('checkout');

    expect(Sale::firstOrFail()->payment_reference)->toBeNull();
});

test('switching payment method clears a reference typed for the old one', function () {
    ($this->till)('GCASH')
        ->set('paymentReference', '1234567890123')
        ->set('paymentMethod', 'CARD')
        ->assertSet('paymentReference', null);
});

test('the service refuses a non-cash sale with no reference', function () {
    app(PosService::class)->checkout(
        cart: [[
            'product_id' => $this->product->id,
            'unit' => 'BOX',
            'quantity' => 1,
            'unit_price' => 120,
        ]],
        branchId: $this->branch->id,
        amountPaid: 120,
        paymentMethod: PaymentMethod::GCASH,
        paymentReference: null,
    );
})->throws(RuntimeException::class, 'Enter the GCash reference number before completing the sale.');

test('the service refuses a reference that is not all numbers', function () {
    app(PosService::class)->checkout(
        cart: [[
            'product_id' => $this->product->id,
            'unit' => 'BOX',
            'quantity' => 1,
            'unit_price' => 120,
        ]],
        branchId: $this->branch->id,
        amountPaid: 120,
        paymentMethod: PaymentMethod::CARD,
        paymentReference: 'AB123',
    );
})->throws(RuntimeException::class, 'Card approval number must be numbers only.');

test('the receipt prints the reference number', function () {
    ($this->till)('GCASH')
        ->set('paymentReference', '1234567890123')
        ->call('checkout');

    $this->get(route('receipts.show', Sale::firstOrFail()))
        ->assertOk()
        ->assertSee('1234567890123');
});
