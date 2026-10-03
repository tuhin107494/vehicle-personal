<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Where a vehicle was bought: from a supplier, or second hand from a customer. */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->enum('source', ['supplier', 'customer'])->default('supplier')->after('party_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
