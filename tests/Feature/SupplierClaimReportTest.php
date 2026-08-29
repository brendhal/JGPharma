<?php

use App\Enums\MovementType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;

/**
 * Write-offs turned into a claim against the supplier that sold the stock.
 *
 * The link runs disposal -> batch -> supplier, so the two things worth
 * pinning down are that each supplier gets only its own lines, and that
 * stock written off without a batch still shows up somewhere rather than
 * silently vanishing from the period's total.
 */
beforeEach(function () {
    $this->branch = Branch::create([
        'code' => 'MAIN',
        'name' => 'Public Market Branch',
        'address' => '12 Market Street',
    ]);

    $this->admin = User::factory()->create([
        'name' => 'Ada Admin',
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->medisupply = Supplier::create(['name' => 'MediSupply Ph', 'is_active' => true]);
    $this->pharmatrade = Supplier::create(['name' => 'PharmaTrade Inc', 'is_active' => true]);

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

    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 10000,
    ]);

    $batch = fn (string $lot, ?Supplier $supplier) => ProductBatch::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'batch_number' => $lot,
        'expiration_date' => '2026-09-30',
        'quantity' => 2000,
        'cost_price' => 2000,
        'received_date' => '2026-01-01',
        'supplier_id' => $supplier?->id,
    ]);

    $this->fromMedisupply = $batch('LOT-A', $this->medisupply);
    $this->fromPharmatrade = $batch('LOT-B', $this->pharmatrade);
    $this->fromNobody = $batch('LOT-C', null);

    $this->inventory = app(InventoryService::class);
    $this->actingAs($this->admin);

    $this->travelTo(Carbon\Carbon::parse('2026-08-20 10:00'));

    $this->inventory->dispose($this->fromMedisupply, 100, MovementType::EXPIRED);
    $this->inventory->dispose($this->fromPharmatrade, 250, MovementType::EXPIRED);
    $this->inventory->dispose($this->fromNobody, 50, MovementType::EXPIRED);
    $this->inventory->dispose($this->fromMedisupply, 500, MovementType::DAMAGED);

    $this->claim = fn (array $params = []) => $this->get(route('disposals.claims', array_merge([
        'period' => 'monthly',
        'date' => '2026-08-20',
    ], $params)));
});

it('gives every supplier its own sheet', function () {
    ($this->claim)()
        ->assertOk()
        ->assertSee('MediSupply Ph')
        ->assertSee('PharmaTrade Inc')
        ->assertSee('LOT-A')
        ->assertSee('LOT-B')
        // 100 tablets at 4.00 from one supplier, 250 from the other.
        ->assertSee('400.00')
        ->assertSee('1,000.00');
});

it('narrows to one supplier when asked', function () {
    $response = ($this->claim)(['supplier' => $this->medisupply->id])->assertOk();

    $response->assertSee('MediSupply Ph')
        ->assertSee('LOT-A')
        ->assertDontSee('PharmaTrade Inc')
        ->assertDontSee('LOT-B');
});

it('covers expired stock only unless damaged is asked for', function () {
    // The 500 damaged tablets are worth 2,000 and must not be claimed by default.
    ($this->claim)(['supplier' => $this->medisupply->id])
        ->assertOk()
        ->assertDontSee('Damaged');

    ($this->claim)(['supplier' => $this->medisupply->id, 'reason' => 'all'])
        ->assertOk()
        ->assertSee('Damaged');
});

it('still lists write-offs that no supplier can be billed for', function () {
    ($this->claim)()
        ->assertOk()
        ->assertSee('No supplier on record')
        ->assertSee('LOT-C');
});

it('is closed to staff outside management', function () {
    $cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($cashier);

    ($this->claim)()->assertForbidden();
});

it('turns guests away', function () {
    auth()->logout();

    ($this->claim)()->assertRedirect();
});
