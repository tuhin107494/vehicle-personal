<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A sale draft is kept for a fixed number of days (the shop's booking days) and then deleted by itself, freeing the vehicle it holds. */
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->after('data');
        });

        // drafts saved before this get the usual week from today
        DB::table('drafts')->where('type', 'sale')->update(['expires_on' => now()->addDays(7)->toDateString()]);
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('expires_on');
        });
    }
};
