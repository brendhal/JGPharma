<?php

use App\Models\Product;
use App\Models\ProductPack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration drops a column that held live data, so it is worth proving
 * that the blisters already on file survive the move — and that rolling back
 * puts them where the old code expects to find them.
 */
$migration = fn () => require database_path('migrations/2026_08_23_000300_create_product_packs_table.php');

test('an existing blister size becomes a pack tier', function () use ($migration) {
    // Wind back to the old shape: a column, no table.
    $migration()->down();

    expect(Schema::hasColumn('products', 'pieces_per_pack'))->toBeTrue()
        ->and(Schema::hasTable('product_packs'))->toBeFalse();

    DB::table('products')->insert([
        'sku' => 'LEGACY-1',
        'name' => 'Neozep Forte',
        'unit' => 'Box',
        'piece_unit' => 'Tab',
        'pieces_per_box' => 20,
        'pieces_per_pack' => 10,
        'cost_price' => 96,
        'selling_price' => 140,
        'minimum_stock' => 40,
        'is_batch_tracked' => 0,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration()->up();

    $product = Product::where('sku', 'LEGACY-1')->firstOrFail();

    expect(Schema::hasColumn('products', 'pieces_per_pack'))->toBeFalse()
        ->and($product->packs)->toHaveCount(1)
        ->and($product->packs->first()->label)->toBe('Blister')
        ->and($product->packs->first()->pieces)->toBe(10)
        // And the till reads it exactly as it did before the change.
        ->and(array_values($product->sellingUnitOptions()))
        ->toBe(['Box (20 Tab)', 'Blister (10 Tab)', 'Tab']);
});

test('a product that never had blisters gains no tier', function () use ($migration) {
    $migration()->down();

    DB::table('products')->insert([
        'sku' => 'LEGACY-2',
        'name' => 'Digital Thermometer',
        'unit' => 'Piece',
        'pieces_per_box' => 1,
        'pieces_per_pack' => null,
        'cost_price' => 165,
        'selling_price' => 245,
        'minimum_stock' => 5,
        'is_batch_tracked' => 0,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration()->up();

    expect(ProductPack::count())->toBe(0);
});

test('a blister recorded as the whole box is not carried over as a tier', function () use ($migration) {
    $migration()->down();

    // Some rows were saved with the blister equal to the box, which was
    // always a no-op; it must not become a duplicate button on the till.
    DB::table('products')->insert([
        'sku' => 'LEGACY-3',
        'name' => 'Odd Entry',
        'unit' => 'Box',
        'piece_unit' => 'Tab',
        'pieces_per_box' => 20,
        'pieces_per_pack' => 20,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 5,
        'is_batch_tracked' => 0,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration()->up();

    expect(ProductPack::count())->toBe(0);
});

test('rolling back writes the largest tier back into the old column', function () use ($migration) {
    $product = Product::create([
        'sku' => 'DIAP-R',
        'name' => 'Diapers',
        'unit' => 'Case',
        'piece_unit' => 'pc',
        'pieces_per_box' => 48,
        'cost_price' => 400,
        'selling_price' => 600,
        'is_batch_tracked' => false,
    ]);

    $product->packs()->createMany([
        ['label' => 'Pack of 6', 'pieces' => 6],
        ['label' => 'Pack of 12', 'pieces' => 12],
    ]);

    $migration()->down();

    expect(DB::table('products')->where('id', $product->id)->value('pieces_per_pack'))->toBe(12);

    // Leave the schema as the suite found it.
    $migration()->up();
});
