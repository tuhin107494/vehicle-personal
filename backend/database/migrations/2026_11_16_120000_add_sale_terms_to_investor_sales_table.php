<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An investor sale is entered like a sale: its own price and down payment (the investor funds the rest), how the down payment
 * was paid, a memo number and a guarantor. Older investor sales had only the amount, so the new columns are nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_sales', function (Blueprint $table) {
            $table->decimal('sale_price', 14, 2)->nullable()->after('date');
            $table->decimal('down_payment', 14, 2)->nullable()->after('sale_price');
            $table->enum('method', ['cash', 'bank', 'mobile'])->nullable()->after('down_payment');
            $table->string('memo_no', 100)->nullable()->after('method');
            $table->string('guarantor_name', 100)->nullable()->after('memo_no');
            $table->string('guarantor_phone', 20)->nullable()->after('guarantor_name');
        });
    }

    public function down(): void
    {
        Schema::table('investor_sales', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'down_payment', 'method', 'memo_no', 'guarantor_name', 'guarantor_phone']);
        });
    }
};
