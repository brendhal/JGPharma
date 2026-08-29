<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('sku', 60)->unique();
            $table->string('barcode', 60)->nullable()->unique();
            $table->string('name');
            // Selling unit: Box (20 Tab), Bottle, Piece, Sachet, Vial ...
            $table->string('unit', 50)->default('Piece');
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->unsignedInteger('minimum_stock')->default(10);
            // Batch/expiry tracking is only meaningful for medicines and
            // perishables; plain merchandise can opt out.
            $table->boolean('is_batch_tracked')->default(true);
            $table->boolean('is_vat_exempt')->default(false);
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['name', 'is_active']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
