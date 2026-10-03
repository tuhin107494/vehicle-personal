<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->unsignedInteger('default_cc')->nullable()->after('default_color');
        });

        DB::table('vehicle_categories')->where('key', 'cng')->update(['default_cc' => 200]);
    }

    public function down(): void
    {
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->dropColumn('default_cc');
        });
    }
};
