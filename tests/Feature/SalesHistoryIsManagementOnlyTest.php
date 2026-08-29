<?php

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Filament\Resources\SaleResource;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PosService;
use Livewire\Livewire;

/**
 * The till is the cashier's whole screen.
 *
 * Sales history belongs to management now — browsing back through the day's
 * tickets is not part of ringing one up. What a cashier keeps is the one
 * thing they actually needed it for: printing a receipt again when a customer
 * comes back holding nothing.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->product = Product::create([
        'sku' => 'BIOG-500',
        'name' => 'Biogesic 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 60,
        'selling_price' => 100,
        'is_batch_tracked' => false,
    ]);

    BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'quantity' => 100000,
    ]);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->rosa = User::factory()->create([
        'name' => 'Rosa Cashier',
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->ben = User::factory()->create([
        'name' => 'Ben Cashier',
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->sell = function (User $cashier, int $boxes = 1): Sale {
        $this->actingAs($cashier);

        return app(PosService::class)->checkout(
            cart: [[
                'product_id' => $this->product->id,
                'unit' => 'BOX',
                'quantity' => $boxes,
                'unit_price' => 100,
            ]],
            branchId: $this->branch->id,
            amountPaid: 100 * $boxes,
            paymentMethod: PaymentMethod::CASH,
        );
    };
});

/* ----------------------------------------------------------------- Access */

test('sales history is closed to cashiers', function () {
    $this->actingAs($this->rosa);

    expect(SaleResource::canViewAny())->toBeFalse();
});

test('sales history stays open to management', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role, 'branch_id' => $this->branch->id]);

    $this->actingAs($user);

    expect(SaleResource::canViewAny())->toBeTrue();
})->with([
    'administrator' => UserRole::ADMIN,
    'branch manager' => UserRole::MANAGER,
]);

test('a cashier is turned away from the sales list by URL', function () {
    $this->actingAs($this->rosa)
        ->get(SaleResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

test('management can still open the sales list', function () {
    $this->actingAs($this->admin)
        ->get(SaleResource::getUrl('index', panel: 'admin'))
        ->assertOk();
});

/* -------------------------------------------------------------- Reprinting */

test('the till offers a reprint action', function () {
    $this->actingAs($this->rosa);

    Livewire::actingAs($this->rosa)
        ->test(PointOfSale::class)
        ->assertActionExists('reprintReceipt');
});

test('the reprint list holds only what this cashier rang up today', function () {
    ($this->sell)($this->rosa, 2);
    ($this->sell)($this->rosa, 3);

    $this->actingAs($this->rosa);

    $sales = Livewire::actingAs($this->rosa)
        ->test(PointOfSale::class)
        ->instance()
        ->todaysSales();

    expect($sales)->toHaveCount(2)
        // Newest first — the reprint anybody wants is almost always the last one.
        ->and((float) $sales->first()->total_amount)->toBe(300.0);
});

test('a reprint list never reaches into another till', function () {
    ($this->sell)($this->ben, 5);
    ($this->sell)($this->rosa, 1);

    $this->actingAs($this->rosa);

    $sales = Livewire::actingAs($this->rosa)
        ->test(PointOfSale::class)
        ->instance()
        ->todaysSales();

    expect($sales)->toHaveCount(1)
        ->and((float) $sales->first()->total_amount)->toBe(100.0);
});

test('yesterday drops off the reprint list', function () {
    $this->travelTo(now()->subDay(), fn () => ($this->sell)($this->rosa, 4));
    ($this->sell)($this->rosa, 1);

    $this->actingAs($this->rosa);

    $sales = Livewire::actingAs($this->rosa)
        ->test(PointOfSale::class)
        ->instance()
        ->todaysSales();

    expect($sales)->toHaveCount(1)
        ->and((float) $sales->first()->total_amount)->toBe(100.0);
});

test('a cashier can still print their own receipt', function () {
    $sale = ($this->sell)($this->rosa, 2);

    $this->actingAs($this->rosa)
        ->get(route('receipts.show', $sale))
        ->assertOk()
        ->assertSee($sale->invoice_number);
});
