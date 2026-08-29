<?php

use App\Enums\MovementType;
use App\Enums\UserRole;
use App\Filament\Resources\DisposalResource\Pages\ListDisposals;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Livewire\Livewire;

/**
 * Write-offs, and what they cost.
 *
 * The valuation is the part worth pinning down: cost is captured against the
 * pack a delivery arrives in, stock is counted in pieces, and multiplying the
 * two directly overstates a loss by the pack size.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $this->other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);

    $this->admin = User::factory()->create([
        'name' => 'Ada Admin',
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    // A box of 500 tablets costing 2000 — so 4.00 a tablet.
    $this->product = Product::create([
        'sku' => 'TEST1-500MG',
        'name' => 'Test1 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 500,
        'cost_price' => 2000,
        'selling_price' => 3500,
        'is_batch_tracked' => true,
    ]);

    $this->product->packs()->create(['label' => 'Blister', 'pieces' => 10]);

    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 5000,
    ]);

    $this->batch = ProductBatch::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'batch_number' => 'LOT-A',
        'expiration_date' => '2026-09-30',
        'quantity' => 5000,
        'cost_price' => 2000,
        'received_date' => '2026-01-01',
    ]);

    $this->inventory = app(InventoryService::class);
    $this->actingAs($this->admin);

    $this->dispose = function (int $pieces, MovementType $type, string $when, ?string $remarks = null) {
        return $this->travelTo(Carbon\Carbon::parse($when), fn () => $this->inventory->dispose(
            $this->batch->fresh(),
            $pieces,
            $type,
            $remarks,
        ));
    };
});

/* ------------------------------------------------------------- Valuation */

test('a disposal is valued per piece, not per box', function () {
    $movement = ($this->dispose)(100, MovementType::EXPIRED, '2026-08-24 10:00');

    // 100 tablets at 4.00 each. Valuing 100 x 2000 would say 200,000.
    expect($movement->disposal_value)->toBe(400.0)
        ->and($movement->pieces_disposed)->toBe(100);
});

test('a whole box written off is worth exactly what the box cost', function () {
    $movement = ($this->dispose)(500, MovementType::DAMAGED, '2026-08-24 10:00');

    expect($movement->disposal_value)->toBe(2000.0);
});

test('the batch cost wins over the product cost', function () {
    // A cheaper delivery of the same product.
    $cheap = ProductBatch::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'batch_number' => 'LOT-B',
        'expiration_date' => '2026-10-31',
        'quantity' => 1000,
        'cost_price' => 1000,
        'received_date' => '2026-02-01',
    ]);

    $movement = $this->inventory->dispose($cheap, 100, MovementType::EXPIRED);

    // 1000 a box of 500 is 2.00 a tablet.
    expect($movement->disposal_value)->toBe(200.0);
});

test('remaining batch stock is valued the same way', function () {
    // The old sum multiplied 5000 pieces by a 2000 per-box cost.
    expect($this->batch->fresh()->stock_value)->toBe(20000.0);
});

test('a product sold whole values one for one', function () {
    $thermometer = Product::create([
        'sku' => 'THERM-1',
        'name' => 'Digital Thermometer',
        'unit' => 'Piece',
        'pieces_per_box' => 1,
        'cost_price' => 165,
        'selling_price' => 245,
        'is_batch_tracked' => true,
    ]);

    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $thermometer->id,
        'quantity' => 10,
    ]);

    $batch = ProductBatch::create([
        'branch_id' => $this->branch->id,
        'product_id' => $thermometer->id,
        'batch_number' => 'T-1',
        'quantity' => 10,
        'cost_price' => 165,
        'received_date' => '2026-01-01',
    ]);

    expect($this->inventory->dispose($batch, 3, MovementType::DAMAGED)->disposal_value)
        ->toBe(495.0);
});

/* ---------------------------------------------------------- The printable */

test('the report totals both reasons and separates them', function () {
    ($this->dispose)(100, MovementType::EXPIRED, '2026-08-10 10:00');
    ($this->dispose)(50, MovementType::DAMAGED, '2026-08-12 10:00');

    $this->get(route('disposals.report', ['period' => 'monthly', 'date' => '2026-08-15']))
        ->assertOk()
        ->assertSee('Expired Disposal')
        ->assertSee('Damaged')
        // 100 + 50 tablets at 4.00 = 600.00 total, 400.00 and 200.00 apart.
        ->assertSee('600.00')
        ->assertSee('400.00')
        ->assertSee('200.00');
});

test('the report can be narrowed to one reason', function () {
    ($this->dispose)(100, MovementType::EXPIRED, '2026-08-10 10:00');
    ($this->dispose)(50, MovementType::DAMAGED, '2026-08-12 10:00');

    $this->get(route('disposals.report', [
        'period' => 'monthly',
        'date' => '2026-08-15',
        'reason' => 'EXPIRED',
    ]))
        ->assertOk()
        ->assertSee('400.00')
        ->assertDontSee('600.00');
});

test('each period gathers the right span', function (string $period, string $date, string $expected) {
    // 24 Aug 2026 is a Monday, so its week runs to the 30th.
    ($this->dispose)(100, MovementType::EXPIRED, '2026-08-24 10:00');   // the day
    ($this->dispose)(50, MovementType::EXPIRED, '2026-08-26 10:00');    // same week
    ($this->dispose)(25, MovementType::EXPIRED, '2026-08-05 10:00');    // same month only
    ($this->dispose)(25, MovementType::EXPIRED, '2026-03-02 10:00');    // same year only

    $this->get(route('disposals.report', ['period' => $period, 'date' => $date]))
        ->assertOk()
        ->assertSee($expected);
})->with([
    ['daily', '2026-08-24', '400.00'],    // 100 tablets at 4.00
    ['weekly', '2026-08-24', '600.00'],   // + the 26th = 150
    ['monthly', '2026-08-24', '700.00'],  // + the 5th = 175
    ['yearly', '2026-08-24', '800.00'],   // + March = 200
]);

test('a quiet period says so instead of erroring', function () {
    $this->get(route('disposals.report', ['period' => 'monthly', 'date' => '2026-01-15']))
        ->assertOk()
        ->assertSee('Nothing was written off');
});

test('sales and purchases never leak into the disposal report', function () {
    ($this->dispose)(100, MovementType::EXPIRED, '2026-08-24 10:00');

    // A movement of a type that is not a write-off.
    StockMovement::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'type' => MovementType::SALE,
        'quantity' => -900,
        'balance_after' => 4000,
        'remarks' => 'A sale, not a loss',
    ]);

    $this->get(route('disposals.report', ['period' => 'monthly', 'date' => '2026-08-24']))
        ->assertOk()
        ->assertDontSee('A sale, not a loss')
        ->assertSee('400.00');
});

/* -------------------------------------------------------------- Who may look */

test('a cashier cannot open the disposal report', function () {
    $this->actingAs($this->cashier)
        ->get(route('disposals.report', ['period' => 'monthly', 'date' => '2026-08-24']))
        ->assertForbidden();
});

test('a guest is sent to log in', function () {
    auth()->logout();

    $this->get(route('disposals.report'))->assertRedirect();
});

/* ------------------------------------------------------------ The screen */

test('the disposals list shows write-offs and hides everything else', function () {
    ($this->dispose)(100, MovementType::EXPIRED, '2026-08-24 10:00');

    StockMovement::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'type' => MovementType::SALE,
        'quantity' => -900,
        'balance_after' => 4000,
    ]);

    $records = Livewire::actingAs($this->admin)
        ->test(ListDisposals::class)
        ->instance()
        ->getTableRecords();

    expect($records)->toHaveCount(1)
        ->and($records->first()->type)->toBe(MovementType::EXPIRED);
});

test('the print modal sends the period and reason through', function () {
    Livewire::actingAs($this->admin)
        ->test(ListDisposals::class)
        ->callAction('printReport', data: [
            'period' => 'yearly',
            'date' => '2026-08-24',
            'reason' => 'DAMAGED',
        ])
        ->assertRedirect(route('disposals.report', [
            'period' => 'yearly',
            'date' => '2026-08-24',
            'reason' => 'DAMAGED',
        ]));
});

test('a blank reason covers both', function () {
    Livewire::actingAs($this->admin)
        ->test(ListDisposals::class)
        ->callAction('printReport', data: [
            'period' => 'monthly',
            'date' => '2026-08-24',
            'reason' => null,
        ])
        ->assertRedirect(route('disposals.report', [
            'period' => 'monthly',
            'date' => '2026-08-24',
        ]));
});

test('a cashier is not offered the disposals screen', function () {
    expect(App\Filament\Resources\DisposalResource::canViewAny())->toBeTrue();

    $this->actingAs($this->cashier);

    expect(App\Filament\Resources\DisposalResource::canViewAny())->toBeFalse();
});
