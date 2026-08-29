<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which unit a line was actually rung up in.
 *
 * `quantity` stays the number of things the customer asked for, so quantity x
 * unit_price still equals the subtotal and no historical receipt changes. The
 * multiplier records how many pieces each of those things was worth, which is
 * what came off the shelf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('unit_label', 30)->nullable()->after('quantity');
            $table->unsignedInteger('unit_multiplier')->default(1)->after('unit_label');
        });

        // Everything sold before this point was sold as a whole selling unit.
        DB::table('products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                DB::table('sale_items')
                    ->where('product_id', $product->id)
                    ->update([
                        'unit_label' => $product->unit,
                        'unit_multiplier' => (int) $product->pieces_per_box,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['unit_label', 'unit_multiplier']);
        });
    }
};
