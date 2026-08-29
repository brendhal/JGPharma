<?php

use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use App\Support\SellingOption;
use Livewire\Livewire;

beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

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

    $this->twelve = $this->diapers->packs()->create(['label' => 'Pack of 12', 'pieces' => 12]);
    $this->six = $this->diapers->packs()->create(['label' => 'Pack of 6', 'pieces' => 6]);

    $this->stock = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->diapers->id,
        'quantity' => 96,
    ]);

    // is_active must be present on the instance, not just defaulted in the
    // database, or the panel's access check reads null.
    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->cashier);
});

test('the till offers every pack size for a scanned product', function () {
    Livewire::test(PointOfSale::class)
        ->set('scan', '4800000000011')
        ->call('submitScan')
        ->assertSet('cart.'.$this->diapers->id.'@BOX.unit_label', 'Case')
        ->assertSet('cart.'.$this->diapers->id.'@BOX.unit_multiplier', 48);
});

test('switching a line to a pack of six reprices and re-measures it', function () {
    $key = $this->diapers->id.'@BOX';
    $sixKey = 'PACK:'.$this->six->id;

    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id)
        ->call('changeUnit', $key, $sixKey)
        ->assertSet('cart.'.$this->diapers->id.'@'.$sixKey.'.unit_label', 'Pack of 6')
        ->assertSet('cart.'.$this->diapers->id.'@'.$sixKey.'.unit_multiplier', 6)
        ->assertSet('cart.'.$this->diapers->id.'@'.$sixKey.'.unit_price', 90.0);
});

test('two pack sizes sit on separate cart lines and check out together', function () {
    $twelveKey = 'PACK:'.$this->twelve->id;
    $sixKey = 'PACK:'.$this->six->id;

    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id, $twelveKey)
        ->call('addToCart', $this->diapers->id, $sixKey)
        ->call('addToCart', $this->diapers->id, SellingOption::PIECE)
        ->assertCount('cart', 3)
        ->set('amountPaid', 1000)
        ->call('checkout')
        ->assertSet('cart', []);

    // 12 + 6 + 1 = 19 pieces off a shelf of 96.
    expect($this->stock->fresh()->quantity)->toBe(77);
});

test('the cart cannot be pushed past what the shelf holds', function () {
    $this->stock->update(['quantity' => 10]);

    $component = Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id, 'PACK:'.$this->six->id);

    // One six fits in ten; a second would need twelve.
    $component->call('increment', $this->diapers->id.'@PACK:'.$this->six->id)
        ->assertSet('cart.'.$this->diapers->id.'@PACK:'.$this->six->id.'.quantity', 1);
});

test('a whole case is refused when only loose pieces remain', function () {
    $this->stock->update(['quantity' => 5]);

    Livewire::test(PointOfSale::class)
        ->call('addToCart', $this->diapers->id, SellingOption::BOX)
        ->assertSet('cart', []);
});
