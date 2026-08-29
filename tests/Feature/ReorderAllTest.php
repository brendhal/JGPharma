<?php

use App\Enums\UserRole;
use App\Filament\Resources\PurchaseResource\Pages\CreatePurchase;
use App\Filament\Widgets\StockAlerts;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

/**
 * Drafting one purchase order for everything that has run low.
 *
 * The quantity is the part that has to be right: shelves are counted in
 * pieces, purchase orders are written in whole units, and the receipt step
 * multiplies a PO line by the pack size. Handing a piece count to a PO orders
 * a box for every tablet.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $this->other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin);

    $this->stock = function (array $attributes, int $quantity, ?int $branchId = null): BranchInventory {
        $product = Product::create([
            'unit' => 'Box',
            'piece_unit' => 'Tablet',
            'cost_price' => 100,
            'selling_price' => 200,
            'is_batch_tracked' => false,
            ...$attributes,
        ]);

        return BranchInventory::create([
            'branch_id' => $branchId ?? $this->branch->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
    };
});

/* ------------------------------------------------------------- Quantities */

test('a suggestion is converted from pieces into whole units', function () {
    // Neozep from the dashboard: 600 tablets is the reorder level, 360 left.
    // Twice the level less what is there = 840 tablets. In boxes of 20 that
    // is 42 boxes — handing 840 to a purchase order would buy 16,800 tablets.
    $line = ($this->stock)([
        'sku' => 'NEOZEP-1',
        'name' => 'Neozep Forte Tablet',
        'pieces_per_box' => 20,
        'minimum_stock' => 600,
    ], 360);

    expect($line->suggested_reorder_quantity)->toBe(840)
        ->and($line->suggested_reorder_units)->toBe(42);
});

test('a part-box suggestion rounds up, since half a box cannot ship', function () {
    // 45 tablets is the level, 6 left: 84 tablets, which is 4.2 boxes of 20.
    $line = ($this->stock)([
        'sku' => 'AZITH-500',
        'name' => 'Azithromycin 500mg Tablet',
        'pieces_per_box' => 20,
        'minimum_stock' => 45,
    ], 6);

    expect($line->suggested_reorder_quantity)->toBe(84)
        ->and($line->suggested_reorder_units)->toBe(5);
});

test('a product sold whole suggests the piece count unchanged', function () {
    $line = ($this->stock)([
        'sku' => 'MILO-1',
        'name' => 'Milo',
        'unit' => 'Piece',
        'pieces_per_box' => 1,
        'minimum_stock' => 10,
    ], 0);

    expect($line->suggested_reorder_units)->toBe(20);
});

/* ------------------------------------------------------------ The button */

test('reorder all names every low product in one link', function () {
    $milo = ($this->stock)(['sku' => 'MILO-1', 'name' => 'Milo', 'unit' => 'Piece', 'pieces_per_box' => 1, 'minimum_stock' => 10], 0);
    $azith = ($this->stock)(['sku' => 'AZI-1', 'name' => 'Azithromycin', 'pieces_per_box' => 20, 'minimum_stock' => 45], 6);

    // Comfortably above its reorder level, so it must not be swept in.
    ($this->stock)(['sku' => 'FINE-1', 'name' => 'Well Stocked', 'pieces_per_box' => 20, 'minimum_stock' => 10], 5000);

    $lines = Livewire::test(StockAlerts::class)
        ->instance()
        ->reorderAllLines()
        ->map(fn ($record): string => $record->product_id.':'.$record->suggested_reorder_units)
        ->all();

    expect($lines)->toContain($milo->product_id.':20')
        ->and($lines)->toContain($azith->product_id.':5')
        // The well-stocked product is not one of the two.
        ->and($lines)->toHaveCount(2);
});

test('the button is hidden when nothing is low', function () {
    ($this->stock)(['sku' => 'FINE-1', 'name' => 'Well Stocked', 'pieces_per_box' => 20, 'minimum_stock' => 10], 5000);

    Livewire::test(StockAlerts::class)
        ->assertTableHeaderActionsExistInOrder([])
        ->assertDontSee('Reorder all');
});

test('the button counts what it will order', function () {
    ($this->stock)(['sku' => 'A-1', 'name' => 'A', 'pieces_per_box' => 1, 'minimum_stock' => 10], 0);
    ($this->stock)(['sku' => 'B-1', 'name' => 'B', 'pieces_per_box' => 1, 'minimum_stock' => 10], 0);
    ($this->stock)(['sku' => 'C-1', 'name' => 'C', 'pieces_per_box' => 1, 'minimum_stock' => 10], 0);

    Livewire::test(StockAlerts::class)->assertSee('Reorder all (3)');
});

test('another branch\'s shortages are left out, since a PO buys for one branch', function () {
    $mine = ($this->stock)(['sku' => 'MINE-1', 'name' => 'Mine', 'pieces_per_box' => 1, 'minimum_stock' => 10], 0);
    $theirs = ($this->stock)(['sku' => 'THEIRS-1', 'name' => 'Theirs', 'pieces_per_box' => 1, 'minimum_stock' => 10], 0, $this->other->id);

    $productIds = Livewire::test(StockAlerts::class)
        ->instance()
        ->reorderAllLines()
        ->pluck('product_id')
        ->all();

    expect($productIds)->toBe([$mine->product_id]);
});

/* ------------------------------------------------- The order it opens with */

test('the purchase form opens with every named product prefilled', function () {
    $milo = ($this->stock)(['sku' => 'MILO-1', 'name' => 'Milo', 'unit' => 'Piece', 'pieces_per_box' => 1, 'minimum_stock' => 10], 0);
    $azith = ($this->stock)(['sku' => 'AZI-1', 'name' => 'Azithromycin', 'pieces_per_box' => 20, 'minimum_stock' => 45], 6);

    $items = Livewire::withQueryParams([
        'items' => $milo->product_id.':20,'.$azith->product_id.':5',
        'branch' => $this->branch->id,
    ])
        ->test(CreatePurchase::class)
        ->instance()
        ->data['items'];

    $lines = collect($items)->values()->map(fn ($line): array => [
        'product_id' => (int) $line['product_id'],
        'quantity' => (int) $line['quantity'],
    ])->all();

    expect($lines)->toBe([
        ['product_id' => $milo->product_id, 'quantity' => 20],
        ['product_id' => $azith->product_id, 'quantity' => 5],
    ]);
});

test('a single-product link still works', function () {
    $azith = ($this->stock)(['sku' => 'AZI-1', 'name' => 'Azithromycin', 'pieces_per_box' => 20, 'minimum_stock' => 45], 6);

    $items = Livewire::withQueryParams([
        'items' => $azith->product_id.':5',
        'branch' => $this->branch->id,
    ])
        ->test(CreatePurchase::class)
        ->instance()
        ->data['items'];

    expect($items)->toHaveCount(1)
        ->and((int) $items[array_key_first($items)]['quantity'])->toBe(5);
});

test('the older product and quantity link is still honoured', function () {
    $azith = ($this->stock)(['sku' => 'AZI-1', 'name' => 'Azithromycin', 'pieces_per_box' => 20, 'minimum_stock' => 45], 6);

    $items = Livewire::withQueryParams([
        'product' => $azith->product_id,
        'quantity' => 3,
    ])
        ->test(CreatePurchase::class)
        ->instance()
        ->data['items'];

    expect((int) $items[array_key_first($items)]['quantity'])->toBe(3);
});

test('an empty link opens the usual blank row', function () {
    $items = Livewire::test(CreatePurchase::class)
        ->instance()
        ->data['items'];

    expect($items)->toHaveCount(1)
        ->and($items[array_key_first($items)]['product_id'])->toBeNull();
});

test('a rubbish link does not blow the form up', function () {
    $items = Livewire::withQueryParams(['items' => 'nonsense,:,0:5,'])
        ->test(CreatePurchase::class)
        ->instance()
        ->data['items'];

    expect($items)->toHaveCount(1)
        ->and($items[array_key_first($items)]['product_id'])->toBeNull();
});

test('a cashier never sees the widget at all', function () {
    $cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($cashier);

    expect(StockAlerts::canView())->toBeFalse();
});
