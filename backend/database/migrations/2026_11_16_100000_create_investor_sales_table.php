<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An investor sale: the investor funds a customer sale on their own terms (amount, profit rate, schedule),
 * apart from what the customer pays the shop. What the shop pays the investor is recorded as investor settlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('investor_id')->constrained();
            $table->date('date');
            $table->decimal('principal', 14, 2);
            $table->decimal('profit_rate', 6, 2)->default(0);
            $table->decimal('profit_amount', 14, 2)->default(0);
            $table->decimal('total_payable', 14, 2);
            $table->unsignedSmallInteger('number_of_installments');
            $table->enum('frequency', ['monthly', 'weekly'])->default('monthly');
            $table->date('start_date');
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['sale_id', 'status']);
            $table->index(['investor_id', 'status']);
        });

        Schema::table('investor_settlements', function (Blueprint $table) {
            $table->foreignId('investor_sale_id')->nullable()->after('plan_id')->constrained('investor_sales');
        });
    }

    public function down(): void
    {
        Schema::table('investor_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investor_sale_id');
        });

        Schema::dropIfExists('investor_sales');
    }
};
