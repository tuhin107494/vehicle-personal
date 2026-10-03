<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->enum('type', ['installment_overdue', 'installment_upcoming', 'promise_broken', 'promise_upcoming']);
            $table->foreignId('sale_id')->constrained();
            $table->unsignedBigInteger('related_id');
            $table->date('due_date');
            $table->string('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'related_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
