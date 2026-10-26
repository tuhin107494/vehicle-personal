<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a "call done" mark on a due-alert row; the row comes back after the shop's recall days
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('kind', ['installment', 'promise']);
            $table->unsignedBigInteger('ref_id'); // the installment or promise the call was about
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('called_at');
            $table->timestamps();
            $table->index(['shop_id', 'kind', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
