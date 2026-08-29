<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teach the catalog how a pack comes apart.
 *
 * Until now `unit` carried the pack size as prose — "Box (20 Tab)" — which
 * reads well and computes not at all, so a counter could only ever sell whole
 * boxes. This splits that into numbers the system can multiply by, and moves
 * every stock figure onto the smallest unit (the piece), which is the only way
 * selling three tablets can take three tablets off a box of twenty.
 *
 * The conversions run product by product rather than as UPDATE..JOIN, because
 * SQLite cannot reach another table from a SET clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // What one piece is called: Tab, Cap, pcs, ml.
            $table->string('piece_unit', 30)->nullable()->after('unit');
            // Pieces in one selling unit. 1 means the product is indivisible.
            $table->unsignedInteger('pieces_per_box')->default(1)->after('piece_unit');
            // Pieces in one blister, when the box breaks down that far.
            $table->unsignedInteger('pieces_per_pack')->nullable()->after('pieces_per_box');
        });

        $this->splitUnitStrings();
        $this->rescaleStock(fn (int $factor): string => "* {$factor}");

        DB::table('products')->update([
            'minimum_stock' => DB::raw('minimum_stock * pieces_per_box'),
        ]);
    }

    public function down(): void
    {
        DB::table('products')->where('pieces_per_box', '>', 0)->update([
            'minimum_stock' => DB::raw('minimum_stock / pieces_per_box'),
        ]);

        $this->rescaleStock(fn (int $factor): string => "/ {$factor}");

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['piece_unit', 'pieces_per_box', 'pieces_per_pack']);
        });
    }

    /**
     * "Box (20 Tab)" becomes unit "Box", 20 pieces, piece unit "Tab".
     * Anything without a count in brackets is simply indivisible.
     */
    private function splitUnitStrings(): void
    {
        DB::table('products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                if (! preg_match('/^\s*(.+?)\s*\(\s*(\d+)\s*([^)]+?)\s*\)\s*$/', (string) $product->unit, $matches)) {
                    continue;
                }

                [, $unit, $pieces, $pieceUnit] = $matches;

                if ((int) $pieces < 2) {
                    continue;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'unit' => $unit,
                    'piece_unit' => $pieceUnit,
                    'pieces_per_box' => (int) $pieces,
                ]);
            }
        });
    }

    /**
     * Move every on-hand figure between boxes and pieces. Reorder levels travel
     * with them, otherwise a box-sized threshold reads as almost empty.
     *
     * @param  callable(int): string  $operation  e.g. fn ($factor) => "* {$factor}"
     */
    private function rescaleStock(callable $operation): void
    {
        DB::table('products')
            ->where('pieces_per_box', '>', 1)
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($operation) {
                foreach ($products as $product) {
                    $maths = $operation((int) $product->pieces_per_box);

                    DB::table('branch_inventories')
                        ->where('product_id', $product->id)
                        ->update(['quantity' => DB::raw("quantity {$maths}")]);

                    DB::table('branch_inventories')
                        ->where('product_id', $product->id)
                        ->whereNotNull('minimum_stock')
                        ->update(['minimum_stock' => DB::raw("minimum_stock {$maths}")]);

                    DB::table('product_batches')
                        ->where('product_id', $product->id)
                        ->update(['quantity' => DB::raw("quantity {$maths}")]);

                    DB::table('stock_movements')
                        ->where('product_id', $product->id)
                        ->update([
                            'quantity' => DB::raw("quantity {$maths}"),
                            'balance_after' => DB::raw("balance_after {$maths}"),
                        ]);
                }
            });
    }
};
