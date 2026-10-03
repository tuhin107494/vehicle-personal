<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('investor_id')->constrained();
            $table->foreignId('plan_id')->nullable()->constrained('installment_plans');
            $table->enum('type', ['investment', 'principal_return', 'profit_share', 'withdrawal']);
            $table->decimal('amount', 14, 2);
            $table->date('date');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_settlements');
    }
};
