<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->decimal('investor_share_percent', 8, 4)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->decimal('investor_share_percent', 5, 2)->default(0)->change();
        });
    }
};
