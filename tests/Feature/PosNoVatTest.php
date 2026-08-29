<?php

use App\Enums\DiscountType;
use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PosService;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Shelf prices are what the customer pays.
 *
 * Nothing is split out of them and nothing is added on top, so a ticket is
 * simply the lines added up, less whatever discount applies. Senior and PWD
 * take their statutory 20% straight off that figure rather than off a
 * tax-stripped price.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'BIOG-500',
        'barcode' => '4800000000033',
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

    $this->pos = app(PosService::class);

    $this->line = fn (int $boxes = 1, float $price = 100) => [[
        'product_id' => $this->product->id,
        'unit' => 'BOX',
        'quantity' => $boxes,
        'unit_price' => $price,
    ]];
});

/* --------------------------------------------------------------- Pricing */

test('a regular sale totals the shelf price with nothing added or taken out', function () {
    $totals = $this->pos->totals(($this->line)(3));

    expect($totals['gross'])->toBe(300.0)
        ->and($totals['subtotal'])->toBe(300.0)
        ->and($totals['discount'])->toBe(0.0)
        ->and($totals['total'])->toBe(300.0);
});

test('the totals no longer carry a VAT figure at all', function () {
    expect($this->pos->totals(($this->line)()))
        ->not->toHaveKey('vat')
        ->not->toHaveKey('vat_exempt');
});

test('a manual discount comes straight off the subtotal', function () {
    $totals = $this->pos->totals(($this->line)(2), DiscountType::NONE, 10);

    expect($totals['subtotal'])->toBe(200.0)
        ->and($totals['discount'])->toBe(20.0)
        ->and($totals['total'])->toBe(180.0);
});

test('a senior sale takes a straight twenty percent off', function () {
    $totals = $this->pos->totals(($this->line)(1), DiscountType::SENIOR);

    expect($totals['subtotal'])->toBe(100.0)
        ->and($totals['discount'])->toBe(20.0)
        ->and($totals['total'])->toBe(80.0);
});

test('a PWD sale is priced the same way as a senior sale', function () {
    expect($this->pos->totals(($this->line)(1), DiscountType::PWD)['total'])
        ->toBe($this->pos->totals(($this->line)(1), DiscountType::SENIOR)['total']);
});

test('subtotal less discount always lands on the total', function () {
    foreach ([DiscountType::NONE, DiscountType::SENIOR, DiscountType::PWD] as $type) {
        $totals = $this->pos->totals(($this->line)(7, 33.33), $type, 5);

        expect(round($totals['subtotal'] - $totals['discount'], 2))->toBe($totals['total']);
    }
});

/* ---------------------------------------------------------------- Storage */

test('the sales table no longer keeps a VAT column', function () {
    expect(Schema::hasColumn('sales', 'vat_amount'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'is_vat_exempt'))->toBeFalse();
});

test('a completed sale stores the total the cashier saw', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('discountType', 'SENIOR')
        ->set('discountIdNumber', 'SC-0001')
        ->set('amountPaid', 100)
        ->call('checkout');

    $sale = Sale::firstOrFail();

    expect((float) $sale->subtotal)->toBe(100.0)
        ->and((float) $sale->discount)->toBe(20.0)
        ->and((float) $sale->total_amount)->toBe(80.0)
        ->and((float) $sale->change_amount)->toBe(20.0);
});

test('the receipt prints no VAT lines', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->product->id)
        ->set('amountPaid', 100)
        ->call('checkout');

    $this->get(route('receipts.show', Sale::firstOrFail()))
        ->assertOk()
        ->assertDontSee('VAT')
        ->assertDontSee('VATable');
});
