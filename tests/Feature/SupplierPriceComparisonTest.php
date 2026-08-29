<?php

use App\Enums\PurchaseStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SupplierPriceComparison as ComparisonPage;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;

/**
 * The sheet the owner buys from.
 *
 * The same medicine comes from more than one supplier at more than one price,
 * and until now nothing put those prices next to each other. This report reads
 * them off the purchase orders already in the system: the newest order with a
 * supplier is what they charge, the cheapest of those is who to order from,
 * and the volume already bought says what the difference has cost.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Public Market Branch']);

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

    $this->antibiotics = Category::create(['name' => 'Antibiotics', 'is_active' => true]);
    $this->vitamins = Category::create(['name' => 'Vitamins', 'is_active' => true]);

    $this->medi = Supplier::create(['name' => 'MediSupply Ph', 'is_active' => true]);
    $this->pharma = Supplier::create(['name' => 'PharmaTrade Inc', 'is_active' => true]);
    $this->tri = Supplier::create(['name' => 'TriCare Distribution', 'is_active' => true]);

    $this->amoxicillin = Product::create([
        'category_id' => $this->antibiotics->id,
        'sku' => 'AMOX-500',
        'name' => 'Amoxicillin 500mg Capsule',
        'unit' => 'Box',
        'piece_unit' => 'Capsule',
        'pieces_per_box' => 100,
        'cost_price' => 145,
        'selling_price' => 220,
        'is_batch_tracked' => true,
    ]);

    $this->ascorbic = Product::create([
        'category_id' => $this->vitamins->id,
        'sku' => 'ASCO-500',
        'name' => 'Ascorbic Acid 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 100,
        'cost_price' => 90,
        'selling_price' => 140,
        'is_batch_tracked' => false,
    ]);

    /** An order placed with a supplier for one product, at a price, on a date. */
    $this->order = function (
        Supplier $supplier,
        float $cost,
        int $quantity,
        string $date,
        ?Product $product = null,
        PurchaseStatus $status = PurchaseStatus::RECEIVED,
    ): Purchase {
        $purchase = Purchase::create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'purchase_date' => $date,
            'status' => $status,
        ]);

        $purchase->items()->create([
            'product_id' => ($product ?? $this->amoxicillin)->id,
            'quantity' => $quantity,
            'cost_price' => $cost,
        ]);

        return $purchase;
    };

    $this->report = fn (array $query = []) => $this->actingAs($this->admin)
        ->get(route('suppliers.price-comparison', $query));
});

/* ------------------------------------------------------------- Comparison */

it('puts both suppliers prices for the same item side by side', function () {
    ($this->order)($this->medi, 145, 10, now()->subMonth()->toDateString());
    ($this->order)($this->pharma, 132.50, 4, now()->subWeek()->toDateString());

    ($this->report)()
        ->assertOk()
        ->assertSee('Amoxicillin 500mg Capsule')
        ->assertSee('MediSupply Ph')
        ->assertSee('PharmaTrade Inc')
        ->assertSee('145.00')
        ->assertSee('132.50');
});

it('names the cheapest supplier as the one to order from', function () {
    ($this->order)($this->medi, 145, 10, now()->subMonth()->toDateString());
    ($this->order)($this->pharma, 132.50, 4, now()->subWeek()->toDateString());
    ($this->order)($this->tri, 151, 2, now()->subWeek()->toDateString());

    ($this->report)()
        ->assertOk()
        ->assertSee('Order from')
        // The 12.50 gap on every one of the ten boxes bought from MediSupply,
        // and the 18.50 gap on the two from TriCare.
        ->assertSee('162.00');
});

it('quotes what a supplier charged last, not what they charged first', function () {
    ($this->order)($this->medi, 120, 5, now()->subMonths(6)->toDateString());
    ($this->order)($this->medi, 155, 5, now()->subDays(3)->toDateString());
    ($this->order)($this->pharma, 140, 5, now()->subDays(2)->toDateString());

    // The old 120 is history; MediSupply now asks 155, so PharmaTrade is cheaper.
    ($this->report)()
        ->assertOk()
        ->assertSee('155.00')
        ->assertSee('140.00');
});

it('adds up what the dearer supplier has cost so far', function () {
    ($this->order)($this->medi, 150, 20, now()->subMonth()->toDateString());
    ($this->order)($this->pharma, 130, 10, now()->subWeek()->toDateString());

    // 20 boxes bought 20.00 above the cheapest price on the row.
    ($this->report)()
        ->assertOk()
        ->assertSee('Could have been saved')
        ->assertSee('400.00');
});

/* --------------------------------------------------------------- What counts */

it('ignores a cancelled order', function () {
    ($this->order)($this->medi, 145, 10, now()->subWeek()->toDateString());
    ($this->order)($this->pharma, 99, 10, now()->subDays(2)->toDateString(), null, PurchaseStatus::CANCELLED);

    ($this->report)(['all' => 1])
        ->assertOk()
        ->assertSee('145.00')
        ->assertDontSee('99.00');
});

it('counts a pending order — the price is already agreed', function () {
    ($this->order)($this->medi, 145, 10, now()->subWeek()->toDateString());
    ($this->order)($this->pharma, 128, 6, now()->toDateString(), null, PurchaseStatus::PENDING);

    ($this->report)()
        ->assertOk()
        ->assertSee('128.00')
        ->assertSee('PharmaTrade Inc');
});

it('leaves out prices older than the window asked for', function () {
    ($this->order)($this->medi, 145, 10, now()->subMonths(9)->toDateString());
    ($this->order)($this->pharma, 132, 10, now()->subMonths(9)->toDateString());
    ($this->order)($this->tri, 138, 10, now()->subDays(5)->toDateString());

    ($this->report)(['months' => 3, 'all' => 1])
        ->assertOk()
        ->assertSee('138.00')
        ->assertDontSee('145.00')
        ->assertDontSee('132.00');
});

it('hides single-source items unless they are asked for', function () {
    ($this->order)($this->medi, 145, 10, now()->subWeek()->toDateString());
    ($this->order)($this->pharma, 132, 10, now()->subWeek()->toDateString());
    ($this->order)($this->medi, 90, 5, now()->subWeek()->toDateString(), $this->ascorbic);

    ($this->report)()
        ->assertOk()
        ->assertSee('Amoxicillin 500mg Capsule')
        ->assertDontSee('Ascorbic Acid 500mg');

    ($this->report)(['all' => 1])
        ->assertOk()
        ->assertSee('Ascorbic Acid 500mg')
        ->assertSee('only supplier');
});

it('narrows to one category when asked', function () {
    ($this->order)($this->medi, 145, 10, now()->subWeek()->toDateString());
    ($this->order)($this->pharma, 132, 10, now()->subWeek()->toDateString());
    ($this->order)($this->medi, 90, 5, now()->subWeek()->toDateString(), $this->ascorbic);
    ($this->order)($this->pharma, 84, 5, now()->subWeek()->toDateString(), $this->ascorbic);

    ($this->report)(['category' => $this->vitamins->id])
        ->assertOk()
        ->assertSee('Ascorbic Acid 500mg')
        ->assertDontSee('Amoxicillin 500mg Capsule');
});

/* ------------------------------------------------------------- Order guide */

it('turns the comparison into a list per supplier', function () {
    ($this->order)($this->medi, 145, 10, now()->subWeek()->toDateString());
    ($this->order)($this->pharma, 132, 10, now()->subWeek()->toDateString());
    ($this->order)($this->medi, 88, 5, now()->subWeek()->toDateString(), $this->ascorbic);
    ($this->order)($this->pharma, 95, 5, now()->subWeek()->toDateString(), $this->ascorbic);

    // Amoxicillin is cheapest at PharmaTrade, ascorbic acid at MediSupply, so
    // the guide splits the order between the two rather than sending both to one.
    ($this->report)()
        ->assertOk()
        ->assertSee('What to order from whom')
        ->assertSee('Next cheapest')
        ->assertSee('Write the quantity you want in the last column before sending.');
});

/* ---------------------------------------------------------------- Nothing yet */

it('says so plainly when there is nothing to compare', function () {
    ($this->order)($this->medi, 145, 10, now()->subWeek()->toDateString());

    ($this->report)()
        ->assertOk()
        ->assertSee('nothing to compare yet', false);
});

/* --------------------------------------------------------------------- Access */

it('is closed to a cashier', function () {
    ($this->order)($this->medi, 145, 10, now()->subWeek()->toDateString());

    $this->actingAs($this->rosa)
        ->get(route('suppliers.price-comparison'))
        ->assertForbidden();
});

it('keeps the panel page to management', function () {
    $this->actingAs($this->rosa);
    expect(ComparisonPage::canAccess())->toBeFalse();

    $this->actingAs($this->admin);
    expect(ComparisonPage::canAccess())->toBeTrue();
});

it('shows management the comparison on screen', function () {
    ($this->order)($this->medi, 145, 10, now()->subMonth()->toDateString());
    ($this->order)($this->pharma, 132.50, 4, now()->subWeek()->toDateString());

    $this->actingAs($this->admin)
        ->get(ComparisonPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Supplier price comparison')
        ->assertSee('Amoxicillin 500mg Capsule')
        ->assertSee('MediSupply Ph')
        ->assertSee('PharmaTrade Inc')
        ->assertSee('132.50')
        ->assertSee('Could have been saved');
});
