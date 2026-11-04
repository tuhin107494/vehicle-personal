<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The shop's own memo number for a sale, and the guarantor's name (both optional). */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('memo_no', 100)->nullable()->after('guarantor_phone');
            $table->string('guarantor_name', 100)->nullable()->after('guarantor_phone');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['memo_no', 'guarantor_name']);
        });
    }
};
