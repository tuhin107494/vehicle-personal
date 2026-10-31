<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every edit of a customer payment, supplier payment or supplier advance: who made it and what changed. */
    public function up(): void
    {
        Schema::create('transaction_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->string('type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->constrained();
            $table->json('changes');
            $table->timestamps();

            $table->index(['type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_edits');
    }
};
