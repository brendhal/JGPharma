<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PosService;
use App\Support\SellingOption;

beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    // A box of 20 tablets, sold in blisters of 10.
    $this->neozep = Product::create([
        'sku' => 'NEOZEP-1',
        'name' => 'Neozep Forte Tablet',
        'unit' => 'Box',
        'piece_unit' => 'Tab',
        'pieces_per_box' => 20,
        'cost_price' => 96,
        'selling_price' => 140,
        'minimum_stock' => 40,
        'is_batch_tracked' => false,
    ]);

    // Sold whole, never broken open.
    $this->thermometer = Product::create([
        'sku' => 'THERM-1',
        'name' => 'Digital Thermometer',
        'unit' => 'Piece',
        'pieces_per_box' => 1,
        'cost_price' => 165,
        'selling_price' => 245,
        'is_batch_tracked' => false,
    ]);

    $this->blister = $this->neozep->packs()->create(['label' => 'Blister', 'pieces' => 10]);
    $this->blisterKey = 'PACK:'.$this->blister->id;

    $this->stock = BranchInventory::create([
        'branch_id' => $this->branch->id,
        'product_id' => $this->neozep->id,
        'quantity' => 100,
    ]);

    $this->cashier = User::factory()->create([
        'role' => UserRole::CASHIER,
        'branch_id' => $this->branch->id,
    ]);

    $this->pos = app(PosService::class);
});

/* ------------------------------------------------------------ Unit maths */

test('a divisible pack offers box, blister and piece', function () {
    expect(array_keys($this->neozep->sellingUnitOptions()))
        ->toBe(['BOX', $this->blisterKey, 'PIECE']);
});

test('an indivisible product offers only itself', function () {
    expect(array_keys($this->thermometer->sellingUnitOptions()))->toBe(['BOX'])
        ->and($this->thermometer->isDivisible())->toBeFalse();
});

test('a box with no blisters skips the blister tier', function () {
    $this->blister->delete();

    expect(array_keys($this->neozep->fresh()->sellingUnitOptions()))->toBe(['BOX', 'PIECE']);
});

test('each unit converts to the right number of pieces', function () {
    expect($this->neozep->piecesPer(SellingOption::BOX))->toBe(20)
        ->and($this->neozep->piecesPer($this->blisterKey))->toBe(10)
        ->and($this->neozep->piecesPer(SellingOption::PIECE))->toBe(1);
});

test('partial prices divide the pack price and round up to the centavo', function () {
    expect($this->neozep->priceFor(SellingOption::BOX))->toBe(140.0)
        ->and($this->neozep->priceFor($this->blisterKey))->toBe(70.0)
        ->and($this->neozep->priceFor(SellingOption::PIECE))->toBe(7.0);
});

test('a price that does not divide evenly rounds up rather than down', function () {
    // 65.00 over 20 is 3.25 exactly; 65.00 over 21 is 3.0952...
    $this->neozep->update(['pieces_per_box' => 21, 'selling_price' => 65]);

    expect($this->neozep->fresh()->priceFor(SellingOption::PIECE))->toBe(3.10);
});

test('stock reads back as packs plus loose pieces', function () {
    expect($this->neozep->formatPieces(100))->toBe('5 Box')
        ->and($this->neozep->formatPieces(87))->toBe('4 Box + 7 Tab')
        ->and($this->neozep->formatPieces(7))->toBe('7 Tab')
        ->and($this->thermometer->formatPieces(3))->toBe('3 Piece');
});

/* ------------------------------------------------------------- Selling */

test('selling three tablets takes three off the box', function () {
    $this->actingAs($this->cashier);

    $sale = $this->pos->checkout(
        cart: [[
            'product_id' => $this->neozep->id,
            'unit' => SellingOption::PIECE,
            'quantity' => 3,
            'unit_price' => $this->neozep->priceFor(SellingOption::PIECE),
        ]],
        branchId: $this->branch->id,
        amountPaid: 100,
    );

    expect($this->stock->fresh()->quantity)->toBe(97);

    $item = $sale->items->first();

    expect($item->quantity)->toBe(3)
        ->and($item->unit_label)->toBe('Tab')
        ->and($item->unit_multiplier)->toBe(1)
        ->and($item->pieces)->toBe(3)
        ->and((float) $item->subtotal)->toBe(21.0);
});

test('selling one blister takes ten off the box', function () {
    $this->actingAs($this->cashier);

    $this->pos->checkout(
        cart: [[
            'product_id' => $this->neozep->id,
            'unit' => $this->blisterKey,
            'quantity' => 1,
            'unit_price' => $this->neozep->priceFor($this->blisterKey),
        ]],
        branchId: $this->branch->id,
        amountPaid: 100,
    );

    expect($this->stock->fresh()->quantity)->toBe(90);
});

test('selling two boxes takes forty pieces', function () {
    $this->actingAs($this->cashier);

    $sale = $this->pos->checkout(
        cart: [[
            'product_id' => $this->neozep->id,
            'unit' => SellingOption::BOX,
            'quantity' => 2,
            'unit_price' => $this->neozep->priceFor(SellingOption::BOX),
        ]],
        branchId: $this->branch->id,
        amountPaid: 500,
    );

    expect($this->stock->fresh()->quantity)->toBe(60)
        ->and((float) $sale->items->first()->subtotal)->toBe(280.0);
});

test('a cart may hold the same product in two units at once', function () {
    $this->actingAs($this->cashier);

    $this->pos->checkout(
        cart: [
            [
                'product_id' => $this->neozep->id,
                'unit' => SellingOption::BOX,
                'quantity' => 1,
                'unit_price' => $this->neozep->priceFor(SellingOption::BOX),
            ],
            [
                'product_id' => $this->neozep->id,
                'unit' => SellingOption::PIECE,
                'quantity' => 4,
                'unit_price' => $this->neozep->priceFor(SellingOption::PIECE),
            ],
        ],
        branchId: $this->branch->id,
        amountPaid: 500,
    );

    expect($this->stock->fresh()->quantity)->toBe(76);
});

test('a line with no unit still sells as a whole pack', function () {
    $this->actingAs($this->cashier);

    $this->pos->checkout(
        cart: [[
            'product_id' => $this->neozep->id,
            'quantity' => 1,
            'unit_price' => 140,
        ]],
        branchId: $this->branch->id,
        amountPaid: 200,
    );

    expect($this->stock->fresh()->quantity)->toBe(80);
});

test('the shelf cannot be oversold in pieces', function () {
    $this->actingAs($this->cashier);
    $this->stock->update(['quantity' => 5]);

    $sell = fn () => $this->pos->checkout(
        cart: [[
            'product_id' => $this->neozep->id,
            'unit' => SellingOption::BOX,
            'quantity' => 1,
            'unit_price' => 140,
        ]],
        branchId: $this->branch->id,
        amountPaid: 200,
    );

    expect($sell)->toThrow(RuntimeException::class);
    expect($this->stock->fresh()->quantity)->toBe(5);
});

/* -------------------------------------------------------------- Costing */

test('margin on a loose tablet is costed per tablet, not per box', function () {
    $this->actingAs($this->cashier);

    $sale = $this->pos->checkout(
        cart: [[
            'product_id' => $this->neozep->id,
            'unit' => SellingOption::PIECE,
            'quantity' => 5,
            'unit_price' => 7.0,
        ]],
        branchId: $this->branch->id,
        amountPaid: 100,
    );

    $item = $sale->items->first();

    // 96.00 a box of 20 is 4.80 a tablet.
    expect((float) $item->cost_price)->toBe(4.80)
        ->and($item->line_profit)->toBe(35.0 - 24.0);
});

/* --------------------------------------------------------------- Voiding */

test('voiding a part-pack sale puts the pieces back', function () {
    $this->actingAs($this->cashier);

    $sale = $this->pos->checkout(
        cart: [[
            'product_id' => $this->neozep->id,
            'unit' => SellingOption::PIECE,
            'quantity' => 6,
            'unit_price' => 7.0,
        ]],
        branchId: $this->branch->id,
        amountPaid: 100,
    );

    expect($this->stock->fresh()->quantity)->toBe(94);

    $this->pos->void(Sale::find($sale->id), 'Wrong item');

    expect($this->stock->fresh()->quantity)->toBe(100);
});
