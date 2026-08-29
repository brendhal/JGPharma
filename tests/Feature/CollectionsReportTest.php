<?php

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Pages\CollectionsReport;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PosService;

/**
 * The sheet you take to the GCash app before cashing out.
 *
 * Cash is counted in the drawer; GCash and card sit with the provider until
 * somebody moves them. This report is the shop's side of that reconciliation:
 * every non-cash payment, its reference number, a subtotal per day, and the
 * amount to withdraw. Cash never appears, and neither does a voided ticket —
 * no money was taken for either.
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
        'name' => 'Ada Admin',
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

    $this->sell = function (
        string $when,
        int $boxes,
        PaymentMethod $method = PaymentMethod::GCASH,
        ?string $reference = '1234567890123',
    ): Sale {
        $this->actingAs($this->rosa);

        return $this->travelTo(Carbon\Carbon::parse($when), fn (): Sale => app(PosService::class)->checkout(
            cart: [[
                'product_id' => $this->product->id,
                'unit' => 'BOX',
                'quantity' => $boxes,
                'unit_price' => 100,
            ]],
            branchId: $this->branch->id,
            amountPaid: 100 * $boxes,
            paymentMethod: $method,
            paymentReference: $reference,
        ));
    };

    $this->report = fn (array $query = []) => $this->actingAs($this->admin)
        ->get(route('collections.report', array_merge(
            ['period' => 'monthly', 'date' => '2026-08-23'],
            $query,
        )));
});

/* ----------------------------------------------------------------- Totals */

test('the report totals what is waiting to be withdrawn', function () {
    ($this->sell)('2026-08-23 10:00', 3, PaymentMethod::GCASH, '1111111111111');
    ($this->sell)('2026-08-23 11:00', 2, PaymentMethod::CARD, '2222222222222');

    ($this->report)()
        ->assertOk()
        ->assertSee('Total to withdraw')
        // 300 on GCash plus 200 on card.
        ->assertSee('500.00')
        ->assertSee('300.00')
        ->assertSee('200.00');
});

test('every payment is listed with its reference number', function () {
    ($this->sell)('2026-08-23 10:00', 1, PaymentMethod::GCASH, '9876543210001');
    ($this->sell)('2026-08-23 11:00', 1, PaymentMethod::GCASH, '9876543210002');

    ($this->report)()
        ->assertOk()
        ->assertSee('9876543210001')
        ->assertSee('9876543210002');
});

test('GCash and card are totalled separately', function () {
    ($this->sell)('2026-08-23 10:00', 7, PaymentMethod::GCASH, '1111111111111');
    ($this->sell)('2026-08-23 11:00', 1, PaymentMethod::CARD, '2222222222222');

    ($this->report)()
        ->assertOk()
        ->assertSee('GCash')
        ->assertSee('Card')
        ->assertSee('700.00')
        ->assertSee('100.00');
});

/* -------------------------------------------------------------- Exclusions */

test('cash sales never appear — that money is in the drawer', function () {
    ($this->sell)('2026-08-23 10:00', 4, PaymentMethod::CASH, null);

    ($this->report)()
        ->assertOk()
        ->assertSee('No GCash payments in this period.')
        ->assertDontSee('400.00');
});

test('a voided ticket drops out of the collections', function () {
    $sale = ($this->sell)('2026-08-23 10:00', 3, PaymentMethod::GCASH, '3333333333333');
    ($this->sell)('2026-08-23 11:00', 1, PaymentMethod::GCASH, '4444444444444');

    app(PosService::class)->void($sale, 'wrong item');

    ($this->report)()
        ->assertOk()
        ->assertDontSee('3333333333333')
        ->assertSee('4444444444444')
        ->assertSee('100.00');
});

test('a legacy payment with no reference is flagged but still counted', function () {
    $sale = ($this->sell)('2026-08-23 10:00', 2, PaymentMethod::GCASH, '5555555555555');

    // Predates the reference becoming compulsory.
    $sale->forceFill(['payment_reference' => null])->save();

    ($this->report)()
        ->assertOk()
        ->assertSee('none recorded')
        ->assertSee('without a reference')
        ->assertSee('200.00');
});

/* ---------------------------------------------------------------- Periods */

test('each period gathers the right span', function (string $period, string $expected) {
    ($this->sell)('2026-08-23 10:00', 1, PaymentMethod::GCASH, '1111111111111');
    ($this->sell)('2026-08-19 10:00', 2, PaymentMethod::GCASH, '2222222222222');
    ($this->sell)('2026-03-02 10:00', 4, PaymentMethod::GCASH, '3333333333333');
    ($this->sell)('2025-08-23 10:00', 8, PaymentMethod::GCASH, '4444444444444');

    ($this->report)(['period' => $period])
        ->assertOk()
        ->assertSee($expected);
})->with([
    ['weekly', '300.00'],
    ['monthly', '300.00'],
    ['yearly', '700.00'],
]);

test('a period with nothing collected still prints', function () {
    ($this->report)(['date' => '2020-01-01'])
        ->assertOk()
        ->assertSee('No GCash payments in this period.')
        ->assertSee('No Card payments in this period.');
});

/* ------------------------------------------------ Separating the providers */

test('a GCash-only sheet leaves the card payments out', function () {
    ($this->sell)('2026-08-23 10:00', 3, PaymentMethod::GCASH, '1111111111111');
    ($this->sell)('2026-08-23 11:00', 2, PaymentMethod::CARD, '2222222222222');

    ($this->report)(['method' => 'GCASH'])
        ->assertOk()
        ->assertSee('GCash')
        ->assertSee('1111111111111')
        ->assertDontSee('2222222222222')
        // 300 on GCash, and no sign of the 200 on card.
        ->assertSee('300.00')
        ->assertDontSee('200.00');
});

test('a card-only sheet leaves the GCash payments out', function () {
    ($this->sell)('2026-08-23 10:00', 3, PaymentMethod::GCASH, '1111111111111');
    ($this->sell)('2026-08-23 11:00', 2, PaymentMethod::CARD, '2222222222222');

    ($this->report)(['method' => 'CARD'])
        ->assertOk()
        ->assertSee('2222222222222')
        ->assertDontSee('1111111111111')
        ->assertSee('200.00');
});

test('naming no method prints both providers together', function () {
    ($this->sell)('2026-08-23 10:00', 3, PaymentMethod::GCASH, '1111111111111');
    ($this->sell)('2026-08-23 11:00', 2, PaymentMethod::CARD, '2222222222222');

    ($this->report)()
        ->assertOk()
        ->assertSee('1111111111111')
        ->assertSee('2222222222222')
        ->assertSee('500.00');
});

test('asking for a cash sheet is refused — there is nothing to withdraw', function () {
    ($this->sell)('2026-08-23 10:00', 4, PaymentMethod::CASH, null);
    ($this->sell)('2026-08-23 11:00', 1, PaymentMethod::GCASH, '1111111111111');

    // CASH is not a collectable method, so the request falls back to covering
    // both providers rather than printing a sheet of drawer money.
    ($this->report)(['method' => 'CASH'])
        ->assertOk()
        ->assertSee('1111111111111')
        ->assertDontSee('400.00');
});

/* ---------------------------------------------------------------- Fallback */

test('a daily collections sheet falls back to the month', function () {
    ($this->sell)('2026-08-02 10:00', 1, PaymentMethod::GCASH, '1111111111111');
    ($this->sell)('2026-08-23 10:00', 2, PaymentMethod::GCASH, '2222222222222');

    // There is no daily sheet: withdrawing is a weekly-or-longer errand, and
    // an old bookmark should still print something sensible.
    ($this->report)(['period' => 'daily'])
        ->assertOk()
        ->assertSee('Monthly')
        ->assertSee('1111111111111')
        ->assertSee('2222222222222')
        ->assertSee('300.00');
});

/* ----------------------------------------------------------------- Access */

test('a cashier cannot open the collections report', function () {
    ($this->sell)('2026-08-23 10:00', 1);

    $this->actingAs($this->rosa)
        ->get(route('collections.report', ['period' => 'monthly', 'date' => '2026-08-23']))
        ->assertForbidden();
});

test('the Collections page is management only', function () {
    $this->actingAs($this->rosa);
    expect(CollectionsReport::canAccess())->toBeFalse();

    $this->actingAs($this->admin);
    expect(CollectionsReport::canAccess())->toBeTrue();
});

test('management can open the Collections page', function () {
    $this->actingAs($this->admin)
        ->get(CollectionsReport::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('GCash this month')
        ->assertSee('Card this month')
        ->assertSee('This week')
        ->assertSee('This month')
        ->assertSee('This year')
        // Withdrawing is a weekly-or-longer errand; there is no daily sheet.
        ->assertDontSee('Daily');
});
