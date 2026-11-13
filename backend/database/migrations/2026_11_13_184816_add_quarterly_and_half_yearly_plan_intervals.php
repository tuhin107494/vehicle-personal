<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans may now run for 3 months (quarterly) or 6 months (half_yearly) as well as monthly and yearly.
 * Only the allowed values widen: every existing plan keeps its interval, so this is safe on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->enum('billing_interval', ['monthly', 'quarterly', 'half_yearly', 'yearly'])->default('monthly')->change();
        });
    }

    public function down(): void
    {
        // narrowing the list would break any plan already on a new interval: refuse rather than change its price period
        if (DB::table('plans')->whereIn('billing_interval', ['quarterly', 'half_yearly'])->exists()) {
            throw new RuntimeException('Some plans use a 3-month or 6-month interval. Change them to monthly or yearly before rolling back.');
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->enum('billing_interval', ['monthly', 'yearly'])->default('monthly')->change();
        });
    }
};
