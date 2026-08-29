<?php

use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

/**
 * A cart that outlives the page it was built on.
 *
 * Reloading the till used to throw the basket away, which on a busy counter
 * means re-scanning everything. The cart is parked in the session instead —
 * but the shelf keeps moving while it sits there, so what comes back is
 * re-checked against stock rather than restored blindly.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    // A case of 48; 96 on hand is exactly two cases.
    $this->diapers = Product::create([
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

    $this->stock = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->diapers->id,
        'quantity' => 96,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->cashier);

    $this->key = $this->diapers->id.'@BOX';
});

test('a refresh brings the cart back instead of emptying it', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id);

    // A fresh page load — a new component, same session.
    Livewire::test(PointOfSale::class)
        ->assertSet('cart.'.$this->key.'.quantity', 1)
        ->assertSet('cart.'.$this->key.'.unit_label', 'Case');
});

test('remove all empties the cart and it stays empty on reload', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id)
        ->call('voidAll')
        ->assertSet('cart', []);

    Livewire::test(PointOfSale::class)->assertSet('cart', []);
});

test('a parked line is trimmed to what is left on the shelf', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id)
        ->call('addToCart', $this->diapers->id)
        ->assertSet('cart.'.$this->key.'.quantity', 2);

    // Another till sells a case while this cart sits parked.
    $this->stock->update(['quantity' => 48]);

    Livewire::test(PointOfSale::class)
        ->assertSet('cart.'.$this->key.'.quantity', 1)
        ->assertSet('cart.'.$this->key.'.stock', 48);
});

test('a parked line is dropped once nothing is left', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id);

    $this->stock->update(['quantity' => 0]);

    Livewire::test(PointOfSale::class)->assertSet('cart', []);
});

test('a delisted product does not come back', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id);

    $this->diapers->update(['is_active' => false]);

    Livewire::test(PointOfSale::class)->assertSet('cart', []);
});

test('one cashier does not inherit another cashier cart', function () {
    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id);

    $other = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($other);

    Livewire::test(PointOfSale::class)->assertSet('cart', []);
});
