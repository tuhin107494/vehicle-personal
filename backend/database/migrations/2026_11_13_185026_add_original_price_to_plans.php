<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's regular price before its discount, shown struck through beside the price. Display only: shops are still
 * charged price_paisa. NULL means the plan has no discount. Nullable, so existing plans are unchanged on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('original_price_paisa')->nullable()->after('price_paisa');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('original_price_paisa');
        });
    }
};
