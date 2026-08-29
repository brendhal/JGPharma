<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The SALES_LADY and CASHIER roles did the same job, so they were collapsed
 * into a single CASHIER role. This moves any account still on the old value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'SALES_LADY')
            ->update(['role' => 'CASHIER']);
    }

    public function down(): void
    {
        // One-way: the original split cannot be recovered from the merged data.
    }
};
