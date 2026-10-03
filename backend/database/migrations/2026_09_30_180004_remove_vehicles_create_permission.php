<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Vehicles are now added only through purchases, so this permission no longer exists. */
    public function up(): void
    {
        DB::table('permissions')->where('key', 'vehicles.create')->delete();
    }

    public function down(): void
    {
        //
    }
};
