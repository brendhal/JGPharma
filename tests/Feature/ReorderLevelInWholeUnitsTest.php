<?php

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\RelationManagers\StockRelationManager;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

/**
 * The reorder level is stored in pieces, because that is the only thing the
 * shelf count can be compared against. A manager reorders by the box though,
 * so the form talks in whole units and converts at the edge.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin);

    $this->base = [
        'name' => 'Test1 500mg',
        'sku' => 'TEST1-500MG-322',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 500,
        'cost_price' => 2000,
        'selling_price' => 3500,
    ];
});

test('a reorder level typed in boxes is stored in pieces', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([...$this->base, 'minimum_stock' => 2])
        ->call('create')
        ->assertHasNoFormErrors();

    // Two boxes of 500 is 1000 tablets, which is what the alert compares.
    expect(Product::where('sku', 'TEST1-500MG-322')->value('minimum_stock'))->toBe(1000);
});

test('the stored piece count reads back as whole units on the edit form', function () {
    $product = Product::create([...$this->base, 'minimum_stock' => 1000, 'is_batch_tracked' => false]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormSet(['minimum_stock' => 2]);
});

test('editing without touching the field leaves the stored value alone', function () {
    $product = Product::create([...$this->base, 'minimum_stock' => 1000, 'is_batch_tracked' => false]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->minimum_stock)->toBe(1000);
});

test('a threshold landing mid-box rounds up rather than down', function () {
    // 1200 tablets is 2.4 boxes; alerting at 3 boxes is the safe reading.
    $product = Product::create([...$this->base, 'minimum_stock' => 1200, 'is_batch_tracked' => false]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormSet(['minimum_stock' => 3]);
});

test('a product sold whole keeps the level exactly as typed', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...$this->base,
            'name' => 'Digital Thermometer',
            'sku' => 'THERM-1',
            'unit' => 'Piece',
            'piece_unit' => '',
            'pieces_per_box' => 1,
            'minimum_stock' => 10,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('sku', 'THERM-1')->value('minimum_stock'))->toBe(10);
});

test('the low-stock alert still fires on the piece count', function () {
    $product = Product::create([...$this->base, 'minimum_stock' => 1000, 'is_batch_tracked' => false]);

    $inventory = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'quantity' => 1000,
    ]);

    // Exactly at the threshold counts as low.
    expect($inventory->fresh()->status)->toBe('Low Stock');

    $inventory->update(['quantity' => 1001]);
    expect($inventory->fresh()->status)->toBe('Healthy');

    $inventory->update(['quantity' => 999]);
    expect($inventory->fresh()->status)->toBe('Low Stock');
});

/* ------------------------------------------------- The per-branch override */

test('a branch override is typed in boxes and stored in pieces', function () {
    $product = Product::create([...$this->base, 'minimum_stock' => 1000, 'is_batch_tracked' => false]);

    $inventory = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'quantity' => 5000,
    ]);

    Livewire::test(StockRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('edit', $inventory, data: ['minimum_stock' => 3])
        ->assertHasNoTableActionErrors();

    // Three boxes of 500 is 1500 tablets.
    expect($inventory->fresh()->minimum_stock)->toBe(1500);
});

test('an existing branch override reads back in whole units', function () {
    $product = Product::create([...$this->base, 'minimum_stock' => 1000, 'is_batch_tracked' => false]);

    $inventory = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'quantity' => 5000,
        'minimum_stock' => 1500,
    ]);

    Livewire::test(StockRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->mountTableAction('edit', $inventory)
        ->assertTableActionDataSet(['minimum_stock' => 3]);
});

test('a blank branch override stays blank so it keeps inheriting', function () {
    $product = Product::create([...$this->base, 'minimum_stock' => 1000, 'is_batch_tracked' => false]);

    $inventory = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'quantity' => 5000,
        'minimum_stock' => 1500,
    ]);

    Livewire::test(StockRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('edit', $inventory, data: ['minimum_stock' => null])
        ->assertHasNoTableActionErrors();

    expect($inventory->fresh()->minimum_stock)->toBeNull()
        // Falls through to the product-wide level, still in pieces.
        ->and($inventory->fresh()->reorder_level)->toBe(1000);
});
