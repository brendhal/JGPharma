<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A medicine is a one-to-one extension of a product. Only products that
     * are drugs get a row here; general merchandise (shampoo, batteries,
     * candy) simply has none.
     */
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('generic_name');
            $table->string('strength', 100)->nullable();
            // Tablet, Capsule, Syrup, Suspension, Inhaler, Ointment ...
            $table->string('dosage_form', 100)->nullable();
            $table->boolean('requires_prescription')->default(false);
            $table->string('manufacturer')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->timestamps();

            $table->index('generic_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
