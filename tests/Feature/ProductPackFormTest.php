<?php

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->branch = Branch::create(['code' => 'MAIN', 'name' => 'Main Branch']);

    $this->admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'branch_id' => $this->branch->id,
    ]);
});

/* ------------------------------------------------------- The summary card */

test('the summary spells the ladder out the way a pharmacist reads it', function () {
    $summary = ProductResource::packSummary([
        'unit' => 'Box',
        'piece_unit' => 'Tablet',
        'pieces_per_box' => 500,
        'packs' => [['label' => 'Blister', 'pieces' => 10]],
    ])->toHtml();

    expect($summary)
        ->toContain('Unit:')
        ->toContain('Tablet')
        ->toContain('10 Tablet / Blister')
        // 500 tablets in blisters of 10 is 50 blisters to the box.
        ->toContain('50 Blisters / Box')
        ->toContain('500 Tablets');
});

test('two pack sizes are both listed, largest first', function () {
    $summary = ProductResource::packSummary([
        'unit' => 'Case',
        'piece_unit' => 'pc',
        'pieces_per_box' => 48,
        'packs' => [
            ['label' => 'Pack of 6', 'pieces' => 6],
            ['label' => 'Pack of 12', 'pieces' => 12],
        ],
    ])->toHtml();

    expect(strpos($summary, 'Pack of 12'))->toBeLessThan(strpos($summary, 'Pack of 6'))
        ->and($summary)->toContain('48 pcs');
});

test('a product sold whole says so instead of showing a ladder', function () {
    $summary = ProductResource::packSummary([
        'unit' => 'Bottle',
        'piece_unit' => '',
        'pieces_per_box' => 1,
        'packs' => [],
    ])->toHtml();

    expect($summary)->toContain('Sold whole only')
        ->and($summary)->not->toContain('Total per');
});

test('a tier that does not divide the box evenly skips the box-size line', function () {
    // 7 does not go into 20, so "how many blisters to a box" has no answer.
    $summary = ProductResource::packSummary([
        'unit' => 'Box',
        'piece_unit' => 'Tab',
        'pieces_per_box' => 20,
        'packs' => [['label' => 'Blister', 'pieces' => 7]],
    ])->toHtml();

    expect($summary)->toContain('7 Tab / Blister')
        ->and($summary)->not->toContain('/ Box</span>')
        ->and($summary)->toContain('20 Tabs');
});

test('a tier bigger than the whole unit is left out of the summary', function () {
    $summary = ProductResource::packSummary([
        'unit' => 'Box',
        'piece_unit' => 'Tab',
        'pieces_per_box' => 20,
        'packs' => [['label' => 'Oversized', 'pieces' => 50]],
    ])->toHtml();

    expect($summary)->not->toContain('Oversized');
});

/* ---------------------------------------------------------- Saving a form */

test('pack tiers save against the product and drive the till', function () {
    $this->actingAs($this->admin);

    $product = Product::create([
        'sku' => 'DIAP-M',
        'name' => 'Dry Comfort Diapers Medium',
        'unit' => 'Case',
        'piece_unit' => 'pc',
        'pieces_per_box' => 48,
        'cost_price' => 400,
        'selling_price' => 600,
        'is_batch_tracked' => false,
    ]);

    $product->packs()->createMany([
        ['label' => 'Pack of 12', 'pieces' => 12],
        ['label' => 'Pack of 6', 'pieces' => 6],
    ]);

    expect($product->fresh()->sellingUnitOptions())->toHaveCount(4)
        ->and($product->fresh()->hasPackTiers())->toBeTrue();
});

test('deleting a product takes its pack tiers with it', function () {
    $product = Product::create([
        'sku' => 'TEMP-1',
        'name' => 'Temporary',
        'unit' => 'Box',
        'piece_unit' => 'pc',
        'pieces_per_box' => 10,
        'cost_price' => 1,
        'selling_price' => 2,
        'is_batch_tracked' => false,
    ]);

    $product->packs()->create(['label' => 'Half', 'pieces' => 5]);
    $productId = $product->id;

    $product->delete();

    expect(App\Models\ProductPack::where('product_id', $productId)->count())->toBe(0);
});
