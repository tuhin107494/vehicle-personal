<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The investor's saved profit formula: what was invested, the profit it earned, over how long, and the two rates that follow. */
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->decimal('formula_invested', 14, 2)->nullable();
            $table->decimal('formula_profit', 14, 2)->nullable();
            $table->unsignedSmallInteger('formula_months')->nullable();
            $table->decimal('formula_rate_month', 10, 4)->nullable();
            $table->decimal('formula_rate_year', 10, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn(['formula_invested', 'formula_profit', 'formula_months', 'formula_rate_month', 'formula_rate_year']);
        });
    }
};
