<?php

use App\Models\Product;

/**
 * Reading a shelf count back in the units a person actually handles.
 *
 * 2490 is the honest number — it is what the shelf holds in tablets — but
 * nobody counts a stockroom in tablets. The breakdown walks the same ladder
 * the till offers, so what the page says matches what the cashier picks.
 */
beforeEach(function () {
    // The product from the stock levels screen: a box of 500, blisters of 10.
    $this->test1 = Product::create([
        'sku' => 'TEST1-500MG-322',
        'name' => 'Test1 500mg',
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 500,
        'cost_price' => 2000,
        'selling_price' => 3500,
        'is_batch_tracked' => false,
    ]);

    $this->test1->packs()->create(['label' => 'Blister', 'pieces' => 10]);
});

test('a real shelf count reads as boxes and blisters', function () {
    // 2490 = 4 boxes of 500, then 490 left over = 49 blisters of 10.
    expect($this->test1->formatPieces(2490))->toBe('4 Box + 49 Blister');
});

test('loose tablets show alongside the boxes and blisters', function () {
    // 2493 = 4 Box (2000) + 49 Blister (490) + 3 loose.
    expect($this->test1->formatPieces(2493))->toBe('4 Box + 49 Blister + 3 Tablet');
});

test('tiers that come to nothing are left out', function () {
    expect($this->test1->formatPieces(2000))->toBe('4 Box')
        ->and($this->test1->formatPieces(2003))->toBe('4 Box + 3 Tablet')
        ->and($this->test1->formatPieces(30))->toBe('3 Blister')
        ->and($this->test1->formatPieces(7))->toBe('7 Tablet');
});

test('an empty shelf is named in the smallest unit, not the box', function () {
    expect($this->test1->formatPieces(0))->toBe('0 Tablet');
});

test('two pack sizes are both used, largest first', function () {
    $diapers = Product::create([
        'sku' => 'DIAP-L',
        'name' => 'Diapers Large',
        'unit' => 'Case',
        'piece_unit' => 'pc',
        'pieces_per_box' => 48,
        'cost_price' => 400,
        'selling_price' => 600,
        'is_batch_tracked' => false,
    ]);

    $diapers->packs()->createMany([
        ['label' => 'Pack of 12', 'pieces' => 12],
        ['label' => 'Pack of 6', 'pieces' => 6],
    ]);

    // 71 = 1 Case (48) + 1 Pack of 12 (60) + 1 Pack of 6 (66) + 5 loose.
    expect($diapers->formatPieces(71))->toBe('1 Case + 1 Pack of 12 + 1 Pack of 6 + 5 pc');
});

test('a product sold whole still reads as a plain count', function () {
    $thermometer = Product::create([
        'sku' => 'THERM-1',
        'name' => 'Digital Thermometer',
        'unit' => 'Piece',
        'pieces_per_box' => 1,
        'cost_price' => 165,
        'selling_price' => 245,
        'is_batch_tracked' => false,
    ]);

    expect($thermometer->formatPieces(3))->toBe('3 Piece')
        ->and($thermometer->formatPieces(0))->toBe('0 Piece');
});

test('a product with no blisters falls back to boxes and pieces', function () {
    $this->test1->packs()->delete();

    expect($this->test1->fresh()->formatPieces(2490))->toBe('4 Box + 490 Tablet');
});
