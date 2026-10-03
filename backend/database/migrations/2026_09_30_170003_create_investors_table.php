<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('alt_phone')->nullable();
            $table->string('nid')->nullable();
            $table->text('address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_no', 50)->nullable();
            $table->text('bank_info')->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            // the investor's saved profit formula: what was invested, the profit it earned, over how long, and the rates that follow
            $table->decimal('formula_invested', 14, 2)->nullable();
            $table->decimal('formula_profit', 14, 2)->nullable();
            $table->unsignedSmallInteger('formula_months')->nullable();
            $table->decimal('formula_rate_month', 10, 4)->nullable();
            $table->decimal('formula_rate_year', 10, 4)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
