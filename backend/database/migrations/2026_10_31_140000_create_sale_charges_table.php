<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Extra money a customer owes on a sale (a late fee, documents...): it adds to the sale's due and is collected like the rest. */
    public function up(): void
    {
        Schema::create('sale_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->constrained();
            $table->string('purpose', 100);
            $table->decimal('amount', 14, 2);
            $table->date('charged_on');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('charges_total', 14, 2)->default(0)->after('down_payment');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('sale_charge_id')->nullable()->after('installment_id')->constrained('sale_charges');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_charge_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('charges_total');
        });

        Schema::dropIfExists('sale_charges');
    }
};
