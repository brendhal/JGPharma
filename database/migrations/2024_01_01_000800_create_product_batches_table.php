<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per physical lot of a product sitting in a branch. This is what
     * makes FEFO (first-expiring, first-out) selling and expiry alerts
     * possible.
     */
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('batch_number', 60);
            $table->date('expiration_date')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->date('received_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'batch_number'], 'batches_branch_product_batch_unique');
            $table->index(['product_id', 'expiration_date']);
            $table->index('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
