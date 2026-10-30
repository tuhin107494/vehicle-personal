<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The shop's own memo number for a due collection (optional). */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('memo_no', 100)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('memo_no');
        });
    }
};
