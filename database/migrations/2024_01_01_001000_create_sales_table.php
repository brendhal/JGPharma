<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('invoice_number', 40)->unique();
            $table->dateTime('sale_date');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);
            // CASH | CARD | GCASH
            $table->string('payment_method', 20)->default('CASH');
            // COMPLETED | HELD | VOIDED | REFUNDED
            $table->string('status', 20)->default('COMPLETED');
            $table->string('customer_name')->nullable();
            // NONE | SENIOR | PWD
            $table->string('discount_type', 20)->default('NONE');
            $table->string('discount_id_number', 60)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'sale_date']);
            $table->index(['status', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
