<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Investors and suppliers get a father's name, as customers have. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['investors', 'suppliers'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('father_name')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        foreach (['investors', 'suppliers'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('father_name');
            });
        }
    }
};
