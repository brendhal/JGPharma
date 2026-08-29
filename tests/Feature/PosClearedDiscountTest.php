<?php

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
 * Clearing the manual discount box is not a crash.
 *
 * Livewire hands an emptied number input to the component as null. The
 * property was a plain float, so the assignment threw, and Livewire answers a
 * TypeError on a typed property by unsetting it — leaving every later read of
 * $discountPercent fatal. Backspacing the field to empty took the whole till
 * down with "Property [$discountPercent] not found".
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
});

test('emptying the manual discount box does not break the page', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', 10)
        ->set('discountPercent', null)
        ->assertOk();
});

test('an empty discount box reads as no discount, not as a broken total', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', 10)
        ->set('discountPercent', null);

    expect($till->instance()->totals['total'])->toBe(100.0);
});

test('a discount typed after the box was cleared still applies', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', null)
        ->set('discountPercent', 25);

    expect($till->instance()->totals['total'])->toBe(75.0);
});

test('a sale rung up with the box left empty charges the full price', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', null)
        ->set('amountPaid', 500)
        ->call('checkout');

    $sale = Sale::firstOrFail();

    expect((float) $sale->discount)->toBe(0.0)
        ->and((float) $sale->total_amount)->toBe(100.0);
});

/* ------------------------------------------------------------- Decimals */

test('a discount with decimals comes off the total', function (float $percent, float $total) {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', $percent);

    expect($till->instance()->totals['total'])->toBe($total);
})->with([
    // One box at 100.00, so the percentage reads straight off as pesos.
    'half a percent' => [0.5, 99.5],
    'twelve and a half' => [12.5, 87.5],
    'two decimal places' => [7.25, 92.75],
    'three quarters of a percent' => [0.75, 99.25],
]);

test('a decimal discount is what gets rung up and stored', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', 12.5)
        ->set('amountPaid', 500)
        ->call('checkout');

    $sale = Sale::firstOrFail();

    expect((float) $sale->discount)->toBe(12.5)
        ->and((float) $sale->total_amount)->toBe(87.5);
});

/* --------------------------------------------------------------- Limits */

test('a discount over a hundred percent is pulled back to a hundred', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', 500)
        ->assertSet('discountPercent', 100.0);

    expect($till->instance()->totals['total'])->toBe(0.0);
});

test('a negative discount is pulled back to nothing', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', -20)
        ->assertSet('discountPercent', 0.0);
});

test('the totals never go past a hundred percent off, whatever they are handed', function (float $percent) {
    $line = [[
        'product_id' => $this->product->id,
        'unit' => 'BOX',
        'quantity' => 1,
        'unit_price' => 100,
    ]];

    $totals = app(PosService::class)->totals($line, discountPercent: $percent);

    expect($totals['total'])->toBe(0.0)
        ->and($totals['discount'])->toBe(100.0);
})->with([
    'exactly a hundred' => [100.0],
    'a fat-fingered five hundred' => [500.0],
    'a stray extra zero' => [1000.0],
]);

test('a discount inside the limit is left alone', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountPercent', 99.99)
        ->assertSet('discountPercent', 99.99);
});
