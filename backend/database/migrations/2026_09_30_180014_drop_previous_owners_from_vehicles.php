<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shop_settings')->where('key', 'like', 'typedef.previous_owners:%')->delete();

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('previous_owners');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('previous_owners')->nullable();
        });
    }
};
