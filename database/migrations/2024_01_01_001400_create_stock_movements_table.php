<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit log. Every change to stock — a sale, a delivery, a
     * disposal, an audit correction, a transfer leg — writes exactly one row
     * here, signed (+in / -out).
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // PURCHASE | SALE | RETURN | EXPIRED | DAMAGED | ADJUSTMENT
            // | TRANSFER_IN | TRANSFER_OUT
            $table->string('type', 20);
            // Signed: positive adds stock, negative removes it.
            $table->integer('quantity');
            $table->integer('balance_after')->nullable();
            // Polymorphic-ish pointer back to the document that caused this.
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['branch_id', 'product_id']);
            $table->index(['type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
