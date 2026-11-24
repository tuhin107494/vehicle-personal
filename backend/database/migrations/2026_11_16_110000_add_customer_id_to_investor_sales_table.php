<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An investor sale can name one more customer besides the sale's buyer (a co-buyer or guarantor). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('investor_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('investor_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
