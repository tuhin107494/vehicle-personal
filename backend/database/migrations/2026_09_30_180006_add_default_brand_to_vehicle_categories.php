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
            $table->string('default_brand')->nullable()->after('name');
        });

        DB::table('vehicle_categories')->where('key', 'cng')->update(['default_brand' => 'Bajaj']);
    }

    public function down(): void
    {
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->dropColumn('default_brand');
        });
    }
};
