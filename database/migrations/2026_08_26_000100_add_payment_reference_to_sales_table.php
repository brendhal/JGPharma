<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the e-wallet / card reference number lives.
 *
 * A GCash or card sale leaves no money in the drawer, so the receipt has to
 * carry the transaction's own number for the day's takings to be reconciled
 * against the wallet statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_reference', 40)->nullable()->after('payment_method');
            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['payment_reference']);
            $table->dropColumn('payment_reference');
        });
    }
};
