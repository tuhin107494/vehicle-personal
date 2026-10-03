<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The memo (invoice) number the seller gave for this purchase; unique per seller within a shop. */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('memo_no', 100)->nullable()->after('source');
            $table->unique(['shop_id', 'party_id', 'memo_no']);
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'party_id', 'memo_no']);
            $table->dropColumn('memo_no');
        });
    }
};
