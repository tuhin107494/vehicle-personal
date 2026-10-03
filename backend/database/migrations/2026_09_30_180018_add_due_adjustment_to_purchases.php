<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buying a vehicle back from a customer who still owes us: part or all of their due can be set off against
     * the purchase price. The set-off is recorded as a payment on their sale with the method "adjustment".
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('due_adjustment', 14, 2)->default(0)->after('paid_amount');
            $table->foreignId('adjusted_sale_id')->nullable()->after('due_adjustment')->constrained('sales')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'bank', 'mobile', 'adjustment'])->default('cash')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'bank', 'mobile'])->default('cash')->change();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_sale_id');
            $table->dropColumn('due_adjustment');
        });
    }
};
