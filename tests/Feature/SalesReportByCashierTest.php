<?php

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use App\Services\PosService;

/**
 * Management picking one cashier out of the sales report.
 *
 * The periods already worked; what is proved here is that the report narrows
 * to the named cashier, and — more importantly — that it refuses to narrow to
 * someone the requester has no business seeing.
 */
beforeEach(function () {
    $this->main = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $this->other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);

    $this->admin = User::factory()->create([
        'name' => 'Ada Admin',
        'role' => UserRole::ADMIN,
        'branch_id' => $this->main->id,
        'is_active' => true,
    ]);

    $this->rosa = User::factory()->create([
        'name' => 'Rosa Cashier',
        'role' => UserRole::CASHIER,
        'branch_id' => $this->main->id,
        'is_active' => true,
    ]);

    $this->ben = User::factory()->create([
        'name' => 'Ben Cashier',
        'role' => UserRole::CASHIER,
        'branch_id' => $this->main->id,
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'sku' => 'PARA-500',
        'name' => 'Paracetamol 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 60,
        'selling_price' => 100,
        'is_batch_tracked' => false,
    ]);

    BranchInventory::create([
        'branch_id' => $this->main->id,
        'product_id' => $this->product->id,
        'quantity' => 100000,
    ]);

    $this->sell = function (User $cashier, string $when, int $boxes): void {
        $this->actingAs($cashier);

        $this->travelTo(Carbon\Carbon::parse($when), function () use ($cashier, $boxes) {
            app(PosService::class)->checkout(
                cart: [[
                    'product_id' => $this->product->id,
                    'unit' => 'BOX',
                    'quantity' => $boxes,
                    'unit_price' => 100,
                ]],
                branchId: $this->main->id,
                amountPaid: 100 * $boxes,
                paymentMethod: PaymentMethod::CASH,
            );
        });
    };
});

/* ------------------------------------------------------ Narrowing by cashier */

test('an admin can pull one cashier out of the day', function () {
    ($this->sell)($this->rosa, '2026-08-23 10:00', 3);
    ($this->sell)($this->ben, '2026-08-23 11:00', 5);

    $this->actingAs($this->admin)
        ->get(route('sales.report', [
            'period' => 'daily',
            'date' => '2026-08-23',
            'cashier' => $this->rosa->id,
        ]))
        ->assertOk()
        ->assertSee('Rosa Cashier')
        ->assertDontSee('Ben Cashier')
        // Rosa's three boxes at 100, not Ben's five.
        ->assertSee('300.00');
});

test('leaving the cashier blank covers everyone', function () {
    ($this->sell)($this->rosa, '2026-08-23 10:00', 3);
    ($this->sell)($this->ben, '2026-08-23 11:00', 5);

    $this->actingAs($this->admin)
        ->get(route('sales.report', ['period' => 'daily', 'date' => '2026-08-23']))
        ->assertOk()
        ->assertSee('All cashiers')
        ->assertSee('Rosa Cashier')
        ->assertSee('Ben Cashier')
        ->assertSee('800.00');
});

/* ---------------------------------------------------------------- Periods */

test('each period gathers the right span for one cashier', function (string $period, string $date, string $expected) {
    ($this->sell)($this->rosa, '2026-08-23 10:00', 1);   // Sunday
    ($this->sell)($this->rosa, '2026-08-19 10:00', 2);   // same week and month
    ($this->sell)($this->rosa, '2026-03-02 10:00', 4);   // same year only
    ($this->sell)($this->rosa, '2025-08-23 10:00', 8);   // previous year

    $this->actingAs($this->admin)
        ->get(route('sales.report', [
            'period' => $period,
            'date' => $date,
            'cashier' => $this->rosa->id,
        ]))
        ->assertOk()
        ->assertSee($expected);
})->with([
    // 23 Aug 2026 alone: 1 box.
    ['daily', '2026-08-23', '100.00'],
    // Week containing it starts Mon 17 Aug, so it picks up the 19th too: 3.
    ['weekly', '2026-08-23', '300.00'],
    // August 2026: the 19th and the 23rd: 3 boxes.
    ['monthly', '2026-08-23', '300.00'],
    // All of 2026 adds the March sale: 7 boxes.
    ['yearly', '2026-08-23', '700.00'],
]);

test('a period with no sales still renders rather than erroring', function () {
    $this->actingAs($this->admin)
        ->get(route('sales.report', [
            'period' => 'monthly',
            'date' => '2026-01-15',
            'cashier' => $this->rosa->id,
        ]))
        ->assertOk()
        ->assertSee('Rosa Cashier');
});

/* ----------------------------------------------------------- Who may look */

test('a cashier is pinned to their own figures whatever they ask for', function () {
    ($this->sell)($this->rosa, '2026-08-23 10:00', 3);
    ($this->sell)($this->ben, '2026-08-23 11:00', 5);

    // Rosa asking for Ben's report still gets her own.
    $this->actingAs($this->rosa)
        ->get(route('sales.report', [
            'period' => 'daily',
            'date' => '2026-08-23',
            'cashier' => $this->ben->id,
        ]))
        ->assertOk()
        ->assertSee('300.00')
        ->assertDontSee('Ben Cashier');
});

test('a branch manager cannot report on another branch\'s cashier', function () {
    $manager = User::factory()->create([
        'name' => 'Mia Manager',
        'role' => UserRole::MANAGER,
        'branch_id' => $this->main->id,
        'is_active' => true,
    ]);

    $outsider = User::factory()->create([
        'name' => 'Otto Outsider',
        'role' => UserRole::CASHIER,
        'branch_id' => $this->other->id,
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('sales.report', [
            'period' => 'daily',
            'date' => '2026-08-23',
            'cashier' => $outsider->id,
        ]))
        ->assertNotFound();
});

test('a manager can report on a cashier in their own branch', function () {
    $manager = User::factory()->create([
        'role' => UserRole::MANAGER,
        'branch_id' => $this->main->id,
        'is_active' => true,
    ]);

    ($this->sell)($this->rosa, '2026-08-23 10:00', 3);

    $this->actingAs($manager)
        ->get(route('sales.report', [
            'period' => 'daily',
            'date' => '2026-08-23',
            'cashier' => $this->rosa->id,
        ]))
        ->assertOk()
        ->assertSee('Rosa Cashier');
});

test('an unknown cashier id is refused rather than silently widened', function () {
    $this->actingAs($this->admin)
        ->get(route('sales.report', [
            'period' => 'daily',
            'date' => '2026-08-23',
            'cashier' => 999999,
        ]))
        ->assertNotFound();
});

/* ------------------------------------------------- The Sales & Profit page */

test('the sales list no longer prints reports', function () {
    // Printing moved to the Reports menu. Reaching last month's figures by way
    // of a list of today's tickets was the wrong door.
    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Resources\SaleResource\Pages\ListSales::class)
        ->assertActionDoesNotExist('printReport');
});

test('the Sales & Profit page offers a cashier picker to management', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Pages\SalesProfitReport::class)
        ->assertSee('Rosa Cashier')
        ->assertSee('Ben Cashier');
});

test('the page sends the chosen cashier through to the report', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Pages\SalesProfitReport::class)
        ->fillForm([
            'period' => 'monthly',
            'date' => '2026-08-23',
            'cashier' => $this->rosa->id,
        ])
        ->call('openReport')
        ->assertRedirect(route('sales.report', [
            'period' => 'monthly',
            'date' => '2026-08-23',
            'cashier' => $this->rosa->id,
        ]));
});

test('a blank pick sends no cashier at all', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Pages\SalesProfitReport::class)
        ->fillForm([
            'period' => 'daily',
            'date' => '2026-08-23',
            'cashier' => null,
        ])
        ->call('openReport')
        ->assertRedirect(route('sales.report', [
            'period' => 'daily',
            'date' => '2026-08-23',
        ]));
});

test('a cashier cannot reach the picker at all', function () {
    $this->actingAs($this->rosa);

    expect(App\Filament\Pages\SalesProfitReport::canAccess())->toBeFalse();
});
