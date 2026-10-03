<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('installment_id')->nullable()->constrained();
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash', 'bank', 'mobile'])->default('cash');
            $table->timestamp('paid_at');
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
