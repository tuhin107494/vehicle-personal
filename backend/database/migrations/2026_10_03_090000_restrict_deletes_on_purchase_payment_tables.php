<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Money records must never vanish because a parent row was deleted: refuse the delete instead of cascading. */
    public function up(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
            $table->foreign('purchase_id')->references('id')->on('purchases')->restrictOnDelete();
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
            $table->foreign('party_id')->references('id')->on('parties')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
            $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
            $table->foreign('party_id')->references('id')->on('parties')->cascadeOnDelete();
        });
    }
};
