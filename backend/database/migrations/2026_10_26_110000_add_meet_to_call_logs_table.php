<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // what the customer said on the call: the day they will meet the shop, and a note
        Schema::table('call_logs', function (Blueprint $table) {
            $table->date('meet_date')->nullable()->after('called_at');
            $table->string('note', 500)->nullable()->after('meet_date');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['meet_date', 'note']);
        });
    }
};
