<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money paid to a supplier (or a customer we bought back from) after the purchase was recorded.
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 20)->default('cash'); // cash, bank, mobile, advance
            $table->date('paid_at');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shop_id', 'purchase_id']);
        });

        // Money paid to a supplier before there is a purchase to put it against; `used` is how much has been applied since.
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('used', 14, 2)->default(0);
            $table->string('method', 20)->default('cash');
            $table->date('paid_at');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shop_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advances');
        Schema::dropIfExists('purchase_payments');
    }
};
