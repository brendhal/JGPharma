<?php

use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/**
 * A scan lands in the cart on its own.
 *
 * A barcode gun is a keyboard that types faster than the search box's debounce,
 * and not every gun is set up to send Enter afterwards — so the code reached
 * the box but never the cart, and the cashier had to press Enter for it. The
 * scanner now hands the finished code straight to submitScan(), which is what
 * these cover: the code goes in as an argument, not through the bound property.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->biogesic = Product::create([
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

    $this->neozep = Product::create([
        'sku' => 'NEOZ-500',
        'barcode' => '4800000000105',
        'name' => 'Neozep Forte',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 70,
        'selling_price' => 120,
        'is_batch_tracked' => false,
        'is_active' => true,
    ]);

    foreach ([$this->biogesic, $this->neozep] as $product) {
        BranchInventory::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'quantity' => 1000,
        ]);
    }

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->cashier);
});

test('a scanned barcode goes into the cart without the box being filled in first', function () {
    $till = Livewire::test(PointOfSale::class)
        // The gun never touched the bound property — this is the whole point.
        ->call('submitScan', '4800000000044');

    expect($till->get('cart'))->toHaveCount(1);

    $line = collect($till->get('cart'))->first();

    expect($line['product_id'])->toBe($this->biogesic->id)
        ->and($line['quantity'])->toBe(1);
});

test('the box is emptied and the suggestions cleared after a scan', function () {
    Livewire::test(PointOfSale::class)
        ->set('scan', '480')
        ->call('submitScan', '4800000000044')
        ->assertSet('scan', '')
        ->assertSet('suggestions', [])
        ->assertDispatched('pos-scan-handled');
});

test('scanning the same item again adds one to the line it is already on', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('submitScan', '4800000000044')
        ->call('submitScan', '4800000000044')
        ->call('submitScan', '4800000000044');

    expect($till->get('cart'))->toHaveCount(1);
    expect(collect($till->get('cart'))->first()['quantity'])->toBe(3);
});

test('two different barcodes make two lines', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('submitScan', '4800000000044')
        ->call('submitScan', '4800000000105');

    expect($till->get('cart'))->toHaveCount(2);
    expect(collect($till->get('cart'))->pluck('product_id')->all())
        ->toEqualCanonicalizing([$this->biogesic->id, $this->neozep->id]);
});

test('a SKU scans the same as a barcode', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('submitScan', 'BIOG-500');

    expect($till->get('cart'))->toHaveCount(1);
});

test('a code the gun padded with spaces still matches', function () {
    $till = Livewire::test(PointOfSale::class)
        ->call('submitScan', "  4800000000044\r\n");

    expect($till->get('cart'))->toHaveCount(1);
});

test('an unknown code is refused and leaves the box ready for the next item', function () {
    Livewire::test(PointOfSale::class)
        ->call('submitScan', '9999999999999')
        ->assertNotified(Notification::make()->title('No product found')->warning())
        ->assertSet('scan', '')
        ->assertSet('cart', []);
});

test('a delisted product does not scan', function () {
    $this->biogesic->update(['is_active' => false]);

    Livewire::test(PointOfSale::class)
        ->call('submitScan', '4800000000044')
        ->assertNotified(Notification::make()->title('No product found')->warning())
        ->assertSet('cart', []);
});

test('scanning past the shelf is refused, not silently added', function () {
    // One box, and it is already in the cart.
    BranchInventory::query()
        ->where('product_id', $this->biogesic->id)
        ->update(['quantity' => 100]);

    $till = Livewire::test(PointOfSale::class)
        ->call('submitScan', '4800000000044')
        ->call('submitScan', '4800000000044')
        ->assertNotified(Notification::make()->title('Not enough stock')->warning());

    expect(collect($till->get('cart'))->first()['quantity'])->toBe(1);
});

test('a typed name that matches several products still offers a pick-list', function () {
    Product::create([
        'sku' => 'BIOG-250',
        'barcode' => '4800000000051',
        'name' => 'Biogesic 250mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 40,
        'selling_price' => 80,
        'is_batch_tracked' => false,
        'is_active' => true,
    ]);

    $till = Livewire::test(PointOfSale::class)
        ->set('scan', 'Biogesic')
        ->call('submitScan')
        ->assertSet('cart', [])
        ->assertSet('scan', 'Biogesic');

    expect($till->get('suggestions'))->toHaveCount(2);
});

test('an empty scan does nothing at all', function () {
    Livewire::test(PointOfSale::class)
        ->call('submitScan', '   ')
        ->assertSet('cart', [])
        ->assertNotDispatched('pos-scan-handled');
});
