<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When a shop's free trial ends. Empty means the shop has no time limit (every existing shop, and shops the super admin creates). */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
