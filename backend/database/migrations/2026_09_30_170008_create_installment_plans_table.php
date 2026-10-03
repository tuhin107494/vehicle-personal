<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('investor_id')->nullable()->constrained();
            $table->decimal('principal', 14, 2);
            $table->decimal('interest_rate', 6, 2)->default(0);
            $table->decimal('interest_amount', 14, 2)->default(0);
            $table->decimal('total_payable', 14, 2);
            $table->unsignedSmallInteger('number_of_installments');
            $table->enum('frequency', ['monthly', 'weekly'])->default('monthly');
            $table->date('start_date');
            $table->decimal('investor_share_percent', 8, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
