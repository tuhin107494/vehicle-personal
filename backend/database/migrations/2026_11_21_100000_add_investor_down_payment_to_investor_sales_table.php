<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The investor's amount on an investor sale (its principal, which the shop pays back with interest) need not all be given at once:
 * the investor gives a down payment now, and the rest (their due to the shop) later. Empty: all of it given at once, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_sales', function (Blueprint $table) {
            $table->decimal('investor_down_payment', 14, 2)->nullable()->after('down_payment');
        });
    }

    public function down(): void
    {
        Schema::table('investor_sales', function (Blueprint $table) {
            $table->dropColumn('investor_down_payment');
        });
    }
};
