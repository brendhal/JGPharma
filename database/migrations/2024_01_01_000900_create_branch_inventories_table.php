<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Running on-hand total per product per branch. Kept in sync with
     * product_batches by InventoryService so the POS never has to sum
     * batches at read time.
     */
    public function up(): void
    {
        Schema::create('branch_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            // Overrides products.minimum_stock for this branch when set.
            $table->unsignedInteger('minimum_stock')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index('quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_inventories');
    }
};
