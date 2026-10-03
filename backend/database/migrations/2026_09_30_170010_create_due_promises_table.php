<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('due_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->constrained();
            $table->date('promised_date');
            $table->decimal('promised_amount', 14, 2);
            $table->enum('status', ['open', 'kept', 'broken', 'cancelled'])->default('open');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['promised_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('due_promises');
    }
};
