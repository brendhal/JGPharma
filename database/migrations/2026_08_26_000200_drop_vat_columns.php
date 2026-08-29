<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT leaves the system.
 *
 * Shelf prices are what the customer pays; nothing is split out of them, so
 * there is no tax figure to keep per sale and no per-product exemption to
 * set. Past sales keep their totals untouched — only the breakdown goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('vat_amount');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_vat_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('vat_amount', 14, 2)->default(0)->after('discount');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_vat_exempt')->default(false);
        });
    }
};
