<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Father's name is recorded for customers only; suppliers never have one. */
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('father_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('father_name');
        });
    }
};
