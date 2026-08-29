<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use App\Services\PosService;
use App\Support\SellingOption;

/**
 * One product, several pack sizes.
 *
 * Diapers are the awkward case the single blister column could not hold: the
 * same item leaves the counter loose, in a six, or in a twelve, and all three
 * draw on one shelf count.
 */
beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    // A case of 48, sold as packs of 12, packs of 6, or single diapers.
    $this->diapers = Product::create([
        'sku' => 'DIAP-L',
        'name' => 'Dry Comfort Diapers Large',
        'unit' => 'Case',
        'piece_unit' => 'pc',
        'pieces_per_box' => 48,
        'cost_price' => 480,
        'selling_price' => 720,
        'is_batch_tracked' => false,
    ]);

    $this->twelve = $this->diapers->packs()->create(['label' => 'Pack of 12', 'pieces' => 12]);
    $this->six = $this->diapers->packs()->create(['label' => 'Pack of 6', 'pieces' => 6]);

    $this->stock = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->diapers->id,
        'quantity' => 96,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
    ]);

    $this->pos = app(PosService::class);
    $this->actingAs($this->cashier);
});

/* --------------------------------------------------------- The unit list */

test('a product may offer two pack sizes at once', function () {
    expect($this->diapers->sellingUnitOptions())->toBe([
        'BOX' => 'Case (48 pc)',
        'PACK:'.$this->twelve->id => 'Pack of 12 (12 pc)',
        'PACK:'.$this->six->id => 'Pack of 6 (6 pc)',
        'PIECE' => 'pc',
    ]);
});

test('pack tiers are listed largest first whatever order they were added', function () {
    $this->diapers->packs()->create(['label' => 'Pack of 24', 'pieces' => 24]);

    expect(array_values(array_map(
        fn ($option) => $option->pieces,
        $this->diapers->fresh()->sellingOptions()
    )))->toBe([48, 24, 12, 6, 1]);
});

test('a tier as large as the whole case is ignored as a duplicate', function () {
    $this->diapers->packs()->create(['label' => 'Full case', 'pieces' => 48]);

    expect(array_keys($this->diapers->fresh()->sellingUnitOptions()))
        ->not->toContain('PACK:'.$this->diapers->packs()->where('pieces', 48)->value('id'));
});

test('the same size cannot be added twice', function () {
    expect(fn () => $this->diapers->packs()->create(['label' => 'Half dozen', 'pieces' => 6]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

/* ------------------------------------------------------------- Deducting */

test('selling a pack of six takes six pieces off the shelf', function () {
    $this->pos->checkout(
        cart: [[
            'product_id' => $this->diapers->id,
            'unit' => 'PACK:'.$this->six->id,
            'quantity' => 1,
            'unit_price' => $this->diapers->priceFor('PACK:'.$this->six->id),
        ]],
        branchId: $this->branch->id,
        amountPaid: 200,
    );

    expect($this->stock->fresh()->quantity)->toBe(90);
});

test('four packs of twelve empty exactly one case', function () {
    $sale = $this->pos->checkout(
        cart: [[
            'product_id' => $this->diapers->id,
            'unit' => 'PACK:'.$this->twelve->id,
            'quantity' => 4,
            'unit_price' => $this->diapers->priceFor('PACK:'.$this->twelve->id),
        ]],
        branchId: $this->branch->id,
        amountPaid: 1000,
    );

    // 96 pieces was two cases; four twelves is 48, so one whole case remains.
    expect($this->stock->fresh()->quantity)->toBe(48)
        ->and($this->diapers->formatPieces(48))->toBe('1 Case');

    $item = $sale->items->first();

    expect($item->unit_label)->toBe('Pack of 12')
        ->and($item->unit_multiplier)->toBe(12)
        ->and($item->pieces)->toBe(48);
});

test('both pack sizes and loose pieces draw on the same shelf count', function () {
    $this->pos->checkout(
        cart: [
            [
                'product_id' => $this->diapers->id,
                'unit' => 'PACK:'.$this->twelve->id,
                'quantity' => 1,
                'unit_price' => $this->diapers->priceFor('PACK:'.$this->twelve->id),
            ],
            [
                'product_id' => $this->diapers->id,
                'unit' => 'PACK:'.$this->six->id,
                'quantity' => 2,
                'unit_price' => $this->diapers->priceFor('PACK:'.$this->six->id),
            ],
            [
                'product_id' => $this->diapers->id,
                'unit' => SellingOption::PIECE,
                'quantity' => 3,
                'unit_price' => $this->diapers->priceFor(SellingOption::PIECE),
            ],
        ],
        branchId: $this->branch->id,
        amountPaid: 1000,
    );

    // 12 + 12 + 3 = 27 pieces gone.
    expect($this->stock->fresh()->quantity)->toBe(69);
});

test('a deleted pack tier does not strand a cart line', function () {
    $key = 'PACK:'.$this->six->id;
    $this->six->delete();

    $this->pos->checkout(
        cart: [[
            'product_id' => $this->diapers->id,
            'unit' => $key,
            'quantity' => 1,
            'unit_price' => 720,
        ]],
        branchId: $this->branch->id,
        amountPaid: 1000,
    );

    // Falls back to the whole case rather than throwing mid-sale.
    expect($this->stock->fresh()->quantity)->toBe(48);
});

/* --------------------------------------------------------------- Pricing */

test('each pack size is priced from the case, rounded up to the centavo', function () {
    // 720.00 over 48 is 15.00 exactly.
    expect($this->diapers->priceFor(SellingOption::BOX))->toBe(720.0)
        ->and($this->diapers->priceFor('PACK:'.$this->twelve->id))->toBe(180.0)
        ->and($this->diapers->priceFor('PACK:'.$this->six->id))->toBe(90.0)
        ->and($this->diapers->priceFor(SellingOption::PIECE))->toBe(15.0);
});

/* ------------------------------------------------- Products without tiers */

test('a product with no pack tiers is untouched by any of this', function () {
    $shampoo = Product::create([
        'sku' => 'SHMP-1',
        'name' => 'Shampoo Sachet',
        'unit' => 'Sachet',
        'pieces_per_box' => 1,
        'cost_price' => 6,
        'selling_price' => 10,
        'is_batch_tracked' => false,
    ]);

    expect($shampoo->sellingUnitOptions())->toBe(['BOX' => 'Sachet'])
        ->and($shampoo->hasPackTiers())->toBeFalse();
});
