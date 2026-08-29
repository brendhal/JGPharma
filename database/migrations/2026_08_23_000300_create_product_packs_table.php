<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let one product carry several in-between pack sizes.
 *
 * A box of Neozep breaks into blisters of ten and nothing else, so a single
 * `pieces_per_pack` column was answer enough. Diapers are not so tidy: the
 * same item sells loose, in a pack of six, and in a pack of twelve, all drawn
 * from one shelf count. A column cannot hold three answers, so the tiers move
 * to their own table and the column retires.
 *
 * No stock moves here. Every quantity in the system stays counted in pieces;
 * these rows only describe how many pieces a customer walks out with when
 * they ask for "a pack".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // What the counter calls this tier: Blister, Pack of 6, Bundle.
            $table->string('label', 40);
            // Pieces a customer gets when they buy one of these.
            $table->unsignedInteger('pieces');
            $table->timestamps();

            // The same size twice would give the cashier two identical buttons.
            $table->unique(['product_id', 'pieces']);
        });

        $this->carryOverBlisters();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pieces_per_pack');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('pieces_per_pack')->nullable()->after('pieces_per_box');
        });

        // Only one tier survives the trip back. Ascending order means the
        // largest is written last and wins, which is the closest match to the
        // blister the old column described.
        foreach (DB::table('product_packs')->orderBy('pieces')->get() as $pack) {
            DB::table('products')
                ->where('id', $pack->product_id)
                ->update(['pieces_per_pack' => $pack->pieces]);
        }

        Schema::dropIfExists('product_packs');
    }

    /** Every product that already broke into blisters keeps doing so. */
    private function carryOverBlisters(): void
    {
        $now = now();

        DB::table('products')
            ->whereNotNull('pieces_per_pack')
            ->where('pieces_per_pack', '>', 1)
            ->whereColumn('pieces_per_pack', '<', 'pieces_per_box')
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($now) {
                DB::table('product_packs')->insert(
                    collect($products)->map(fn ($product) => [
                        'product_id' => $product->id,
                        'label' => 'Blister',
                        'pieces' => (int) $product->pieces_per_pack,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }
};
