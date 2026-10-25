<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shop that signed itself up waits (approved_at empty) until the super admin switches it on.
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('is_active');
        });

        // every shop made so far was made by the super admin
        DB::table('shops')->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
