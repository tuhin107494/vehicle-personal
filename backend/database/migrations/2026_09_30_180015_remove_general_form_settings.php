<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Shops now preset everything per vehicle type, so the old shop-wide form defaults are gone. */
    public function up(): void
    {
        DB::table('shop_settings')
            ->where('key', 'not like', 'purchase.price:%')
            ->where('key', 'not like', 'sale.price:%')
            ->where('key', 'not like', 'typedef.%')
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
