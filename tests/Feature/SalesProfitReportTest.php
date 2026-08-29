<?php

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Pages\SalesProfitReport;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\PosService;

/**
 * What the shop made, not just what it took.
 *
 * Revenue alone does not tell an owner whether a month was worth having, so
 * the printable report carries cost of goods sold, gross profit and margin
 * beside the takings — for the period as a whole, per day, per product, per
 * category and per cashier. None of it reaches the floor: cost prices are
 * management's business.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->painRelief = Category::create(['name' => 'Pain Relief']);
    $this->vitamins = Category::create(['name' => 'Vitamins']);

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

    $this->ben = User::factory()->create([
        'name' => 'Ben Cashier',
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    // Sells for 100, cost 60 — 40 a box, a 40% margin.
    $this->biogesic = Product::create([
        'sku' => 'BIOG-500',
        'name' => 'Biogesic 500mg',
        'category_id' => $this->painRelief->id,
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 60,
        'selling_price' => 100,
        'is_batch_tracked' => false,
    ]);

    // Sells for 200, cost 190 — 10 a box, a thin 5% margin. It moves more
    // units than Biogesic but earns less, which is the point of ranking the
    // product table by profit rather than by volume.
    $this->ceelin = Product::create([
        'sku' => 'CEEL-100',
        'name' => 'Ceelin Drops',
        'category_id' => $this->vitamins->id,
        'unit' => 'Box',
        'piece_unit' => 'Bottle',
        'pieces_per_box' => 10,
        'cost_price' => 190,
        'selling_price' => 200,
        'is_batch_tracked' => false,
    ]);

    foreach ([$this->biogesic, $this->ceelin] as $product) {
        BranchInventory::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'quantity' => 100000,
        ]);
    }

    $this->sell = function (User $cashier, Product $product, string $when, int $boxes): void {
        $this->actingAs($cashier);

        $this->travelTo(Carbon\Carbon::parse($when), function () use ($product, $boxes) {
            app(PosService::class)->checkout(
                cart: [[
                    'product_id' => $product->id,
                    'unit' => 'BOX',
                    'quantity' => $boxes,
                    'unit_price' => (float) $product->selling_price,
                ]],
                branchId: $this->branch->id,
                amountPaid: (float) $product->selling_price * $boxes,
                paymentMethod: PaymentMethod::CASH,
            );
        });
    };

    $this->report = fn (array $query = []) => $this->actingAs($this->admin)
        ->get(route('sales.report', array_merge(
            ['period' => 'daily', 'date' => '2026-08-23'],
            $query,
        )));
});

/* ------------------------------------------------------------------ Totals */

test('the report carries cost, gross profit and margin for the period', function () {
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-23 10:00', 3);

    ($this->report)()
        ->assertOk()
        ->assertSee('Total sales')
        ->assertSee('Cost of goods sold')
        // 3 boxes: 300 taken, 180 of it cost, 120 earned at a 40% margin.
        ->assertSee('300.00')
        ->assertSee('180.00')
        ->assertSee('120.00')
        ->assertSee('40.0% margin', false);
});

test('a period with no sales reports no profit rather than erroring', function () {
    ($this->report)(['date' => '2020-01-01'])
        ->assertOk()
        ->assertSee('No completed sales in this period.');
});

/* ----------------------------------------------------------------- Buckets */

test('a weekly report breaks profit down by day', function () {
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-19 10:00', 1);
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-21 10:00', 2);

    ($this->report)(['period' => 'weekly'])
        ->assertOk()
        ->assertSee('Profit by day')
        ->assertSee('Wed, 19 Aug 2026')
        ->assertSee('Fri, 21 Aug 2026');
});

test('a yearly report breaks profit down by month instead', function () {
    ($this->sell)($this->rosa, $this->biogesic, '2026-03-02 10:00', 1);
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-23 10:00', 1);

    ($this->report)(['period' => 'yearly'])
        ->assertOk()
        ->assertSee('Profit by month')
        ->assertSee('March 2026')
        ->assertSee('August 2026')
        ->assertDontSee('Profit by day');
});

/* -------------------------------------------------------------- Breakdowns */

test('products are ranked by what they earned, not by what moved', function () {
    // Ceelin sells more and takes more money, Biogesic earns more.
    ($this->sell)($this->rosa, $this->ceelin, '2026-08-23 10:00', 5);    // 1000 taken, 50 earned
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-23 11:00', 3);  // 300 taken, 120 earned

    $body = ($this->report)()->assertOk()->assertSee('Top products by profit')->getContent();

    $table = substr($body, strpos($body, 'Top products by profit'));

    expect(strpos($table, 'Biogesic 500mg'))->toBeLessThan(strpos($table, 'Ceelin Drops'));
});

test('profit is grouped by category', function () {
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-23 10:00', 3);
    ($this->sell)($this->rosa, $this->ceelin, '2026-08-23 11:00', 1);

    ($this->report)()
        ->assertOk()
        ->assertSee('By category')
        ->assertSee('Pain Relief')
        ->assertSee('Vitamins');
});

test('profit is grouped by cashier when the report covers everyone', function () {
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-23 10:00', 3);
    ($this->sell)($this->ben, $this->biogesic, '2026-08-23 11:00', 2);

    ($this->report)()
        ->assertOk()
        ->assertSee('By cashier')
        ->assertSee('Rosa Cashier')
        ->assertSee('Ben Cashier');
});

test('the cashier breakdown is dropped when the report is already one cashier', function () {
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-23 10:00', 3);

    ($this->report)(['cashier' => $this->rosa->id])
        ->assertOk()
        ->assertDontSee('By cashier');
});

/* ------------------------------------------------------------------ Access */

test('a cashier sees their takings but never the cost or the profit', function () {
    ($this->sell)($this->rosa, $this->biogesic, '2026-08-23 10:00', 3);

    $this->actingAs($this->rosa)
        ->get(route('sales.report', ['period' => 'daily', 'date' => '2026-08-23']))
        ->assertOk()
        ->assertSee('300.00')
        ->assertDontSee('Cost of goods sold')
        ->assertDontSee('Gross profit')
        ->assertDontSee('Top products by profit')
        ->assertDontSee('180.00');
});

test('the Sales & Profit page is management only', function () {
    $this->actingAs($this->rosa);
    expect(SalesProfitReport::canAccess())->toBeFalse();

    $this->actingAs($this->admin);
    expect(SalesProfitReport::canAccess())->toBeTrue();
});

test('management can open the Sales & Profit page', function () {
    $this->actingAs($this->admin)
        ->get(SalesProfitReport::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('This month')
        ->assertSee('This year');
});
